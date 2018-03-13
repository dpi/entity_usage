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
    $all_entity_types = $this->entityTypeManager->getDefinitions();

    // Filter the entity types.
    /** @var \Drupal\Core\Entity\ContentEntityTypeInterface[] $entity_type_options */
    $entity_type_options = [];
    foreach ($all_entity_types as $entity_type) {
      if (!($entity_type instanceof ContentEntityTypeInterface) || !$entity_type->hasLinkTemplate('canonical')) {
        continue;
      }
      $entity_type_options[$entity_type->id()] = $entity_type->getLabel();
    }

    // Tabs configuration.
    $form['local_task_enabled_entity_types'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Enabled local tasks'),
      '#description' => $this->t('Check in which entity types there should be a tab (local task) linking to the usage page.'),
      '#tree' => TRUE,
    ];
    $form['local_task_enabled_entity_types']['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Local task entity types'),
      '#options' => $entity_type_options,
      '#default_value' => $config->get('local_task_enabled_entity_types') ?: [],
    ];

    // Entity types (source).
    $form['track_enabled_source_entity_types'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Enabled source entity types'),
      '#description' => $this->t('Check which entity types should be tracked when source.'),
      '#tree' => TRUE,
    ];
    $form['track_enabled_source_entity_types']['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Source entity types'),
      '#options' => $entity_type_options,
      '#default_value' => $config->get('track_enabled_source_entity_types') ?: array_keys($entity_type_options),
      '#required' => TRUE,
    ];

    // Entity types (target).
    $form['track_enabled_target_entity_types'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Enabled target entity types'),
      '#description' => $this->t('Check which entity types should be tracked when target.'),
      '#tree' => TRUE,
    ];
    $form['track_enabled_target_entity_types']['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Target entity types'),
      '#options' => $entity_type_options,
      '#default_value' => $config->get('track_enabled_target_entity_types') ?: array_keys($entity_type_options),
      '#required' => TRUE,
    ];

    // Plugins to enable.
    $form['track_enabled_plugins'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#title' => $this->t('Enabled tracking plugins'),
      '#description' => $this->t('The following plugins were found in the system and can provide usage tracking. Check all plugins that should be active.'),
      '#tree' => TRUE,
    ];
    $plugins = $this->usageTrackManager->getDefinitions();
    $plugin_options = [];
    foreach ($plugins as $plugin) {
      $plugin_options[$plugin['id']] = $plugin['label'];
    }
    $form['track_enabled_plugins']['plugins'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Tracking plugins'),
      '#options' => $plugin_options,
      '#default_value' => $config->get('track_enabled_plugins') ?: array_keys($plugin_options),
      '#required' => TRUE,
    ];
    // Add descriptions to all plugins that defined it.
    foreach ($plugins as $plugin) {
      if (!empty($plugin['description'])) {
        $form['track_enabled_plugins']['plugins'][$plugin['id']]['#description'] = $plugin['description'];
      }
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
    $form_state->cleanValues();

    $config = $this->config('entity_usage.settings');
    $config->set('track_enabled_base_fields', (bool) $form_state->getValue('track_enabled_base_fields'))
      ->set('track_enabled_source_entity_types', $form_state->getValue('track_enabled_source_entity_types')['entity_types'])
      ->set('track_enabled_target_entity_types', $form_state->getValue('track_enabled_target_entity_types')['entity_types'])
      ->set('track_enabled_plugins', $form_state->getValue('track_enabled_plugins')['plugins'])
      ->set('local_task_enabled_entity_types', $form_state->getValue('local_task_enabled_entity_types')['entity_types'])
      ->save();

    $this->routerBuilder->rebuild();
    $this->cacheRender->invalidateAll();

    parent::submitForm($form, $form_state);
  }

}
