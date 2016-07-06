<?php

namespace Drupal\entity_usage;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class EntityUpdateManager.
 *
 * @package Drupal\entity_update
 */
class EntityUpdateManager {

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
   * Constructor method.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger service.
   */
  public function __construct(
    EntityFieldManagerInterface $entity_field_manager,
    LoggerChannelFactoryInterface $logger
  ) {
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
  }

  /**
   * Track updates on entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity we are dealing with.
   * @param string $operation
   *   The operation the entity is going through (insert, update or delete).
   */
  public function trackUpdate(EntityInterface $entity, $operation) {
//    $this->logger->get('entity_usage')->log('warning', 'Received entity: ' . $entity->label() . ' and operation: ' . $operation);

    // Only act on content entities.
    $is_content_entity = $entity->getEntityType() instanceof \Drupal\Core\Entity\ContentEntityType;
    if (!$is_content_entity) {
      return;
    }

    foreach ($this->isHostEntity($entity) as $field_name) {
      $foo = 'bar';

      switch ($operation) {
        case 'insert':
          break;
        case 'update':
          break;
        case 'delete':
          break;
      }

      // Original entity had some values on the field.
      if (!$entity->original->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->original->$field_name as $field_item) {
          // Check if this item is still present on the updated entity.
          if (!$this->targetIdIsReferencedInEntity($entity, $field_item->target_id, $field_name)) {
            // This item got removed. Track the usage down.
            // @TODO IMPLEMENT ME.
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
            // @TODO IMPLEMENT ME.
          }
        }
      }
    }

  }

  /**
   * Check if a given entity object is "pointing to" other entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *
   * @return bool|array
   *   If the received entity is potentially a "linker" to other entities, will
   *   return an array of field_names that could do this referencing. Will
   *   return FALSE if the entity cannot link to any other entity.
   */
  private function isHostEntity(EntityInterface $entity) {
    // For now only entityreference field types are supported as method for
    // "linking" entities.
    // @TODO Extend / refactor this if other methods are allowed in the future.

    $fields_on_entity = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());
    $entityref_fields_on_this_entity_type = $this->entityFieldManager->getFieldMapByFieldType('entity_reference')[$entity->getEntityTypeId()];
    $entityref_on_this_bundle = array_intersect_key($fields_on_entity, $entityref_fields_on_this_entity_type);
    // Clean out basefields.
    $basefields = $this->entityFieldManager->getBaseFieldDefinitions($entity->getEntityTypeId());
    $entityref_on_this_bundle = array_diff_key($entityref_on_this_bundle, $basefields);
    if (!empty($entityref_on_this_bundle)) {
      return array_keys($entityref_on_this_bundle);
    }
    return FALSE;
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   */
  private function needsUsageUpdate(EntityInterface $entity) {
    if (!empty($entityref_on_this_bundle)) {
      $field_names = [];
      foreach ($entityref_on_this_bundle as $fieldname => $field) {
        if (!empty($entity->$fieldname->target_id)) {
          $field_names[] = $fieldname;
        }
      }
      if (!empty($field_names)) {
        return $field_names;
      }
    }

  }

  /**
   * Check the presence of target ids in an entity object, for a given field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param int $referenced_entity_id
   *   The referenced entity id.
   * @param string $field_name
   *   The field name where to check this information.
   *
   * @return TRUE if the $host_entity has the $referenced_entity_id "target_id"
   *   value in any delta of the $field_name, FALSE otherwise.
   */
  private function targetIdIsReferencedInEntity($host_entity, $referenced_entity_id, $field_name) {
    if (!$host_entity->$field_name->isEmpty()) {
      foreach ($host_entity->get($field_name) as $field_delta) {
        if ($field_delta->target_id == $referenced_entity_id) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

}
