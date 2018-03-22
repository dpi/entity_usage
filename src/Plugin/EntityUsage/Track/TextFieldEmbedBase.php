<?php

namespace Drupal\entity_usage\Plugin\EntityUsage\Track;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\entity_usage\EmbedTrackInterface;
use Drupal\entity_usage\EntityUsage;
use Drupal\entity_usage\EntityUsageTrackBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for plugins tracking usage in entities embedded in WYSIWYG fields.
 */
abstract class TextFieldEmbedBase extends EntityUsageTrackBase implements EmbedTrackInterface {

  /**
   * The ModuleHandler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The EntityRepository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

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
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The ModuleHandler service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The EntityRepositoryInterface service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityUsage $usage_service, EntityFieldManagerInterface $entity_field_manager, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, EntityRepositoryInterface $entity_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $usage_service, $entity_field_manager, $config_factory);
    $this->moduleHandler = $module_handler;
    $this->entityRepository = $entity_repository;
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
      $container->get('module_handler'),
      $container->get('entity.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityCreation(ContentEntityInterface $source_entity) {
    $referenced_entities_by_field = $this->getEmbeddedEntities($source_entity);
    foreach ($referenced_entities_by_field as $field_name => $embedded_entities) {
      foreach ($embedded_entities as $target_uuid => $target_type) {
        // Check if the target entity exists since text fields are not
        // automatically updated when an entity is removed.
        /** @var \Drupal\Core\Entity\ContentEntityInterface $target_entity */
        if ($target_entity = $this->entityRepository->loadEntityByUuid($target_type, $target_uuid)) {
          $this->usageService->registerUsage($target_entity->id(), $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_entity->getRevisionId(), $this->pluginId, $field_name);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityUpdate(ContentEntityInterface $source_entity) {
    // If we create a new revision, just add the new tracking records.
    if ($source_entity->getRevisionId() != $source_entity->original->getRevisionId()) {
      $this->trackOnEntityCreation($source_entity);
      return;
    }

    // We are updating an existing revision, compare target entities to see if
    // we need to add or remove tracking records.
    $current_field_uuids = $this->getEmbeddedEntities($source_entity);
    $original_field_uuids = $this->getEmbeddedEntities($source_entity->original);

    foreach ($current_field_uuids as $field_name => $uuids) {
      if (!empty($original_field_uuids[$field_name])) {
        $uuids = array_diff_key($uuids, $original_field_uuids[$field_name]);
      }
      foreach ($uuids as $target_uuid => $target_type) {
        // Check if the target entity exists since text fields are not
        // automatically updated when an entity is removed.
        /** @var \Drupal\Core\Entity\ContentEntityInterface $target_entity */
        if ($target_entity = $this->entityRepository->loadEntityByUuid($target_type, $target_uuid)) {
          $this->usageService->registerUsage($target_entity->id(), $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_entity->getRevisionId(), $this->pluginId, $field_name);
        }
      }
    }

    foreach ($original_field_uuids as $field_name => $uuids) {
      if (!empty($current_field_uuids[$field_name])) {
        $uuids = array_diff_key($uuids, $current_field_uuids[$field_name]);
      }
      foreach ($uuids as $target_uuid => $target_type) {
        // Check if the target entity exists since text fields are not
        // automatically updated when an entity is removed.
        /** @var \Drupal\Core\Entity\ContentEntityInterface $target_entity */
        if ($target_entity = $this->entityRepository->loadEntityByUuid($target_type, $target_uuid)) {
          $this->usageService->registerUsage($target_entity->id(), $target_type, $source_entity->id(), $source_entity->getEntityTypeId(), $source_entity->language()->getId(), $source_entity->getRevisionId(), $this->pluginId, $field_name, 0);
        }
      }
    }
  }

  /**
   * Get all entities embedded (<drupal-entity>) in formatted text fields.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity.
   *
   * @return array
   *   An array of found embedded entities, in the following structure:
   *   [
   *     'field_name' => [
   *       'uuid1' => 'entity_type1',
   *       'uuid2' => 'entity_type1',
   *       'uuid3' => 'entity_type2',
   *        etc.
   *     ],
   *   ].
   */
  protected function getEmbeddedEntities(ContentEntityInterface $source_entity) {
    $entities = [];

    if ($this->moduleHandler->moduleExists('editor')) {
      $formatted_text_fields = _editor_get_formatted_text_fields($source_entity);
      foreach ($formatted_text_fields as $formatted_text_field_name) {
        $text = '';
        $field_items = $source_entity->get($formatted_text_field_name);
        foreach ($field_items as $field_item) {
          $text .= $field_item->value;
          if ($field_item->getFieldDefinition()->getType() === 'text_with_summary') {
            $text .= $field_item->summary;
          }
        }
        $entities[$formatted_text_field_name] = $this->parseEntitiesFromText($text);
      }
    }

    return $entities;
  }

}
