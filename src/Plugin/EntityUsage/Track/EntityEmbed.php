<?php

namespace Drupal\entity_usage\Plugin\EntityUsage\Track;

use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\entity_usage\EntityUsage;
use Drupal\entity_usage\EntityUsageTrackBase;
use Drupal\entity_usage\EntityUsageTrackInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Component\Utility\Html;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tracks usage of entities related in entity_reference fields.
 *
 * @EntityUsageTrack(
 *   id = "entity_embed",
 *   label = @Translation("Entities embedded with Entity Embed"),
 *   description = @Translation("Tracks usage of entities related when embedded with Entity Embed."),
 * )
 */
class EntityEmbed extends EntityUsageTrackBase implements EntityUsageTrackInterface {

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
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The ModuleHandler service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The EntityRepositoryInterface service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityUsage $usage_service,
    ModuleHandlerInterface $module_handler,
    EntityRepositoryInterface $entity_repository
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $usage_service);
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
      $container->get('module_handler'),
      $container->get('entity.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityCreation(EntityInterface $entity) {
    $referenced_entities_by_field = $this->getEmbeddedEntitiesByField($entity);
    foreach ($referenced_entities_by_field as $field => $embedded_entities) {
      foreach ($embedded_entities as $uuid => $type) {
        // Increment the usage as embedded entity.
        $this->incrementEmbeddedUsage($entity, $type, $uuid);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityUpdate(EntityInterface $entity) {
    // Track entities embedded in text fields.
    $referenced_entities_new = $this->getEmbeddedEntitiesByField($entity, TRUE);
    $referenced_entities_original = $this->getEmbeddedEntitiesByField($entity->original, TRUE);
    foreach (array_diff_key($referenced_entities_new, $referenced_entities_original) as $uuid => $type) {
      // These entities were added.
      $this->incrementEmbeddedUsage($entity, $type, $uuid);
    }
    foreach (array_diff_key($referenced_entities_original, $referenced_entities_new) as $uuid => $type) {
      // These entities were removed.
      $this->decrementEmbeddedUsage($entity, $type, $uuid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function trackOnEntityDeletion(EntityInterface $entity) {
    // Track entities embedded in text fields.
    $referenced_entities_by_field = $this->getEmbeddedEntitiesByField($entity);
    foreach ($referenced_entities_by_field as $field => $embedded_entities) {
      foreach ($embedded_entities as $uuid => $type) {
        // Decrement the usage as embedded entity.
        $this->decrementEmbeddedUsage($entity, $type, $uuid);
      }
    }
  }

  /**
   * Finds all entities embedded (<drupal-entity>) by formatted text fields.
   *
   * @param EntityInterface $entity
   *   An entity object whose fields to analyze.
   * @param bool $omit_field_names
   *   (Optional) Whether the field names should be omitted from the results.
   *   Defaults to FALSE.
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
   *   ]
   *   If the $omit_field_names flag is TRUE, the first level is not present,
   *   and the result array is directly an associative array of uuids as keys
   *   and entity_types as values.
   */
  private function getEmbeddedEntitiesByField(EntityInterface $entity, $omit_field_names = FALSE) {
    $entities = [];

    if ($this->moduleHandler->moduleExists('editor')) {
      $formatted_text_fields = _editor_get_formatted_text_fields($entity);
      foreach ($formatted_text_fields as $formatted_text_field) {
        $text = '';
        $field_items = $entity->get($formatted_text_field);
        foreach ($field_items as $field_item) {
          $text .= $field_item->value;
          if ($field_item->getFieldDefinition()->getType() == 'text_with_summary') {
            $text .= $field_item->summary;
          }
        }
        if ($omit_field_names) {
          $entities += $this->parseEntityUuids($text);
        }
        else {
          $entities[$formatted_text_field] = $this->parseEntityUuids($text);
        }
      }
    }

    return $entities;
  }

  /**
   * Parse an HTML snippet for any embedded entity with a <drupal-entity> tag.
   *
   * @param string $text
   *   The partial (X)HTML snippet to load. Invalid markup will be corrected on
   *   import.
   *
   * @return array
   *   An array of all embedded entities found, where keys are the uuids and the
   *   values are the entity types.
   */
  private function parseEntityUuids($text) {
    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $entities = [];
    foreach ($xpath->query('//drupal-entity[@data-entity-type and @data-entity-uuid]') as $node) {
      // Note that this does not cover 100% of the situations. In the (unlikely
      // but possible) use case where the user embeds the same entity twice in
      // the same field, we are just recording 1 usage for this target entity,
      // when we should record 2. The alternative is to add a lot of complexity
      // to the update logic of our plugin, to deal with all possible
      // combinations in the update scenario.
      // @TODO Re-evaluate if this is worth the effort and overhead.
      $entities[$node->getAttribute('data-entity-uuid')] = $node->getAttribute('data-entity-type');
    }
    return $entities;
  }

  /**
   * Helper method to increment the usage for embedded entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $t_type
   *   The type of the target entity.
   * @param string $uuid
   *   The UUID of the target entity.
   */
  private function incrementEmbeddedUsage(EntityInterface $entity, $t_type, $uuid) {
    $target_entity = $this->entityRepository->loadEntityByUuid($t_type, $uuid);
    $this->usageService->add($target_entity->id(), $t_type, $entity->id(), $entity->getEntityTypeId(), $this->pluginId);
  }

  /**
   * Helper method to decrement the usage for embedded entities.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity object.
   * @param string $t_type
   *   The type of the target entity.
   * @param string $uuid
   *   The UUID of the target entity.
   */
  private function decrementEmbeddedUsage(EntityInterface $entity, $t_type, $uuid) {
    $target_entity = $this->entityRepository->loadEntityByUuid($t_type, $uuid);
    $this->usageService->delete($target_entity->id(), $t_type, $entity->id(), $entity->getEntityTypeId());
  }

}
