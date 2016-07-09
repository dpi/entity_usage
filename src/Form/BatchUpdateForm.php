<?php

namespace Drupal\entity_usage\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form to launch batch tracking of existing entities.
 */
class BatchUpdateForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'entity_update_batch_update_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $entity_types = \Drupal::entityTypeManager()->getDefinitions();
    $types = [];
    foreach ($entity_types as $type => $entity_type) {
      // Only look for content entities.
      if ($entity_type->isSubclassOf('\Drupal\Core\Entity\ContentEntityBase')) {
        $types[$type] = $type;
      }
    }

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => t("This form allows you to reset and track again all entity usages in your system.<br /> It may be useful if you want to have available the information about the relationships between entities before installing the module.<br /><b>Be aware though that using this operation will DELETE all your current usage data for the selected entity types!</b>"),
    ];
    $form['host_entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => t('Re-create usage tracking for these entities when used REFERENCING other entities (hosts).'),
      '#options' => $types,
    ];
    $form['target_entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => t('Re-create usage tracking for these entities when used REFERENCED by other entities (targets).'),
      '#options' => $types,
      '#disabled' => TRUE,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Go',
    ];

    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $types['hosts'] = [];
    $types['targets'] = [];

    $host_entity_types = array_filter($form_state->getValue('host_entity_types'));

    // Delete current usage statistics for these entities.
    foreach ($host_entity_types as $type) {
      /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
      $entity_usage = \Drupal::service('entity_usage.usage');
      $entity_usage->bulkDeleteHosts($type);
    }

    $types['hosts'] = $host_entity_types;

    // @TODO Treat target entity types.

    // Generate a batch to re-create the statistics.
    $batch = $this->generateBatch($types);
    batch_set($batch);
  }

  /**
   * Create a batch to process the entities in bulk.
   *
   * @param array $types
   *   An array containing two arrays, keyed each by 'hosts' or 'targets'. Each
   *   sub-array is an array of entity_types to be trated in the corresponding
   *   condition (host or target) as defined in its first level key.
   *
   * @return array
   *   The batch array.
   */
  public function generateBatch(array $types) {
    $operations = [];

    // Generation for host entity types:
    $entity_types = $types['hosts'];

    foreach ($entity_types as $type) {
      $entities = \Drupal::entityTypeManager()->getStorage($type)->loadMultiple();
      foreach ($entities as $id => $entity) {
        $operations[] = [
          'entity_usage_update_batch_worker',
          [
            $entity,
            $this->t('Operation in @name', ['@name' => $entity->getEntityTypeId() . ':' . $entity->id()]),
          ],
        ];
      }
    }

    // Generation for target entity types:
    $entity_types = $types['targets'];

    foreach ($entity_types as $type) {
      // @TODO implement me.
    }

    $batch = [
      'operations' => $operations,
      'finished' => 'entity_usage_update_batch_fihished',
      'title' => $this->t('Processing batch update.'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('This batch encountered an error.'),
    ];

    return $batch;
  }

}
