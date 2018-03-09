<?php

namespace Drupal\entity_usage\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\entity_usage\EntityUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for our pages.
 */
class ListUsageController extends ControllerBase {


  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The EntityUsage service.
   *
   * @var \Drupal\entity_usage\EntityUsageInterface
   */
  protected $entityUsage;

  /**
   * ListUsageController constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\entity_usage\EntityUsageInterface $entity_usage
   *   The EntityUsage service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, EntityUsageInterface $entity_usage) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityUsage = $entity_usage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_usage.usage')
    );
  }

  /**
   * Lists the usage of a given entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return array
   *   The page build to be rendered.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   */
  public function listUsagePage($entity_type, $entity_id) {
    $entity_types = $this->entityTypeManager->getDefinitions();

    if (!is_string($entity_type) || !is_numeric($entity_id) || !array_key_exists($entity_type, $entity_types)) {
      throw new NotFoundHttpException();
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if ($entity) {
      $usages = $this->entityUsage->listSources($entity, TRUE);
      if (empty($usages)) {
        $build = [
          '#markup' => $this->t('There are no recorded usages for entity of type: @type with id: @id', ['@type' => $entity_type, '@id' => $entity_id]),
        ];
      }
      else {
        $header = [
          $this->t('Entity'),
          $this->t('Type'),
          $this->t('Method'),
          $this->t('Field'),
          $this->t('Count'),
        ];
        $rows = [];
        foreach ($usages as $method => $method_usages) {
          foreach ($method_usages as $source_type => $source_type_usages) {
            foreach ($source_type_usages as $source_id => $field_names) {
              $source_entity = $this->entityTypeManager->getStorage($source_type)->load($source_id);
              if ($source_entity) {
                $field_definitions = $this->entityFieldManager->getFieldDefinitions($source_entity->getEntityTypeId(), $source_entity->bundle());
                foreach ($field_names as $field_name => $count) {
                  $link = $this->getSourceEntityLink($source_entity);
                  $field_label = isset($field_definitions[$field_name]) ? $field_definitions[$field_name]->getLabel() : $this->t('Unknown');
                  $rows[] = [
                    $link,
                    $entity_types[$source_type]->getLabel(),
                    $method,
                    $field_label,
                    $count,
                  ];
                }
              }
            }
          }
        }
        $build = [
          '#theme' => 'table',
          '#rows' => $rows,
          '#header' => $header,
        ];
      }
    }
    else {
      // Non-existing entity in database.
      $build = [
        '#markup' => $this->t('Could not find the entity of type: @type with id: @id', ['@type' => $entity_type, '@id' => $entity_id]),
      ];
    }
    return $build;
  }

  /**
   * Title page callback.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int $entity_id
   *   The entity id.
   *
   * @return string
   *   The title to be used on this page.
   */
  public function getTitle($entity_type, $entity_id) {
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if ($entity) {
      return $this->t('Entity usage information for %entity_label', ['%entity_label' => $entity->label()]);
    }
    return $this->t('Entity Usage List');
  }

  /**
   * Retrieve a link to the source entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $source_entity
   *   The source entity.
   * @param string|null $text
   *   (optional) The link text for the anchor tag as a translated string.
   *   If NULL, it will use the entity's label. Defaults to NULL.
   *
   * @return \Drupal\Core\Link|string
   *   A link to the entity, or its non-linked label, in case it was impossible
   *   to correctly build a link.
   *   Note that Paragraph entities are specially treated. This function will
   *   return the link to its parent entity, relying on the fact that paragraphs
   *   have only one single parent and don't have canonical template.
   */
  protected function getSourceEntityLink(ContentEntityInterface $source_entity, $text = NULL) {
    $entity_label = $source_entity->access('view label') ? $source_entity->label() : $this->t('- Restricted access -');
    if ($source_entity->hasLinkTemplate('canonical')) {
      $link_text = $text ?: $entity_label;
      // Prevent 404s by exposing the text unlinked if the user has no access
      // to view the entity.
      return $source_entity->access('view') ? $source_entity->toLink($link_text) : $link_text;
    }

    // Treat paragraph entities in a special manner. Once the current paragraphs
    // implementation does not support reusing paragraphs, it is safe to
    // consider that each paragraph entity is attached to only one parent
    // entity. For this reason we will use the link to the parent's entity,
    // adding a note that the parent uses this entity through a paragraph.
    // @see #2414865 and related issues for more info.
    if ($source_entity->getEntityTypeId() == 'paragraph' && $parent = $source_entity->getParentEntity()) {
      return $this->getSourceEntityLink($parent, $entity_label);
    }

    // As a fallback just return a non-linked label.
    return $entity_label;
  }

  /**
   * Checks access based on whether the user can view the current entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int $entity_id
   *   The entity ID.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkAccess($entity_type, $entity_id) {
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if (!$entity || !$entity->access('view')) {
      return AccessResult::forbidden();
    }
    return AccessResult::allowed();
  }

}
