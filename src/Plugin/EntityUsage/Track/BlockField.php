<?php

namespace Drupal\entity_usage\Plugin\EntityUsage\Track;

use Drupal\block_field\BlockFieldItemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\entity_usage\EntityUsage;
use Drupal\entity_usage\EntityUsageTrackBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tracks usage of entities related in block_field fields.
 *
 * @EntityUsageTrack(
 *   id = "block_field",
 *   label = @Translation("Block Field"),
 *   description = @Translation("Tracks relationships created with 'Block Field' fields."),
 * )
 */
class BlockField extends EntityUsageTrackBase {

  /**
   * Entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs display plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\entity_usage\EntityUsage $usage_service
   *   The usage tracking service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The EntityFieldManager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityUsage $usage_service, EntityFieldManagerInterface $entity_field_manager, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $usage_service, $entity_field_manager, $config_factory);
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_usage.usage'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityCreation(EntityInterface $source_entity) {
    $block_fields = array_keys($this->getReferencingFields($source_entity, ['block_field']));
    foreach ($block_fields as $field_name) {
      if (!$source_entity->{$field_name}->isEmpty()) {
        /** @var \Drupal\link\Plugin\Field\FieldType\LinkItem $field_item */
        foreach ($source_entity->{$field_name} as $field_item) {
          // This item got added, add a tracking record.
          $target_entity = $this->getTargetEntity($field_item);
          if ($target_entity) {
            list($target_type, $target_id) = explode('|', $target_entity);
            $source_vid = ($source_entity instanceof RevisionableInterface && $source_entity->getRevisionId()) ? $source_entity->getRevisionId() : 0;
            $this->usageService->registerUsage($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_vid, $this->pluginId, $field_name);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityUpdate(EntityInterface $source_entity) {
    $block_fields = array_keys($this->getReferencingFields($source_entity, ['block_field']));
    foreach ($block_fields as $field_name) {
      if (($source_entity instanceof RevisionableInterface) &&
        $source_entity->getRevisionId() != $source_entity->original->getRevisionId() &&
        !$source_entity->{$field_name}->isEmpty()) {

        $this->trackOnEntityCreation($source_entity);
        return;
      }

      // We are updating an existing revision, compare target entities to see if
      // we need to add or remove tracking records.
      $current_targets = [];
      if (!$source_entity->{$field_name}->isEmpty()) {
        foreach ($source_entity->{$field_name} as $field_item) {
          $target_entity = $this->getTargetEntity($field_item);
          if ($target_entity) {
            $current_targets[] = $target_entity;
          }
        }
      }

      $original_targets = [];
      if (!$source_entity->original->{$field_name}->isEmpty()) {
        foreach ($source_entity->original->{$field_name} as $field_item) {
          $target_entity = $this->getTargetEntity($field_item);
          if ($target_entity) {
            $original_targets[] = $target_entity;
          }
        }
      }

      // If a field references the same target entity, we record only one usage.
      $original_targets = array_unique($original_targets);
      $current_targets = array_unique($current_targets);

      $added_ids = array_diff($current_targets, $original_targets);
      $removed_ids = array_diff($original_targets, $current_targets);

      foreach ($added_ids as $added_entity) {
        list($target_type, $target_id) = explode('|', $added_entity);
        $source_vid = ($source_entity instanceof RevisionableInterface && $source_entity->getRevisionId()) ? $source_entity->getRevisionId() : 0;
        $this->usageService->registerUsage($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_vid, $this->pluginId, $field_name);
      }
      foreach ($removed_ids as $removed_entity) {
        list($target_type, $target_id) = explode('|', $removed_entity);
        $source_vid = ($source_entity instanceof RevisionableInterface && $source_entity->getRevisionId()) ? $source_entity->getRevisionId() : 0;
        $this->usageService->registerUsage($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_vid, $this->pluginId, $field_name, 0);
      }
    }
  }

  /**
   * Gets the target entity of a block field item.
   *
   * @param \Drupal\block_field\BlockFieldItemInterface $item
   *   The Block Field item to get the target entity from.
   *
   * @return string|null
   *   Target Type and ID glued together with a '|' or NULL if no entity linked.
   */
  protected function getTargetEntity(BlockFieldItemInterface $item) {
    $block_instance = $item->getBlock();
    if (!$block_instance) {
      return NULL;
    }

    $target_type = NULL;
    $target_id = NULL;

    // If there is a view inside this block, track the view entity instead.
    if ($block_instance->getBaseId() === 'views_block') {
      list($view_name, $display_id) = explode('-', $block_instance->getDerivativeId(), 2);
      // @todo worth trying to track the display id as well?
      // At this point the view is supposed to exist. Only track it if so.
      if ($this->entityTypeManager->getStorage('view')->load($view_name)) {
        $target_type = 'view';
        $target_id = $view_name;
      }
    }
    // @todo other special cases apart from views?
    else {
      // @todo DI.
      $id = $block_instance->getConfiguration()['id'];
      if ($this->entityTypeManager->getStorage('block_content')->load($id)) {
        // Doing this here means that an initial save operation of a host entity
        // will likely not track this block, once it does not exist at this
        // point. However, it's preferable to miss that and ensure we only track
        // lodable entities.
        $target_type = 'block_content';
        $target_id = $block_instance->getConfiguration()['id'];
      }
    }

    // Glue the target type and ID together for easy comparison.
    return ($target_type && $target_id) ? $target_type . '|' . $target_id : NULL;
  }

}
