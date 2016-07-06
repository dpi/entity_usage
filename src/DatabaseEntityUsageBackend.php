<?php

namespace Drupal\entity_usage;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;

/**
 * Defines the database entity usage backend.
 */
class DatabaseEntityUsageBackend extends EntityUsageBase {

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
   * Construct the DatabaseEntityUsageBackend.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection which will be used to store the entity usage
   *   information.
   * @param string $table
   *   (optional) The table to store the entity usage info. Defaults to
   *   'entity_usage'.
   */
  public function __construct(Connection $connection, $table = 'entity_usage') {
    $this->connection = $connection;
    $this->tableName = $table;
  }

  /**
   * {@inheritdoc}
   */
  public function add($t_id, $t_type, $re_id, $re_type, $method = 'entity_reference', $count = 1) {
    $this->connection->merge($this->tableName)
      ->keys([
        't_id' => $t_id,
        't_type' => $t_type,
        're_id' => $re_id,
        're_type' => $re_type,
        'method' => $method,
      ])
      ->fields(['count' => $count])
      ->expression('count', 'count + :count', [':count' => $count])
      ->execute();

    parent::add($t_id, $t_type, $re_id, $re_type, $method, $count);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($t_id, $t_type, $re_id = NULL, $re_type = NULL, $count = 1) {

    // Delete rows that have an exact or less value to prevent empty rows.
    $query = $this->connection->delete($this->tableName)
      ->condition('t_type', $t_type)
      ->condition('t_id', $t_id);
    if ($re_type && $re_id) {
      $query
        ->condition('re_type', $re_type)
        ->condition('re_id', $re_id);
    }
    if ($count) {
      $query->condition('count', $count, '<=');
    }
    $result = $query->execute();

    // If the row has more than the specified count decrement it by that number.
    if (!$result && $count > 0) {
      $query = $this->connection->update($this->tableName)
        ->condition('t_type', $t_type)
        ->condition('t_id', $t_id);
      if ($re_type && $re_id) {
        $query
          ->condition('re_type', $re_type)
          ->condition('re_id', $re_id);
      }
      $query->expression('count', 'count - :count', [':count' => $count]);
      $query->execute();
    }

    parent::delete($t_id, $t_type, $re_id, $re_type, $count);
  }

  /**
   * {@inheritdoc}
   */
  public function listUsage(EntityInterface $entity, $include_method = FALSE) {
    $result = $this->connection->select($this->tableName, 'e')
      ->fields('e', ['re_id', 're_type', 'method', 'count'])
      ->condition('t_id', $entity->id())
      ->condition('t_type', $entity->getEntityTypeId())
      ->condition('count', 0, '>')
      ->execute();
    $references = [];
    foreach ($result as $usage) {
      if ($include_method) {
        $references[$usage->method][$usage->re_type][$usage->re_id] = $usage->count;
      }
      else {
        $references[$usage->re_type][$usage->re_id] = $usage->count;
      }
    }
    return $references;
  }

}
