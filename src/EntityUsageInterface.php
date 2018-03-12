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
   * @param string $source_langcode
   *   The source entity language code.
   * @param string $source_vid
   *   The source entity revision ID.
   * @param string $method
   *   The method used to relate source entity with the target entity. Normally
   *   the plugin id.
   * @param string $field_name
   *   The name of the field in the source entity using the target entity.
   * @param int $count
   *   (optional) The number of references to add to the object. Defaults to 1.
   */
  public function add($target_id, $target_type, $source_id, $source_type, $source_langcode, $source_vid, $method, $field_name, $count = 1);

  /**
   * Records that a source entity is no longer referencing a target entity.
   *
   * @param int $target_id
   *   The target entity ID.
   * @param string $target_type
   *   The target entity type.
   * @param int $source_id
   *   (optional) The source entity ID. May be omitted if all references to an
   *   target are being deleted. Defaults to NULL.
   * @param string $source_type
   *   (optional) The source entity type. May be omitted if all references to a
   *   target are being deleted. Defaults to NULL.
   * @param string $source_langcode
   *   (optional) The source entity language code. May be omitted if all
   *   references to a target are being deleted. Defaults to NULL.
   * @param string $source_vid
   *   (optional) The source entity revision ID. May be omitted if all
   *   references to a target are being deleted. Defaults to NULL.
   * @param string $method
   *   (optional) The method used to relate source entity with the target
   *   entity. Defaults to NULL.
   * @param string $field_name
   *   (optional) The name of the field in the source entity using the
   *   target entity. May be omitted if all references to a target are being
   *   deleted. Defaults to NULL.
   * @param int $count
   *   (optional) The number of references to delete from the object. Defaults
   *   to 1. Zero may be specified to delete all references to the entity within
   *   a specific object. Defaults to 1.
   */
  public function delete($target_id, $target_type, $source_id = NULL, $source_type = NULL, $source_langcode = NULL, $source_vid = NULL, $method = NULL, $field_name = NULL, $count = 1);

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
   *        'source_langcode' => 'en',
   *        'source_vid' => '128',
   *        'method' => 'entity_reference',
   *        'field_name' => 'Related items',
   *        'count' => 1,
   *      ],
   *      124 => [
   *        'source_langcode' => 'en',
   *        'source_vid' => '129',
   *        'method' => 'entity_reference',
   *        'field_name' => 'Related items',
   *        'count' => 1,
   *      ],
   *    ],
   *    'user' => [
   *      2 => [
   *        'source_langcode' => 'en',
   *        'source_vid' => '2',
   *        'method' => 'entity_reference',
   *        'field_name' => 'Author',
   *        'count' => 1,
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
   *
   * @return array
   *   A nested array with usage data. The first level is keyed by the type of
   *   the source entities, the second by the source id. The value of the second
   *   level contains all other information like the method used by the source
   *   to reference the target, the field name and the source language code.
   */
  public function listSources(EntityInterface $target_entity);

  /**
   * Provide a list of all referenced target entities for a source entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $source_entity
   *   The source entity to check for references.
   *
   * @return array
   *   A nested array with usage data. The first level is keyed by the type of
   *   the target entities, the second by the target id. The value of the second
   *   level contains all other information like the method used by the source
   *   to reference the target, the field name and the target language code.
   *
   * @see \Drupal\entity_usage\EntityUsageInterface::listSources()
   */
  public function listTargets(EntityInterface $source_entity);

}
