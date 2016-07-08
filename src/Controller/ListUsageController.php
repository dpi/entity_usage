<?php

namespace Drupal\entity_usage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller routines for page example routes.
 */
class ListUsageController extends ControllerBase {

  /**
   * Lists the usage of a given entity.
   *
   * @param string $type
   *   The entity type.
   * @param int $id
   *   The entity ID.
   */
  public function listUsagePage($type, $id) {
    $entity_types = array_keys(\Drupal::entityTypeManager()->getDefinitions());
    if (!is_string($type) || !is_numeric($id) || !in_array($type, $entity_types)) {
      throw new NotFoundHttpException();
    }
    $entity = \Drupal::entityTypeManager()->getStorage($type)->load($id);
    if ($entity) {
      $usages = \Drupal::service('entity_usage.usage')->listUsage($entity);
      if (empty($usages)) {
        // Entity exists but not used.
        $build = [
          '#markup' => t('There are no recorded usages for entity of type: @type with id: @id', ['@type' => $type, '@id' => $id]),
        ];
      }
      else {
        // Entity is being used.
        $header = [
          t('Referencing entity'),
          t('Referencing entity type'),
          t('Count'),
        ];
        $rows = [];
        foreach ($usages as $re_type => $type_usages) {
          foreach ($type_usages as $re_id => $count) {
            $referencing_entity = \Drupal::entityTypeManager()->getStorage($re_type)->load($re_id);
            if ($referencing_entity) {
              $rows[] = [
                $referencing_entity->toLink(),
                $re_type,
                $count,
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
        '#markup' => t('Could not find the entity of type: @type with id: @id', ['@type' => $type, '@id' => $id]),
      ];
    }
    return $build;
  }

  /**
   * Title page callback.
   *
   * @param string $type
   *   The entity type.
   * @param int $id
   *   The entity id.
   *
   * @return string
   *   The title to be used on this page.
   */
  public function getTitle($type, $id) {
    $entity = \Drupal::entityTypeManager()->getStorage($type)->load($id);
    if ($entity) {
      return t('Entity usage information for @entity_label', ['@entity_label' => $entity->label()]);
    }
    else {
      return t('Entity Usage List');
    }
  }

}
