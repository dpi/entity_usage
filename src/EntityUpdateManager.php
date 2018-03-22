<?php

namespace Drupal\entity_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Class EntityUpdateManager.
 *
 * @package Drupal\entity_usage
 */
class EntityUpdateManager {

  /**
   * The usage track service.
   *
   * @var \Drupal\entity_usage\EntityUsage
   */
  protected $usageService;

  /**
   * The usage track manager.
   *
   * @var \Drupal\entity_usage\EntityUsageTrackManager
   */
  protected $trackManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * EntityUpdateManager constructor.
   *
   * @param \Drupal\entity_usage\EntityUsage $usage_service
   *   The usage tracking service.
   * @param \Drupal\entity_usage\EntityUsageTrackManager $track_manager
   *   The PluginManager track service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(EntityUsage $usage_service, EntityUsageTrackManager $track_manager, ConfigFactoryInterface $config_factory) {
    $this->usageService = $usage_service;
    $this->trackManager = $track_manager;
    $this->config = $config_factory->get('entity_usage.settings');

  }

  /**
   * Track updates on creation of potential source entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity we are dealing with.
   */
  public function trackUpdateOnCreation(ContentEntityInterface $entity) {
    if (!$this->allowEntityTracking($entity)) {
      return;
    }

    // Call all plugins that want to track entity usages. We need to call this
    // for all translations as well since Drupal stores new revisions for all
    // translations by default when saving an entity.
    foreach ($entity->getTranslationLanguages() as $translation_language) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $translation */
      if ($translation = $entity->getTranslation($translation_language->getId())) {
        foreach ($this->getEnabledPlugins() as $plugin) {
          $plugin->trackOnEntityCreation($translation);
        }
      }
    }
  }

  /**
   * Track updates on edit / update of potential source entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity we are dealing with.
   */
  public function trackUpdateOnEdition(ContentEntityInterface $entity) {
    if (!$this->allowEntityTracking($entity)) {
      return;
    }

    // Call all plugins that want to track entity usages. We need to call this
    // for all translations as well since Drupal stores new revisions for all
    // translations by default when saving an entity.
    foreach ($entity->getTranslationLanguages() as $translation_language) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $translation */
      if ($translation = $entity->getTranslation($translation_language->getId())) {
        foreach ($this->getEnabledPlugins() as $plugin) {
          $plugin->trackOnEntityUpdate($translation);
        }
      }
    }
  }

  /**
   * Track updates on deletion of entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity we are dealing with.
   * @param string $type
   *   What type of deletion is being performed:
   *   - default: The main entity (default language, default revision) is being
   *   deleted (delete also other languages and revisions).
   *   - translation: Only one translation is being deleted.
   *   - revision: Onlyone revision is being deleted.
   *
   * @throws \InvalidArgumentException
   */
  public function trackUpdateOnDeletion(ContentEntityInterface $entity, $type = 'default') {
    if (!($entity instanceof ContentEntityInterface)) {
      return;
    }

    // When an entity is being deleted the logic is much simpler and we don't
    // even need to call the plugins. Just delete the records that affect this
    // entity both as target and source.
    switch ($type) {
      case 'revision':
        $this->usageService->deleteBySourceEntity($entity->id(), $entity->getEntityTypeId(), NULL, $entity->getRevisionId());
        break;

      case 'translation':
        $this->usageService->deleteBySourceEntity($entity->id(), $entity->getEntityTypeId(), $entity->language()->getId());
        break;

      case 'default':
        $this->usageService->deleteBySourceEntity($entity->id(), $entity->getEntityTypeId());
        $this->usageService->deleteByTargetEntity($entity->id(), $entity->getEntityTypeId());
        break;

      default:
        // We only accept one of the above mentioned types.
        throw new \InvalidArgumentException('EntityUpdateManager::trackUpdateOnDeletion called with unkown deletion type: ' . $type);
    }
  }

  /**
   * Check if an entity is allowed to be tracked.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return bool
   *   Whether the entity can be tracked or not.
   */
  protected function allowEntityTracking(EntityInterface $entity) {
    if (!($entity instanceof ContentEntityInterface)) {
      return FALSE;
    }

    // Check if entity type is enabled, all entity types are enabled by default.
    $enabled_source_entity_types = $this->config->get('track_enabled_source_entity_types');
    if (is_array($enabled_source_entity_types) && !in_array($entity->getEntityTypeId(), $enabled_source_entity_types, TRUE)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Get the enabled tracking plugins, all plugins are enabled by default.
   *
   * @return \Drupal\entity_usage\EntityUsageTrackInterface[]
   *   The enabled plugin instances.
   */
  protected function getEnabledPlugins() {
    $all_plugin_ids = array_keys($this->trackManager->getDefinitions());
    $enabled_plugins = $this->config->get('track_enabled_plugins');
    $enabled_plugin_ids = is_array($enabled_plugins) ? $enabled_plugins : $all_plugin_ids;

    $plugins = [];
    foreach (array_intersect($all_plugin_ids, $enabled_plugin_ids) as $plugin_id) {
      /** @var EntityUsageTrackInterface $instance */
      $plugins[$plugin_id] = $this->trackManager->createInstance($plugin_id);
    }

    return $plugins;
  }

}
