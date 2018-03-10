<?php

/**
 * @file
 * Hooks for the entity_usage module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Allows modules to block a specific tracking record.
 *
 * Modules implementing this hook should return TRUE if the operation should
 * be blocked. Any other return value will be disregarded and the register
 * written to the database.
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
 *   The number of usages being tracked or deleted.
 * @param string $action
 *   The strings 'add' or 'delete', indicating the API method that invoked this
 *   hook.
 */
function hook_entity_usage_block_tracking($target_id, $target_type, $source_id, $source_type, $method, $field_name, $count, $action) {
  if ($field_name === 'field_foo_bar' && $method === 'link') {
    return TRUE;
  }
  return FALSE;
}

/**
 * @} End of "addtogroup hooks".
 */
