<?php

namespace Drupal\entity_usage;

use Drupal\Core\Entity\EntityInterface;


/**
 * Entity usage backend interface.
 */
interface EntityUsageInterface {

  /**
   * Records that an entity is referencing another entity.
   *
   * Examples:
   * - A node that references another node using an entityreference field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A target (referenced) entity.
   * @param int $re_id
   *   The identifier of the referencing entity.
   * @param string $re_type
   *   The type of the entity that is referencing.
   * @param string $method
   *   (optional) The method or way the two entities are being referenced.
   *   Defaults to 'entity_reference'.
   * @param int $count
   *   (optional) The number of references to add to the object. Defaults to 1.
   */
  public function add(EntityInterface $entity, $re_id, $re_type, $method = 'entity_reference', $count = 1);

  /**
   * Removes a record indicating that the entity is not being referenced anymore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A target (referenced) entity.
   * @param int $re_id
   *   (optional) The unique, numerid ID of the object containing the referenced
   *   entity. May be omitted if all references to an entity are being deleted.
   *   Defaults to NULL.
   * @param string $re_type
   *   (optional) The type of the object containing the referenced entity. May
   *   be omitted if all entity-type references to a file are being deleted.
   *   Defaults to NULL.
   * @param int $count
   *   (optional) The number of references to delete from the object. Defaults
   *   to 1. Zero may be specified to delete all references to the entity within
   *   a specific object.
   */
  public function delete(EntityInterface $entity, $re_id = NULL, $re_type = NULL, $count = 1);

  /**
   * Determines where an entity is used.
   *
   * Examples:
   *  - A possible return value of this function could be:
   *  [
   *    'node' => [
   *      123 => 1,
   *      124 => 1,
   *    ],
   *    'user' => [
   *      2 => 1,
   *    ],
   *  ]
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A target (referenced) entity.
   *
   * @return array
   *   A nested array with usage data. The first level is keyed by the type of
   *   the referencing entities, the second by the referencing objects id. The
   *   value of the second level contains the usage count.
   */
  public function listUsage(EntityInterface $entity);

}
