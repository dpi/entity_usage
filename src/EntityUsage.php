<?php

namespace Drupal\entity_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
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
   * The config factory.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The ModuleHandler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Construct the EntityUsage object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection which will be used to store the entity usage
   *   information.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   An event dispatcher instance to use for events.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The ModuleHandler service.
   * @param string $table
   *   (optional) The table to store the entity usage info. Defaults to
   *   'entity_usage'.
   */
  public function __construct(Connection $connection, EventDispatcherInterface $event_dispatcher, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, $table = 'entity_usage') {
    $this->connection = $connection;
    $this->tableName = $table;
    $this->eventDispatcher = $event_dispatcher;
    $this->moduleHandler = $module_handler;
    $this->config = $config_factory->get('entity_usage.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function add($target_id, $target_type, $source_id, $source_type, $source_langcode, $source_vid, $method, $field_name, $count = 1) {
    // Check if target entity type is enabled, all entity types are enabled by
    // default.
    $enabled_target_entity_types = $this->config->get('track_enabled_target_entity_types');
    if ($enabled_target_entity_types && !in_array($target_type, $enabled_target_entity_types, TRUE)) {
      return;
    }

    // Allow modules to block this operation.
    $context = [
      'target_id' => $target_id,
      'target_type' => $target_type,
      'source_id' => $source_id,
      'source_type' => $source_type,
      'source_langcode' => $source_langcode,
      'source_vid' => $source_vid,
      'method' => $method,
      'field_name' => $field_name,
      'count' => $count,
      'action' => 'add',
    ];
    $abort = $this->moduleHandler->invokeAll('entity_usage_block_tracking', $context);
    // If at least one module wants to block the tracking, bail out.
    if (in_array(TRUE, $abort, TRUE)) {
      return;
    }

    $this->connection->merge($this->tableName)
      ->keys([
        'target_id' => $target_id,
        'target_type' => $target_type,
        'source_id' => $source_id,
        'source_type' => $source_type,
        'source_langcode' => $source_langcode,
        'source_vid' => $source_vid ?: 0,
        'method' => $method,
        'field_name' => $field_name,
      ])
      ->fields(['count' => $count])
      ->expression('count', 'count + :count', [':count' => $count])
      ->execute();

    $event = new EntityUsageEvent($target_id, $target_type, $source_id, $source_type, $source_langcode, $source_vid, $method, $field_name, $count);
    $this->eventDispatcher->dispatch(Events::USAGE_ADD, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($target_id, $target_type, $source_id = NULL, $source_type = NULL, $source_langcode = NULL, $source_vid = NULL, $method = NULL, $field_name = NULL, $count = 1) {
    // Allow modules to block this operation.
    $context = [
      'target_id' => $target_id,
      'target_type' => $target_type,
      'source_id' => $source_id,
      'source_type' => $source_type,
      'source_langcode' => $source_langcode,
      'source_vid' => $source_vid,
      'method' => $method,
      'field_name' => $field_name,
      'count' => $count,
      'action' => 'delete',
    ];
    $abort = $this->moduleHandler->invokeAll('entity_usage_block_tracking', $context);
    // If at least one module wants to block the tracking, bail out.
    if (in_array(TRUE, $abort, TRUE)) {
      return;
    }

    $query = $this->connection->delete($this->tableName)
      ->condition('target_type', $target_type)
      ->condition('target_id', $target_id);
    if ($source_type && $source_id) {
      $query
        ->condition('source_type', $source_type)
        ->condition('source_id', $source_id);
    }
    if ($source_langcode) {
      $query
        ->condition('source_langcode', $source_langcode);
    }
    if ($source_vid) {
      $query
        ->condition('source_vid', $source_vid ?: 0);
    }
    if ($method) {
      $query
        ->condition('method', $method);
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
          ->condition('source_id', $source_id)
          ->condition('source_vid', $source_vid ?: 0);
      }
      if ($source_langcode) {
        $query
          ->condition('source_langcode', $source_langcode);
      }
      if ($method) {
        $query
          ->condition('method', $method);
      }
      if ($field_name) {
        $query
          ->condition('field_name', $field_name);
      }
      $query->expression('count', 'count - :count', [':count' => $count]);
      $query->execute();
    }

    $event = new EntityUsageEvent($target_id, $target_type, $source_id, $source_type, $source_langcode, $source_vid, $method, $field_name, $count);
    $this->eventDispatcher->dispatch(Events::USAGE_DELETE, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function bulkDeleteTargets($target_type) {
    $query = $this->connection->delete($this->tableName)
      ->condition('target_type', $target_type);
    $query->execute();

    $event = new EntityUsageEvent(NULL, $target_type, NULL, NULL, NULL, NULL, NULL, NULL, NULL);
    $this->eventDispatcher->dispatch(Events::BULK_DELETE_DESTINATIONS, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function bulkDeleteSources($source_type) {
    $query = $this->connection->delete($this->tableName)
      ->condition('source_type', $source_type);
    $query->execute();

    $event = new EntityUsageEvent(NULL, NULL, NULL, $source_type, NULL, NULL, NULL, NULL, NULL);
    $this->eventDispatcher->dispatch(Events::BULK_DELETE_SOURCES, $event);
  }

  /**
   * {@inheritdoc}
   */
  public function listSources(EntityInterface $target_entity) {
    $result = $this->connection->select($this->tableName, 'e')
      ->fields('e', ['source_id', 'source_type', 'source_langcode', 'source_vid', 'method', 'field_name', 'count'])
      ->condition('target_id', $target_entity->id())
      ->condition('target_type', $target_entity->getEntityTypeId())
      ->condition('count', 0, '>')
      ->orderBy('source_type')
      ->orderBy('source_id', 'DESC')
      ->orderBy('source_vid', 'DESC')
      ->orderBy('source_langcode')
      ->execute();

    $references = [];
    foreach ($result as $usage) {
      $references[$usage->source_type][$usage->source_id][] = [
        'source_langcode' => $usage->source_langcode,
        'source_vid' => $usage->source_vid,
        'method' => $usage->method,
        'field_name' => $usage->field_name,
        'count' => $usage->count,
      ];
    }

    return $references;
  }

  /**
   * {@inheritdoc}
   */
  public function listTargets(EntityInterface $source_entity) {
    $result = $this->connection->select($this->tableName, 'e')
      ->fields('e', ['target_id', 'target_type', 'method', 'field_name', 'count'])
      ->condition('source_id', $source_entity->id())
      ->condition('source_type', $source_entity->getEntityTypeId())
      ->condition('count', 0, '>')
      ->orderBy('target_id')
      ->orderBy('target_id', 'DESC')
      ->execute();

    $references = [];
    foreach ($result as $usage) {
      $references[$usage->target_type][$usage->target_id][] = [
        'method' => $usage->method,
        'field_name' => $usage->field_name,
        'count' => $usage->count,
      ];
    }

    return $references;
  }

}
