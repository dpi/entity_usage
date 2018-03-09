<?php

namespace Drupal\entity_usage\Events;

/**
 * Contains all events thrown by Entity Usage.
 */
final class Events {

  /**
   * The USAGE_ADD event occurs when entities are referenced.
   *
   * @var string
   */
  const USAGE_ADD = 'entity_usage.add';

  /**
   * The USAGE_DELETE event occurs when a reference to an entity is removed.
   *
   * @var string
   */
  const USAGE_DELETE = 'entity_usage.delete';

  /**
   * The BULK_DELETE_DESTINATIONS event.
   *
   * The BULK_DELETE_DESTINATIONS event occurs when all records of a given
   * target entity type are removed.
   *
   * @var string
   */
  const BULK_DELETE_DESTINATIONS = 'entity_usage.bulk_delete_targets';

  /**
   * The BULK_DELETE_SOURCES event.
   *
   * The BULK_DELETE_SOURCES event occurs when all records of a given source
   * entity type are removed.
   *
   * @var string
   */
  const BULK_DELETE_SOURCES = 'entity_usage.bulk_delete_sources';

}
