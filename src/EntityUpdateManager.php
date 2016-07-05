<?php

namespace Drupal\entity_usage;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class EntityUpdateManager.
 *
 * @package Drupal\entity_update
 */
class EntityUpdateManager {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   */
  protected $logger;

  /**
   * Constructor method.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger service.
   */
  public function __construct(
      LoggerChannelFactoryInterface $logger
  ) {
    $this->logger = $logger;
  }

  /**
   * Track updates on entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity we are dealing with.
   * @param string $operation
   *   The operation the entity is going through (insert, update or delete).
   */
  public function trackUpdate(EntityInterface $entity, $operation) {
    $this->logger->get('entity_usage')->log('warning', 'Received entity: ' . $entity->label() . ' and operation: ' . $operation);
  }

}
