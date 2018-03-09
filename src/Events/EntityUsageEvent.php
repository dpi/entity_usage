<?php

namespace Drupal\entity_usage\Events;

use Symfony\Component\EventDispatcher\Event;

/**
 * Implementation of Entity Usage events.
 */
class EntityUsageEvent extends Event {

  /**
   * The target entity ID.
   *
   * @var string
   */
  protected $targetEntityId;

  /**
   * The target entity type.
   *
   * @var string
   */
  protected $targetEntityType;

  /**
   * The identifier of the source entity.
   *
   * @var string
   */
  protected $sourceEntityId;

  /**
   * The type of the entity that is source.
   *
   * @var string
   */
  protected $sourceEntityType;

  /**
   * The method or way the two entities are being referenced.
   *
   * @var string
   */
  protected $method;

  /**
   * The name of the field in the source entity using the target entity.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * The number of references to add or remove.
   *
   * @var string
   */
  protected $count;

  /**
   * EntityUsageEvents constructor.
   *
   * @param int $target_id
   *   The identifier of the target entity.
   * @param string $target_type
   *   The type of the target entity.
   * @param int $source_id
   *   The identifier of the source entity.
   * @param string $source_type
   *   The type of the entity that is source.
   * @param string $method
   *   The method or way the two entities are being referenced.
   * @param string $field_name
   *   The name of the field in the source entity using the target entity.
   * @param int $count
   *   The number of references to add or remove.
   */
  public function __construct($target_id = NULL, $target_type = NULL, $source_id = NULL, $source_type = NULL, $method = NULL, $field_name = NULL, $count = NULL) {
    $this->targetEntityId = $target_id;
    $this->targetEntityType = $target_type;
    $this->sourceEntityId = $source_id;
    $this->sourceEntityType = $source_type;
    $this->method = $method;
    $this->fieldName = $field_name;
    $this->count = $count;
  }

  /**
   * Sets the target entity id.
   *
   * @param int $id
   *   The target entity id.
   */
  public function setTargetEntityId($id) {
    $this->targetEntityId = $id;
  }

  /**
   * Sets the target entity type.
   *
   * @param string $type
   *   The target entity type.
   */
  public function setTargetEntityType($type) {
    $this->targetEntityType = $type;
  }

  /**
   * Sets the source entity id.
   *
   * @param int $id
   *   The source entity id.
   */
  public function setSourceEntityId($id) {
    $this->sourceEntityId = $id;
  }

  /**
   * Sets the source entity type.
   *
   * @param string $type
   *   The source entity type.
   */
  public function setSourceEntityType($type) {
    $this->sourceEntityType = $type;
  }

  /**
   * Sets the source method.
   *
   * @param string $method
   *   The source method.
   */
  public function setSourceMethod($method) {
    $this->method = $method;
  }

  /**
   * Sets the count.
   *
   * @param int $count
   *   The number od references to add or remove.
   */
  public function setCount($count) {
    $this->count = $count;
  }

  /**
   * Gets the target entity id.
   *
   * @return null|string
   *   The target entity id or NULL.
   */
  public function getTargetEntityId() {
    return $this->targetEntityId;
  }

  /**
   * Gets the target entity type.
   *
   * @return null|string
   *   The target entity type or NULL.
   */
  public function getTargetEntityType() {
    return $this->targetEntityType;
  }

  /**
   * Gets the source entity id.
   *
   * @return int|null
   *   The source entity id or NULL.
   */
  public function getSourceEntityId() {
    return $this->sourceEntityId;
  }

  /**
   * Gets the source entity type.
   *
   * @return null|string
   *   The source entity type or NULL.
   */
  public function getSourceEntityType() {
    return $this->sourceEntityType;
  }

  /**
   * Gets the source method.
   *
   * @return null|string
   *   The source method or NULL.
   */
  public function getSourceMethod() {
    return $this->method;
  }

  /**
   * Gets the source field name.
   *
   * @return null|string
   *   The source field name or NULL.
   */
  public function getSourceFieldName() {
    return $this->fieldName;
  }

  /**
   * Gets the count.
   *
   * @return int|null
   *   The number of references to add or remove or NULL.
   */
  public function getCount() {
    return $this->count;
  }

}
