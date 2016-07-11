<?php

namespace Drupal\entity_usage;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class EntityUpdateManager.
 *
 * @package Drupal\entity_update
 */
class EntityUpdateManager {

  /**
   * Our usage tracking service.
   *
   * @var \Drupal\entity_usage\EntityUsage $usage_service
   */
  protected $usageService;

  /**
   * Entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   */
  protected $logger;

  /**
   * The EntityTypeManager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The EntityRepository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The ModuleHandler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * EntityUpdateManager constructor.
   *
   * @param \Drupal\entity_usage\EntityUsage $usage_service
   *   The EntityUsage service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The EntityFieldManager service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The Logger service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The EntityRepositoryInterface service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The ModuleHandler service.
   */
  public function __construct(
      EntityUsage $usage_service,
      EntityFieldManagerInterface $entity_field_manager,
      LoggerChannelFactoryInterface $logger,
      EntityTypeManagerInterface $entity_type_manager,
      EntityRepositoryInterface $entity_repository,
      ModuleHandlerInterface $module_handler
  ) {
    $this->usageService = $usage_service;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRepository = $entity_repository;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Track updates on creation of potential host entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity we are dealing with.
   */
  public function trackUpdateOnCreation(EntityInterface $entity) {

    // Only act on content entities.
    if (!($entity instanceof ContentEntityInterface)) {
      return;
    }

    // Track entities referenced in entity_reference fields.
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // This item got added. Track the usage up.
          $this->incrementEntityReferenceUsage($entity, $field_name, $field_item);
        }
      }
    }

    // Track entities embedded in text fields.
    $referenced_entities_by_field = $this->getEmbeddedEntitiesByField($entity);
    foreach ($referenced_entities_by_field as $field => $embedded_entities) {
      foreach ($embedded_entities as $uuid => $type) {
        // Increment the usage as embedded entity.
        $this->incrementEmbeddedUsage($entity, $type, $uuid);
      }
    }

  }

  /**
   * Track updates on deletion of potential host entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity we are dealing with.
   */
  public function trackUpdateOnDeletion(EntityInterface $entity) {

    // Only act on content entities.
    if (!($entity instanceof ContentEntityInterface)) {
      return;
    }

    // Track entities referenced in entity_reference fields.
    foreach ($this->entityReferenceFieldsAvailable($entity) as $field_name) {
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // This item got deleted. Track the usage down.
          $this->decrementEntityReferenceUsage($entity, $field_name, $field_item);
        }
      }
    }

    // Track entities embedded in text fields.
    $referenced_entities_by_field = $this->getEmbeddedEntitiesByField($entity);
    foreach ($referenced_entities_by_field as $field => $embedded_entities) {
      foreach ($embedded_entities as $uuid => $type) {
        // Decrement the usage as embedded entity.
        $this->decrementEmbeddedUsage($entity, $type, $uuid);
      }
    }

    // Now clean the possible usage of the entity that was deleted when target.
    $this->usageService->delete($entity->id(), $entity->getEntityTypeId());

  }

  /**
   * Track updates on edit / update of potential host entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity we are dealing with.
   */
  public function trackUpdateOnEdition(EntityInterface $entity) {

    // Only act on content entities.
    if (!($entity instanceof ContentEntityInterface)) {
      return;
    }

    // Track entities referenced in entity_reference fields.
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

    // Track entities embedded in text fields.
    $referenced_entities_new = $this->getEmbeddedEntitiesByField($entity, TRUE);
    $referenced_entities_original = $this->getEmbeddedEntitiesByField($entity->original, TRUE);
    foreach (array_diff_key($referenced_entities_new, $referenced_entities_original) as $uuid => $type) {
      // These entities were added.
      $this->incrementEmbeddedUsage($entity, $type, $uuid);
    }
    foreach (array_diff_key($referenced_entities_original, $referenced_entities_new) as $uuid => $type) {
      // These entities were removed.
      $this->decrementEmbeddedUsage($entity, $type, $uuid);
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
    $entityref_fields_on_this_entity_type = $this->entityFieldManager->getFieldMapByFieldType('entity_reference')[$entity->getEntityTypeId()];
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
    $this->usageService->add($field_item->target_id, $referenced_entity_type, $entity->id(), $entity->getEntityTypeId());
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

  /**
   * Helper method to increment the usage for embedded entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $t_type
   *   The type of the target entity.
   * @param string $uuid
   *   The UUID of the target entity.
   */
  private function incrementEmbeddedUsage(EntityInterface $entity, $t_type, $uuid) {
    $target_entity = $this->entityRepository->loadEntityByUuid($t_type, $uuid);
    $this->usageService->add($target_entity->id(), $t_type, $entity->id(), $entity->getEntityTypeId(), 'embed');
  }

  /**
   * Helper method to decrement the usage for embedded entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $t_type
   *   The type of the target entity.
   * @param string $uuid
   *   The UUID of the target entity.
   */
  private function decrementEmbeddedUsage(EntityInterface $entity, $t_type, $uuid) {
    $target_entity = $this->entityRepository->loadEntityByUuid($t_type, $uuid);
    $this->usageService->delete($target_entity->id(), $t_type, $entity->id(), $entity->getEntityTypeId());
  }

  /**
   * Finds all entities embedded (<drupal-entity>) by formatted text fields.
   *
   * @param EntityInterface $entity
   *   An entity object whose fields to analyze.
   * @param bool $omit_field_names
   *   (Optional) Whether the field names should be omitted from the results.
   *   Defaults to FALSE.
   *
   * @return array
   *   An array of found embedded entities, in the following structure:
   *   [
   *     'field_name' => [
   *       'uuid1' => 'entity_type1',
   *       'uuid2' => 'entity_type1',
   *       'uuid3' => 'entity_type2',
   *        etc.
   *     ],
   *   ]
   *   If the $omit_field_names flag is TRUE, the first level is not present,
   *   and the result array is directly an associative array of uuids as keys
   *   and entity_types as values.
   */
  private function getEmbeddedEntitiesByField(EntityInterface $entity, $omit_field_names = FALSE) {
    $entities = [];

    if ($this->moduleHandler->moduleExists('editor')) {
      $formatted_text_fields = _editor_get_formatted_text_fields($entity);
      foreach ($formatted_text_fields as $formatted_text_field) {
        $text = '';
        $field_items = $entity->get($formatted_text_field);
        foreach ($field_items as $field_item) {
          $text .= $field_item->value;
          if ($field_item->getFieldDefinition()->getType() == 'text_with_summary') {
            $text .= $field_item->summary;
          }
        }
        if ($omit_field_names) {
          $entities += $this->parseEntityUuids($text);
        }
        else {
          $entities[$formatted_text_field] = $this->parseEntityUuids($text);
        }
      }
    }

    return $entities;
  }

  /**
   * Parse an HTML snippet for any embedded entity with a <drupal-entity> tag.
   *
   * @param string $text
   *   The partial (X)HTML snippet to load. Invalid markup will be corrected on
   *   import.
   *
   * @return array
   *   An array of all embedded entities found, where keys are the uuids and the
   *   values are the entity types.
   */
  private function parseEntityUuids($text) {
    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $entities = [];
    foreach ($xpath->query('//drupal-entity[@data-entity-type and @data-entity-uuid]') as $node) {
      // Note that this does not cover 100% of the situations. In the (unlikely
      // but possible) use case where the user embeds the same entity twice in
      // the same field, we are just recording 1 usage for this target entity,
      // when we should record 2. The alternative is to add a lot of complexity
      // to the update logic of our service, to deal with all possible
      // combinations in the update scenario.
      // @TODO Re-evaluate if this is worth the effort and overhead.
      $entities[$node->getAttribute('data-entity-uuid')] = $node->getAttribute('data-entity-type');
    }
    return $entities;
  }

}
