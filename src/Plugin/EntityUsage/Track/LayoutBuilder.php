<?php

namespace Drupal\entity_usage\Plugin\EntityUsage\Track;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\entity_usage\EntityUsage;
use Drupal\entity_usage\EntityUsageTrackBase;
use Drupal\layout_builder\Plugin\Field\FieldType\LayoutSectionItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tracks usage of entities related in Layout Builder layouts.
 *
 * @EntityUsageTrack(
 *   id = "layout_builder",
 *   label = @Translation("Layout builder"),
 *   description = @Translation("Tracks relationships in layout builder layouts."),
 *   field_types = {"layout_section"},
 * )
 */
class LayoutBuilder extends EntityUsageTrackBase {

  /**
   * Block manager.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

  /**
   * Constructs a new LayoutBuilder plugin instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\entity_usage\EntityUsage $usage_service
   *   The usage tracking service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The EntityFieldManager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The EntityRepositoryInterface service.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   Block manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityUsage $usage_service, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, ConfigFactoryInterface $config_factory, EntityRepositoryInterface $entity_repository, BlockManagerInterface $blockManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $usage_service, $entity_type_manager, $entity_field_manager, $config_factory, $entity_repository);
    $this->blockManager = $blockManager;
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
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('entity.repository'),
      $container->get('plugin.manager.block')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntities(FieldItemInterface $item) {
    assert($item instanceof LayoutSectionItem);

    $blockContentRevisionIds = [];

    /** @var \Drupal\layout_builder\Plugin\DataType\SectionData $value */
    foreach ($item as $value) {
      /** @var \Drupal\layout_builder\Section $section */
      $section = $value->getValue();
      foreach ($section->getComponents() as $component) {
        $def = $this->blockManager->getDefinition($component->getPluginId());
        if ($def['id'] === 'inline_block') {
          $configuration = $component->toArray()['configuration'];
          $blockContentRevisionIds[] = $configuration['block_revision_id'];
        }
      }
    }

    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $blockContentStorage */
    $blockContentStorage = $this->entityTypeManager->getStorage('block_content');

    /** @var \Drupal\block_content\BlockContentInterface[] $blockContent */
    $ids = $blockContentStorage->getQuery()
      ->condition($blockContentStorage->getEntityType()->getKey('revision'), $blockContentRevisionIds, 'IN')
      ->execute();

    return array_map(function (string $id): string {
      return 'block_content|' . $id;
    }, $ids);
  }

}
