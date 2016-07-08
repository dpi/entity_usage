<?php

namespace Drupal\entity_usage;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityType;

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
   * Constructor method.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger service.
   */
  public function __construct(
      EntityUsage $usage_service,
      EntityFieldManagerInterface $entity_field_manager,
      LoggerChannelFactoryInterface $logger
  ) {
    $this->usageService = $usage_service;
    $this->entityFieldManager = $entity_field_manager;
    $this->logger = $logger;
  }

  /**
   * Track updates on creation of potential host entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity we are dealing with.
   */
  public function trackUpdateOnCreation(EntityInterface $entity) {

    // Only act on content entities.
    $is_content_entity = $entity->getEntityType() instanceof ContentEntityType;
    if (!$is_content_entity) {
      return;
    }

    foreach ($this->isHostEntity($entity) as $field_name) {
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // This item got added. Track the usage up.
          $this->incrementUsage($entity, $field_name, $field_item);
        }
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
    $is_content_entity = $entity->getEntityType() instanceof ContentEntityType;
    if (!$is_content_entity) {
      return;
    }

    // First deal with the deletion of hosting entities.
    foreach ($this->isHostEntity($entity) as $field_name) {
      if (!$entity->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->$field_name as $field_item) {
          // This item got deleted. Track the usage down.
          $this->decrementUsage($entity, $field_name, $field_item);
        }
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
    $is_content_entity = $entity->getEntityType() instanceof ContentEntityType;
    if (!$is_content_entity) {
      return;
    }

    foreach ($this->isHostEntity($entity) as $field_name) {

      // Original entity had some values on the field.
      if (!$entity->original->$field_name->isEmpty()) {
        /** @var \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item */
        foreach ($entity->original->$field_name as $field_item) {
          // Check if this item is still present on the updated entity.
          if (!$this->targetIdIsReferencedInEntity($entity, $field_item->target_id, $field_name)) {
            // This item got removed. Track the usage down.
            $this->decrementUsage($entity, $field_name, $field_item);
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
            $this->incrementUsage($entity, $field_name, $field_item);
          }
        }
      }
    }

  }

  /**
   * Check if a given entity object is "pointing to" other entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return bool|array
   *   If the received entity is potentially a "linker" to other entities, will
   *   return an array of field_names that could do this referencing. Will
   *   return FALSE if the entity cannot link to any other entity.
   */
  private function isHostEntity(EntityInterface $entity) {
    // For now only entityreference field types are supported as method for
    // "linking" entities.
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
   * Helper method to increment the usage.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $field_name
   *   The name of the entity_reference field, present in $entity.
   * @param \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item
   *   The field item containing the values of the target entity.
   */
  private function incrementUsage(EntityInterface $entity, $field_name, EntityReferenceItem $field_item) {
    /** @var \Drupal\field\Entity\FieldConfig $definition */
    $definition = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle())[$field_name];
    $referenced_entity_type = $definition->getSetting('target_type');
    $this->usageService->add($field_item->target_id, $referenced_entity_type, $entity->id(), $entity->getEntityTypeId());
  }

  /**
   * Helper method to decrement the usage.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $field_name
   *   The name of the entity_reference field, present in $entity.
   * @param \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem $field_item
   *   The field item containing the values of the target entity.
   */
  private function decrementUsage(EntityInterface $entity, $field_name, EntityReferenceItem $field_item) {
    /** @var \Drupal\field\Entity\FieldConfig $definition */
    $definition = $this->entityFieldManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle())[$field_name];
    $referenced_entity_type = $definition->getSetting('target_type');
    $this->usageService->delete($field_item->target_id, $referenced_entity_type, $entity->id(), $entity->getEntityTypeId());
  }

}
