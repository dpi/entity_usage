<?php

namespace Drupal\entity_usage\Plugin\EntityUsage\Track;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\entity_usage\EntityUsage;
use Drupal\entity_usage\EntityUsageTrackBase;
use Drupal\entity_usage\EntityUsageTrackInterface;
use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tracks usage of entities related in entity_reference fields.
 *
 * @EntityUsageTrack(
 *   id = "entity_reference",
 *   label = @Translation("Entity Reference Fields"),
 *   description = @Translation("Tracks usage of entities related in entity_reference fields."),
 * )
 */
class EntityReference extends EntityUsageTrackBase implements EntityUsageTrackInterface {

  /**
   * Entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs display plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\entity_usage\EntityUsage $usage_service
   *   The usage tracking service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The EntityFieldManager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityUsage $usage_service,
    EntityFieldManagerInterface $entity_field_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $usage_service);
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_usage.usage'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityCreation(EntityInterface $entity) {
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // This item got added. Track the usage up.
          $this->incrementEntityReferenceUsage($entity, $field_name, $field_item);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityUpdate(EntityInterface $entity) {
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      // Original entity had some values on the field.
      if (!$entity->original->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->original->$field_name as $field_item) {
          // Check if this item is still present on the updated entity.
          if (!$this->targetIdIsReferencedInEntity($entity, $field_item->target_id, $field_name)) {
            // This item got removed. Track the usage down.
            $this->decrementEntityReferenceUsage($entity, $field_name, $field_item);
          }
        }
      }
      // Current entity has some values on the field.
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // Check if this item was present on the original entity.
          if (!$this->targetIdIsReferencedInEntity($entity->original, $field_item->target_id, $field_name)) {
            // This item got added. Track the usage up.
            $this->incrementEntityReferenceUsage($entity, $field_name, $field_item);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityDeletion(EntityInterface $entity) {
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // This item got deleted. Track the usage down.
          $this->decrementEntityReferenceUsage($entity, $field_name, $field_item);
        }
      }
    }
  }

  /**
   * Retrieve the entity_reference fields on a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return array
   *   An array of field_names that could reference to other entities.
   */
  private function entityReferenceFieldsAvailable(EntityInterface $entity) {
    $return_fields = [];
    $fields_on_entity = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());
    $entityref_fields_on_this_entity_type = [];
    if (!empty($this->entityFieldManager->getFieldMapByFieldType('entity_reference')[$entity->getEntityTypeId()])) {
      $entityref_fields_on_this_entity_type = $this->entityFieldManager->getFieldMapByFieldType('entity_reference')[$entity->getEntityTypeId()];
    }
    $entityref_on_this_bundle = array_intersect_key($fields_on_entity, $entityref_fields_on_this_entity_type);
    // Clean out basefields.
    $basefields = $this->entityFieldManager->getBaseFieldDefinitions($entity->getEntityTypeId());
    $entityref_on_this_bundle = array_diff_key($entityref_on_this_bundle, $basefields);
    if (!empty($entityref_on_this_bundle)) {
      $return_fields = array_keys($entityref_on_this_bundle);
    }
    return $return_fields;
  }

  /**
   * Check the presence of target ids in an entity object, for a given field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host_entity
   *   The host entity object.
   * @param int $referenced_entity_id
   *   The referenced entity id.
   * @param string $field_name
   *   The field name where to check this information.
   *
   * @return TRUE if the $host_entity has the $referenced_entity_id "target_id"
   *   value in any delta of the $field_name, FALSE otherwise.
   */
  private function targetIdIsReferencedInEntity(EntityInterface $host_entity, $referenced_entity_id, $field_name) {
    if (!$host_entity->$field_name->isEmpty()) {
      foreach ($host_entity->get($field_name) as $field_delta) {
        if ($field_delta->target_id == $referenced_entity_id) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Helper method to increment the usage in entity_reference fields.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $field_name
   *   The name of the entity_reference field, present in $entity.
   * @param \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item
   *   The field item containing the values of the target entity.
   */
  private function incrementEntityReferenceUsage(EntityInterface $entity, $field_name, EntityReferenceItem $field_item) {
    /** @var \Drupal\field\Entity\FieldConfig $definition */
    $definition = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle())[$field_name];
    $referenced_entity_type = $definition->getSetting('target_type');
    $this->usageService->add($field_item->target_id, $referenced_entity_type, $entity->id(), $entity->getEntityTypeId(), $this->pluginId);
  }

  /**
   * Helper method to decrement the usage in entity_reference fields.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $field_name
   *   The name of the entity_reference field, present in $entity.
   * @param \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item
   *   The field item containing the values of the target entity.
   */
  private function decrementEntityReferenceUsage(EntityInterface $entity, $field_name, EntityReferenceItem $field_item) {
    /** @var \Drupal\field\Entity\FieldConfig $definition */
    $definition = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle())[$field_name];
    $referenced_entity_type = $definition->getSetting('target_type');
    $this->usageService->delete($field_item->target_id, $referenced_entity_type, $entity->id(), $entity->getEntityTypeId());
  }

}
