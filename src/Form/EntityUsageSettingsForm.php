<?php

namespace Drupal\entity_usage\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\entity_usage\EntityUsageTrackManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to configure entity_usage settings.
 */
class EntityUsageSettingsForm extends ConfigFormBase {

  /**
   * The Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The router builder.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routerBuilder;

  /**
   * The Cache Render.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheRender;

  /**
   * The Entity Usage Track Manager service.
   *
   * @var \Drupal\entity_usage\EntityUsageTrackManager
   */
  protected $usageTrackManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, RouteBuilderInterface $router_builder, CacheBackendInterface $cache_render, EntityUsageTrackManager $usage_track_manager) {
    parent::__construct($config_factory);
    $this->entityTypeManager = $entity_type_manager;
    $this->routerBuilder = $router_builder;
    $this->cacheRender = $cache_render;
    $this->usageTrackManager = $usage_track_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('router.builder'),
      $container->get('cache.render'),
      $container->get('plugin.manager.entity_usage.track')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_usage_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['entity_usage.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('entity_usage.settings');
    $entity_types = $this->entityTypeManager->getDefinitions();
    // Filter the entity types.
    /** @var \Drupal\Core\Entity\ContentEntityTypeInterface[] $entity_types */
    $entity_types = array_filter(array_map(function ($entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface) {
        return $entity_type;
      }
      return NULL;
    }, $entity_types));

    // Tabs configuration.
    $form['local_task_enabled_entity_types'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Local tasks'),
      '#description' => $this->t('Check in which entity types there should be a tab (local task) linking to the usage page.'),
      '#tree' => TRUE,
    ];

    $local_tabs_default = $config->get('local_task_enabled_entity_types') ?: [];
    foreach ($entity_types as $entity_type_id => $entity_type) {
      if ($entity_type->hasLinkTemplate('canonical')) {
        $form['local_task_enabled_entity_types'][$entity_type_id] = [
          '#type' => 'checkbox',
          '#title' => $entity_type->getLabel(),
          '#default_value' => in_array($entity_type_id, $local_tabs_default, TRUE),
        ];
      }
    }

    // Entity types (source).
    $form['track_enabled_source_entity_types'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Entity types (source)'),
      '#description' => $this->t('Check which entity types should be tracked when source.'),
      '#tree' => TRUE,
    ];
    $source_default = $config->get('track_enabled_source_entity_types') ?: array_keys($entity_types);
    foreach ($entity_types as $entity_type_id => $entity_type) {
      $form['track_enabled_source_entity_types'][$entity_type_id] = [
        '#type' => 'checkbox',
        '#title' => $entity_type->getLabel(),
        '#default_value' => in_array($entity_type_id, $source_default, TRUE),
      ];
    }

    // Entity types (target).
    $form['track_enabled_target_entity_types'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Entity types (target)'),
      '#description' => $this->t('Check which entity types should be tracked when target.'),
      '#tree' => TRUE,
    ];

    $target_default = $config->get('track_enabled_target_entity_types') ?: array_keys($entity_types);
    foreach ($entity_types as $entity_type_id => $entity_type) {
      $form['track_enabled_target_entity_types'][$entity_type_id] = [
        '#type' => 'checkbox',
        '#title' => $entity_type->getLabel(),
        '#default_value' => in_array($entity_type_id, $target_default, TRUE),
      ];
    }

    // Plugins to enable.
    $form['track_enabled_plugins'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Enabled tracking plugins'),
      '#description' => $this->t('The following plugins were found in the system and can provide usage tracking. Check all plugins that should be active.'),
      '#tree' => TRUE,
    ];

    $plugins = $this->usageTrackManager->getDefinitions();
    $plugins_default = $config->get('track_enabled_plugins') ?: array_keys($plugins);
    foreach ($plugins as $plugin_id => $plugin) {
      $form['track_enabled_plugins'][$plugin_id] = [
        '#type' => 'checkbox',
        '#title' => $plugin['label'],
        '#description' => !empty($plugin['description']) ? $plugin['description'] : NULL,
        '#default_value' => in_array($plugin_id, $plugins_default, TRUE),
      ];
    }

    // Miscellaneous settings.
    $form['generic_settings'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Generic'),
    ];
    $form['generic_settings']['track_enabled_base_fields'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track referencing basefields'),
      '#description' => $this->t('If enabled, relationships generated through non-configurable fields (basefields) will also be tracked.'),
      '#default_value' => (bool) $config->get('track_enabled_base_fields'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('entity_usage.settings');

    $form_state->cleanValues();

    foreach ($form_state->getValues() as $key => $value) {
      switch ($key) {
        case 'local_task_enabled_entity_types':
        case 'track_enabled_source_entity_types':
        case 'track_enabled_target_entity_types':
        case 'track_enabled_plugins':
          $enabled_entity_types = [];
          foreach ($value as $entity_type_id => $enabled) {
            if ($enabled) {
              $enabled_entity_types[] = $entity_type_id;
            }
          }
          $value = $enabled_entity_types;
          break;

        case 'track_enabled_base_fields':
          $value = (bool) $value;
          break;
      }
      $config->set($key, $value);
    }
    $config->save();

    $this->routerBuilder->rebuild();
    $this->cacheRender->invalidateAll();

    parent::submitForm($form, $form_state);
  }

}
