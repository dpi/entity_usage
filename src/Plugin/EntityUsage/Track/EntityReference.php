<?php

namespace Drupal\entity_usage\Plugin\EntityUsage\Track;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_usage\EntityUsage;
use Drupal\entity_usage\EntityUsageTrackBase;
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
class EntityReference extends EntityUsageTrackBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityUsage $usage_service, EntityFieldManagerInterface $entity_field_manager, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $usage_service, $entity_field_manager, $config_factory);
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityCreation(ContentEntityInterface $entity) {
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // This item got added. Track the usage up.
          $this->incrementEntityReferenceUsage($entity, $field_name, $field_item->target_id);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityUpdate(ContentEntityInterface $entity) {
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      $current_target_ids = [];
      if (!$entity->{$field_name}->isEmpty()) {
        foreach ($entity->{$field_name} as $field_item) {
          $current_target_ids[] = $field_item->target_id;
        }
      }

      $original_target_ids = [];
      if (!$entity->original->{$field_name}->isEmpty()) {
        foreach ($entity->original->{$field_name} as $field_item) {
          $original_target_ids[] = $field_item->target_id;
        }
      }

      // If a field references the same target entity, we record only one usage.
      $original_target_ids = array_unique($original_target_ids);
      $current_target_ids = array_unique($current_target_ids);

      $added_ids = array_diff($current_target_ids, $original_target_ids);
      $removed_ids = array_diff($original_target_ids, $current_target_ids);

      foreach ($added_ids as $id) {
        $this->incrementEntityReferenceUsage($entity, $field_name, $id);
      }
      foreach ($removed_ids as $id) {
        $this->decrementEntityReferenceUsage($entity, $field_name, $id);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityDeletion(ContentEntityInterface $entity) {
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      if (!$entity->{$field_name}->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->{$field_name} as $field_item) {
          $this->decrementEntityReferenceUsage($entity, $field_name, $field_item->target_id);
        }
      }
    }
  }

  /**
   * Retrieve the entity_reference fields on a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity object.
   *
   * @return array
   *   An array of field_names that could reference to other content entities.
   */
  protected function entityReferenceFieldsAvailable(ContentEntityInterface $entity) {
    /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields */
    $fields = $this->getReferencingFields($entity, ['entity_reference']);

    $return_fields = [];
    if (!empty($fields)) {
      // Make sure we only leave the fields that are referencing content
      // entities.
      foreach ($fields as $key => $entityref) {
        $target_type = $entityref->getItemDefinition()->getSettings()['target_type'];
        $entity_type = $this->entityTypeManager->getStorage($target_type)->getEntityType();
        if ($entity_type instanceof ConfigEntityTypeInterface) {
          unset($fields[$key]);
        }
      }
      $return_fields = array_keys($fields);
    }

    return $return_fields;
  }

  /**
   * Helper method to increment the usage in entity_reference fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity object.
   * @param string $field_name
   *   The name of the entity_reference field, present in $entity.
   * @param int $target_id
   *   The id of the target entity.
   */
  protected function incrementEntityReferenceUsage(ContentEntityInterface $source_entity, $field_name, $target_id) {
    /** @var \Drupal\field\Entity\FieldConfig $definition */
    $definition = $this->entityFieldManager->getFieldDefinitions($source_entity->getEntityTypeId(), $source_entity->bundle())[$field_name];
    $target_type = $definition->getSetting('target_type');
    $this->usageService->add($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $this->pluginId, $field_name);
  }

  /**
   * Helper method to decrement the usage in entity_reference fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity object.
   * @param string $field_name
   *   The name of the entity_reference field, present in $entity.
   * @param int $target_id
   *   The id of the target entity.
   */
  protected function decrementEntityReferenceUsage(ContentEntityInterface $source_entity, $field_name, $target_id) {
    /** @var \Drupal\field\Entity\FieldConfig $definition */
    $definition = $this->entityFieldManager->getFieldDefinitions($source_entity->getEntityTypeId(), $source_entity->bundle())[$field_name];
    $target_type = $definition->getSetting('target_type');
    $this->usageService->delete($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $this->pluginId, $field_name);
  }

}
