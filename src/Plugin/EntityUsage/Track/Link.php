<?php

namespace Drupal\entity_usage\Plugin\EntityUsage\Track;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_usage\EntityUsage;
use Drupal\entity_usage\EntityUsageTrackBase;
use Drupal\link\LinkItemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tracks usage of entities related in entity_reference fields.
 *
 * @EntityUsageTrack(
 *   id = "link",
 *   label = @Translation("Link Field References"),
 *   description = @Translation("Tracks usage of entities related in link fields."),
 * )
 */
class Link extends EntityUsageTrackBase {

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
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityUsage $usage_service,
    EntityFieldManagerInterface $entity_field_manager,
    ConfigFactoryInterface $config_factory,
    EntityTypeManagerInterface $entity_type_manager
  ) {
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
  public function trackOnEntityCreation(ContentEntityInterface $source_entity) {
    foreach ($this->linkFieldsAvailable($source_entity) as $field_name) {
      if (!$source_entity->{$field_name}->isEmpty()) {
        /** @var \Drupal\link\Plugin\Field\FieldType\LinkItem $field_item */
        foreach ($source_entity->{$field_name} as $field_item) {
          // This item got added, add a tracking record.
          $target_entity = $this->getTargetEntity($field_item);
          if ($target_entity) {
            list($target_type, $target_id) = explode('|', $target_entity);
            $this->usageService->registerUsage($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_entity->getRevisionId(), $this->pluginId, $field_name);
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityUpdate(ContentEntityInterface $source_entity) {
    foreach ($this->linkFieldsAvailable($source_entity) as $field_name) {
      // If we create a new revision, just add the new tracking records.
      if ($source_entity->getRevisionId() != $source_entity->original->getRevisionId() && !$source_entity->$field_name->isEmpty()) {
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
        $this->usageService->registerUsage($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_entity->getRevisionId(), $this->pluginId, $field_name);
      }
      foreach ($removed_ids as $removed_entity) {
        list($target_type, $target_id) = explode('|', $removed_entity);
        $this->usageService->registerUsage($target_id, $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_entity->getRevisionId(), $this->pluginId, $field_name, 0);
      }
    }
  }

  /**
   * Retrieve the link fields on a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The entity object.
   *
   * @return array
   *   An array of field_names that could reference to other content entities.
   */
  protected function linkFieldsAvailable(ContentEntityInterface $source_entity) {
    $return_fields = [];
    $fields = $this->getReferencingFields($source_entity, ['link']);
    if (!empty($fields)) {
      $return_fields = array_keys($fields);
    }
    return $return_fields;
  }

  /**
   * Gets the target entity of a link item.
   *
   * @param \Drupal\link\LinkItemInterface $link
   *   The LinkItem to get the target entity from.
   *
   * @return string|null
   *   Target Type and ID glued together with a '|' or NULL if no entity linked.
   */
  protected function getTargetEntity(LinkItemInterface $link) {
    // Check if the link is referencing an entity.
    $url = $link->getUrl();
    if (!$url->isRouted() || !preg_match('/^entity\./', $url->getRouteName())) {
      return NULL;
    }

    // Ge the target entity type and ID.
    $route_parameters = $url->getRouteParameters();
    $target_type = array_keys($route_parameters)[0];
    $target_id = $route_parameters[$target_type];

    if (!($this->entityTypeManager->getDefinition($target_type) instanceof ContentEntityTypeInterface)) {
      // This module only supports content entity types.
      return NULL;
    }

    // Glue the target type and ID together for easy comparison.
    return $target_type . '|' . $target_id;
  }

}
