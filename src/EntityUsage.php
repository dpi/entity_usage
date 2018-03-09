<?php

namespace Drupal\entity_usage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\entity_usage\Events\Events;
use Drupal\entity_usage\Events\EntityUsageEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines the entity usage base class.
 */
class EntityUsage implements EntityUsageInterface {

  /**
   * The database connection used to store entity usage information.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The name of the SQL table used to store entity usage information.
   *
   * @var string
   */
  protected $tableName;

  /**
   * An event dispatcher instance.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Construct the EntityUsage object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection which will be used to store the entity usage
   *   information.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   An event dispatcher instance to use for events.
   * @param string $table
   *   (optional) The table to store the entity usage info. Defaults to
   *   'entity_usage'.
   */
  public function __construct(Connection $connection, EventDispatcherInterface $event_dispatcher, $table = 'entity_usage') {
    $this->connection = $connection;
    $this->tableName = $table;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public function add($target_id, $target_type, $source_id, $source_type, $method = 'entity_reference', $field_name = NULL, $count = 1) {
    $this->connection->merge($this->tableName)
      ->keys([
        'target_id' => $target_id,
        'target_type' => $target_type,
        'source_id' => $source_id,
        'source_type' => $source_type,
        'method' => $method,
        'field_name' => $field_name,
      ])
      ->fields(['count' => $count])
      ->expression('count', 'count + :count', [':count' => $count])
      ->execute();

    $event = new EntityUsageEvent($target_id, $target_type, $source_id, $source_type, $method, $field_name, $count);
    $this->eventDispatcher->dispatch(Events::USAGE_ADD, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($target_id, $target_type, $source_id = NULL, $source_type = NULL, $field_name = NULL, $count = 1) {
    $query = $this->connection->delete($this->tableName)
      ->condition('target_type', $target_type)
      ->condition('target_id', $target_id);
    if ($source_type && $source_id) {
      $query
        ->condition('source_type', $source_type)
        ->condition('source_id', $source_id);
    }
    if ($field_name) {
      $query
        ->condition('field_name', $field_name);
    }
    if ($count) {
      $query->condition('count', $count, '<=');
    }
    $result = $query->execute();

    // If the row has more than the specified count decrement it by that number.
    if (!$result && $count > 0) {
      $query = $this->connection->update($this->tableName)
        ->condition('target_type', $target_type)
        ->condition('target_id', $target_id);
      if ($source_type && $source_id) {
        $query
          ->condition('source_type', $source_type)
          ->condition('source_id', $source_id);
      }
      if ($field_name) {
        $query
          ->condition('field_name', $field_name);
      }
      $query->expression('count', 'count - :count', [':count' => $count]);
      $query->execute();
    }

    $event = new EntityUsageEvent($target_id, $target_type, $source_id, $source_type, NULL, NULL, $count);
    $this->eventDispatcher->dispatch(Events::USAGE_DELETE, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function bulkDeleteTargets($target_type) {
    $query = $this->connection->delete($this->tableName)
      ->condition('target_type', $target_type);
    $query->execute();

    $event = new EntityUsageEvent(NULL, $target_type, NULL, NULL, NULL, NULL, NULL);
    $this->eventDispatcher->dispatch(Events::BULK_DELETE_DESTINATIONS, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function bulkDeleteSources($source_type) {
    $query = $this->connection->delete($this->tableName)
      ->condition('source_type', $source_type);
    $query->execute();

    $event = new EntityUsageEvent(NULL, NULL, NULL, $source_type, NULL, NULL, NULL);
    $this->eventDispatcher->dispatch(Events::BULK_DELETE_SOURCES, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function listUsage(EntityInterface $target_entity, $include_method = FALSE) {
    $result = $this->connection->select($this->tableName, 'e')
      ->fields('e', ['source_id', 'source_type', 'method', 'field_name', 'count'])
      ->condition('target_id', $target_entity->id())
      ->condition('target_type', $target_entity->getEntityTypeId())
      ->condition('count', 0, '>')
      ->execute();

    $references = [];
    foreach ($result as $usage) {
      $field_name = $usage->field_name ?: '_unknown';
      if ($include_method) {
        $references[$usage->method][$usage->source_type][$usage->source_id][$field_name] = $usage->count;
      }
      else {
        $count = $usage->count;
        // If there were previous usages recorded for this same pair of entities
        // (with different methods), sum on the top of it.
        if (!empty($references[$usage->source_type][$usage->source_id][$field_name])) {
          $count += $references[$usage->source_type][$usage->source_id][$field_name];
        }
        $references[$usage->source_type][$usage->source_id][$field_name] = $count;
      }
    }

    return $references;
  }

  /**
   * {@inheritdoc}
   */
  public function listReferencedEntities(EntityInterface $source_entity) {
    $result = $this->connection->select($this->tableName, 'e')
      ->fields('e', ['target_id', 'target_type', 'field_name', 'count'])
      ->condition('source_id', $source_entity->id())
      ->condition('source_type', $source_entity->getEntityTypeId())
      ->condition('count', 0, '>')
      ->execute();

    $references = [];
    foreach ($result as $usage) {
      $count = $usage->count;
      $field_name = $usage->field_name ?: '_unknown';
      // If there were previous usages recorded for this same pair of entities
      // (with different methods), sum on the top of it.
      if (!empty($references[$usage->target_type][$usage->target_id][$field_name])) {
        $count += $references[$usage->target_type][$usage->target_id][$field_name];
      }
      $references[$usage->target_type][$usage->target_id][$field_name] = $count;
    }

    return $references;
  }

}
