<?php

namespace Drupal\entity_usage;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Defines the interface for entity_usage track methods.
 *
 * Track plugins use any arbitrary method to link two entities together.
 * Examples include:
 *
 * - Entities related through an entity_reference field are tracked using the
 *   "entity_reference" method.
 * - Entities embedded into other entities are tracked using the "embed" method.
 */
interface EntityUsageTrackInterface extends PluginInspectionInterface {

  /**
   * Returns the tracking method unique id.
   *
   * @return string
   *   The tracking method id.
   */
  public function getId();

  /**
   * Returns the tracking method label.
   *
   * @return string
   *   The tracking method label.
   */
  public function getLabel();

  /**
   * Returns the tracking method description.
   *
   * @return string
   *   The tracking method description.
   */
  public function getDescription();

  /**
   * Track usage updates on the creation of entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity.
   */
  public function trackOnEntityCreation(ContentEntityInterface $source_entity);

  /**
   * Track usage updates on the edition of entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity.
   */
  public function trackOnEntityUpdate(ContentEntityInterface $source_entity);

  /**
   * Track usage updates on the deletion of entities.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity.
   */
  public function trackOnEntityDeletion(ContentEntityInterface $source_entity);

  /**
   * Retrieve fields of the given types on an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity object.
   * @param string[] $field_types
   *   A list of field types.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   *   An array of fields that could reference to other content entities.
   */
  public function getReferencingFields(ContentEntityInterface $source_entity, array $field_types);

}
