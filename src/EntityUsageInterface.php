<?php

namespace Drupal\entity_usage;

use Drupal\Core\Entity\EntityInterface;

/**
 * Entity usage interface.
 */
interface EntityUsageInterface {

  /**
   * Records that a source entity is referencing a target entity.
   *
   * Examples:
   * - A node that references another node using an entityreference field.
   *
   * @param int $target_id
   *   The target entity ID.
   * @param string $target_type
   *   The target entity type.
   * @param int $source_id
   *   The source entity ID.
   * @param string $source_type
   *   The source entity type.
   * @param string $method
   *   The method used to relate source entity with the target entity. Normally
   *   the plugin id.
   * @param string $field_name
   *   The name of the field in the source entity using the target entity.
   * @param int $count
   *   (optional) The number of references to add to the object. Defaults to 1.
   */
  public function add($target_id, $target_type, $source_id, $source_type, $method, $field_name, $count = 1);

  /**
   * Records that a source entity is no longer referencing a target entity.
   *
   * @param int $target_id
   *   The target entity ID.
   * @param string $target_type
   *   The target entity type.
   * @param int $source_id
   *   (optional) The source entity ID. May be omitted if all references to an
   *   entity are being deleted. Defaults to NULL.
   * @param string $source_type
   *   (optional) The source entity type. May be omitted if all entity type
   *   references to a target are being deleted. Defaults to NULL.
   * @param string $field_name
   *   (optional) The name of the field in the source entity using the
   *   target entity. Defaults to NULL.
   * @param int $count
   *   (optional) The number of references to delete from the object. Defaults
   *   to 1. Zero may be specified to delete all references to the entity within
   *   a specific object.
   */
  public function delete($target_id, $target_type, $source_id = NULL, $source_type = NULL, $field_name = NULL, $count = 1);

  /**
   * Remove all records of a given target entity type.
   *
   * @param string $target_type
   *   The target entity type.
   */
  public function bulkDeleteTargets($target_type);

  /**
   * Remove all records of a given source entity type.
   *
   * @param string $source_type
   *   The source entity type.
   */
  public function bulkDeleteSources($source_type);

  /**
   * Provide a list of all referencing source entities for a target entity.
   *
   * Examples:
   *  - Return example 1:
   *  [
   *    'node' => [
   *      123 => [
   *        'field_name' => 1,
   *      ],
   *      124 => [
   *        'field_name' => 1,
   *      ],
   *    ],
   *    'user' => [
   *      2 => [
   *        'field_name' => 1,
   *      ],
   *    ],
   *  ]
   *  - Return example 2:
   *  [
   *    'entity_reference' => [
   *      'node' => [...],
   *      'user' => [...],
   *    ]
   *  ]
   *
   * @param \Drupal\Core\Entity\EntityInterface $target_entity
   *   A target entity.
   * @param bool $include_method
   *   (optional) Whether the results must be wrapped into an additional array
   *   level, by the reference method. Defaults to FALSE.
   *
   * @return array
   *   A nested array with usage data. The first level is keyed by the type of
   *   the source entities, the second by the source id and the third the field
   *   name. The value of the third level contains the usage count.
   *   Note that if $include_method is TRUE, the first level is keyed by the
   *   reference method, and the second level will continue as explained above.
   */
  public function listSources(EntityInterface $target_entity, $include_method = FALSE);

  /**
   * Provide a list of all referenced target entities for a source entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $source_entity
   *   The source entity to check for references.
   *
   * @return array
   *   A nested array with usage data. The first level is keyed by the type of
   *   the target entities, the second by the target id and the third
   *   the field name. The value of the third level contains the usage count.
   *
   * @see \Drupal\entity_usage\EntityUsageInterface::listSources()
   */
  public function listTargets(EntityInterface $source_entity);

}
