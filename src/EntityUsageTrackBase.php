<?php

namespace Drupal\entity_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base implementation for track plugins.
 */
abstract class EntityUsageTrackBase extends PluginBase implements EntityUsageTrackInterface, ContainerFactoryPluginInterface {

  /**
   * The usage tracking service.
   *
   * @var \Drupal\entity_usage\EntityUsage
   */
  protected $usageService;

  /**
   * Entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The Entity Update config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityUsage $usage_service,
    EntityFieldManagerInterface $entity_field_manager,
    ConfigFactoryInterface $config_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configuration += $this->defaultConfiguration();
    $this->usageService = $usage_service;
    $this->entityFieldManager = $entity_field_manager;
    $this->config = $config_factory->get('entity_usage.settings');
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
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return $this->pluginDefinition['id'];
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->pluginDefinition['description'];
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityCreation(ContentEntityInterface $source_entity) {

  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityUpdate(ContentEntityInterface $source_entity) {

  }

  /**
   * {@inheritdoc}
   */
  public function getReferencingFields(ContentEntityInterface $source_entity, array $field_types) {
    $source_entity_type_id = $source_entity->getEntityTypeId();

    $all_fields_on_bundle = $this->entityFieldManager->getFieldDefinitions($source_entity_type_id, $source_entity->bundle());

    $referencing_fields_on_entity_type = [[]];
    foreach ($field_types as $field_type) {
      $fields_of_type = $this->entityFieldManager->getFieldMapByFieldType($field_type);
      if (!empty($fields_of_type[$source_entity_type_id])) {
        $referencing_fields_on_entity_type[] = $fields_of_type[$source_entity_type_id];
      }
    }
    $referencing_fields_on_entity_type = array_merge(...$referencing_fields_on_entity_type);
    $referencing_fields_on_bundle = array_intersect_key($all_fields_on_bundle, $referencing_fields_on_entity_type);

    if (!$this->config->get('track_enabled_base_fields')) {
      $basefields = $this->entityFieldManager->getBaseFieldDefinitions($source_entity_type_id);
      $referencing_fields_on_bundle = array_diff_key($referencing_fields_on_bundle, $basefields);
    }

    return $referencing_fields_on_bundle;
  }

}
