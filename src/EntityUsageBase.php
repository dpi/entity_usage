<?php

namespace Drupal\entity_usage;

use Drupal\Core\Entity\EntityInterface;

/**
 * Defines the base class for the entity usage backend.
 */
abstract class EntityUsageBase implements EntityUsageInterface {

  /**
   * {@inheritdoc}
   */
  public function add(EntityInterface $entity, $re_id, $re_type, $method = 'entity_reference', $count = 1) {

  }

  /**
   * {@inheritdoc}
   */
  public function delete(EntityInterface $entity, $re_id = NULL, $re_type = NULL, $count = 1) {

  }

  /**
   * {@inheritdoc}
   */
  public function listUsage(EntityInterface $entity) {

  }

}
