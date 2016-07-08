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
    $form['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => t('Entity types'),
      '#options' => $types,
    ];
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => 'Go',
    );

    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity_types = array_filter($form_state->getValue('entity_types'));

    // Delete current usage statistics for these entities.
    foreach ($entity_types as $type) {
      /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
      $entity_usage = \Drupal::service('entity_usage.usage');
      $entity_usage->bulkDelete($type);
    }
    // Generate a batch to re-create the statistics.
    $batch = $this->generateBatch($entity_types);
    batch_set($batch);
  }

  /**
   * Create a batch to process the entities in bulk.
   *
   * @param array $entity_types
   *   The types we are interested in.
   *
   * @return array
   *   The batch array.
   */
  public function generateBatch(array $entity_types) {
    $operations = [];
    // Load all entities of the selected types.
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
