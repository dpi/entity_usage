<?php

namespace Drupal\entity_usage\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
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
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if ($entity) {
      $all_usages = $this->entityUsage->listSources($entity);
      if (empty($all_usages)) {
        $build = [
          '#markup' => $this->t('There are no recorded usages for entity of type: @type with id: @id', ['@type' => $entity_type, '@id' => $entity_id]),
        ];
      }
      else {
        $header = [
          $this->t('Entity'),
          $this->t('Type'),
          $this->t('Language'),
          $this->t('Revision ID'),
          $this->t('Field name'),
        ];
        $rows = [];
        foreach ($all_usages as $source_type => $source_ids) {
          // Of those, only loop over existing entities.
          foreach ($this->entityTypeManager->getStorage($source_type)->loadMultiple(array_keys($source_ids)) as $source_id => $source_entity) {
            $entity_usages = $source_ids[$source_id];
            $field_definitions = $this->entityFieldManager->getFieldDefinitions($source_type, $source_entity->bundle());
            foreach ($entity_usages as $usage_details) {
              if ($entity instanceof RevisionableInterface) {
                $source_entity_revision = $this->entityTypeManager->getStorage($source_type)
                  ->loadRevision($usage_details['source_vid']);
                if ($source_entity_revision &&
                  $source_entity->hasTranslation($usage_details['source_langcode']) &&
                  $translation = $source_entity_revision->getTranslation($usage_details['source_langcode'])) {
                  // Only show revision translations if they were affected.
                  /** @var \Drupal\Core\Entity\ContentEntityInterface $translation */
                  if (!$translation->isRevisionTranslationAffected()) {
                    continue;
                  }

                  $link = $this->getSourceEntityLink($translation);
                }
              }
              else {
                $link = $this->getSourceEntityLink($source_entity);
              }
              // If the label is empty it means this usage shouldn't be shown
              // on the UI, just skip this row.
              if (empty($link)) {
                continue;
              }
              $field_label = isset($field_definitions[$usage_details['field_name']]) ? $field_definitions[$usage_details['field_name']]->getLabel() : $this->t('Unknown');
              $rows[] = [
                $link,
                $entity_types[$source_type]->getLabel(),
                $usage_details['source_langcode'],
                $usage_details['source_vid'] ?: '',
                $field_label,
              ];
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
   * @param \Drupal\Core\Entity\EntityInterface $source_entity
   *   The source entity.
   * @param string|null $text
   *   (optional) The link text for the anchor tag as a translated string.
   *   If NULL, it will use the entity's label. Defaults to NULL.
   *
   * @return \Drupal\Core\Link|string|false
   *   A link to the entity, or its non-linked label, in case it was impossible
   *   to correctly build a link. Will return FALSE if this item should not be
   *   shown on the UI (for example when dealing with an orphan paragraph).
   *   Note that Paragraph entities are specially treated. This function will
   *   return the link to its parent entity, relying on the fact that paragraphs
   *   have only one single parent and don't have canonical template.
   */
  protected function getSourceEntityLink(EntityInterface $source_entity, $text = NULL) {
    // Note that $paragraph_entity->label() will return a string of type:
    // "{parent label} > {parent field}", which is actually OK for us.
    $entity_label = $source_entity->access('view label') ? $source_entity->label() : $this->t('- Restricted access -');

    $rel = NULL;
    if ($source_entity->hasLinkTemplate('revision')) {
      $rel = 'revision';
    }
    elseif ($source_entity->hasLinkTemplate('canonical')) {
      $rel = 'canonical';
    }

    if ($rel) {
      $link_text = $text ?: $entity_label;
      // Prevent 404s by exposing the text unlinked if the user has no access
      // to view the entity.
      return $source_entity->access('view') ? $source_entity->toLink($link_text, $rel) : $link_text;
    }

    // Treat paragraph entities in a special manner. Normal paragraph entities
    // only exist in the context of their host (parent) entity. For this reason
    // we will use the link to the parent's entity label instead.
    /** @var \Drupal\paragraphs\ParagraphInterface $source_entity */
    if ($source_entity->getEntityTypeId() == 'paragraph') {
      // Paragraph items may be legitimately orphan, so even if this is a real
      // usage, we will only show it on the UI if its parent is loadable and
      // references the paragraph on its default revision.
      // @todo This could probably be simplified once #2954039 lands.
      $parent = $source_entity->getParentEntity();
      if (empty($parent)) {
        $orphan = TRUE;
      }
      else {
        $parent_field = $source_entity->get('parent_field_name')->value;
        /** @var \Drupal\entity_reference_revisions\EntityReferenceRevisionsFieldItemList $values */
        $values = $parent->{$parent_field};
        if (empty($values->getValue())) {
          // The field is empty or was removed.
          $orphan = TRUE;
        }
        else {
          // There are values in the field. Once paragraphs can have just been
          // re-ordered, there is no other option apart from looping through all
          // values and checking if any of them is this entity.
          $orphan = TRUE;
          foreach ($values as $value) {
            if ($value->entity->id() == $source_entity->id()) {
              $orphan = FALSE;
              break;
            }
          }
        }
      }
      if ($orphan) {
        return FALSE;
      }
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
