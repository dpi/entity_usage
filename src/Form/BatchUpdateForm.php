<?php

namespace Drupal\entity_usage\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to launch batch tracking of existing entities.
 */
class BatchUpdateForm extends FormBase {

  /**
   * The EntityTypeManager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * BatchUpdateForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The EntityTypeManager service.
   */
  public function __construct(EntityTypeManagerInterface $entity_manager) {
    $this->entityTypeManager = $entity_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

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
    $form['description'] = [
      '#markup' => $this->t("This page allows you to delete and re-generate again all entity usage statistics in your system.<br /><br />You may want to check the settings page to fine-tune what entities should be tracked, and other options."),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#button_type' => 'primary',
      '#value' => $this->t('Recreate all entity usage statistics'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Generate a batch to recreate the statistics for all entities.
    // Note that if we force all statistics to be created, there is no need to
    // separate them between source / target cases. If all entities are
    // going to be re-tracked, tracking all of them as source is enough, because
    // there could never be a target without a source.
    $batch = $this->generateBatch();
    batch_set($batch);
  }

  /**
   * Create a batch to process the entity types in bulk.
   *
   * @return array
   *   The batch array.
   */
  public function generateBatch() {
    $operations = [];
    $to_track = \Drupal::config('entity_usage.settings')->get('track_enabled_source_entity_types');
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      // Only look for content entities that are marked for tracking on the
      // settings form.
      if ($entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface') && (empty($to_track) || in_array($entity_type_id, $to_track, TRUE))) {
        $operations[] = ['Drupal\entity_usage\Form\BatchUpdateForm::updateSourcesBatchWorker', [$entity_type_id]];
      }
    }

    $batch = [
      'operations' => $operations,
      'finished' => 'Drupal\entity_usage\Form\BatchUpdateForm::batchFinished',
      'title' => $this->t('Updating entity usage statistics.'),
      'progress_message' => $this->t('Processed @current of @total entity types.'),
      'error_message' => $this->t('This batch encountered an error.'),
    ];

    return $batch;
  }

  /**
   * Batch operation worker for recreating statistics for source entities.
   *
   * @param string $entity_type_id
   *   The entity type id, for example 'node'.
   * @param array $context
   *   The context array.
   */
  public static function updateSourcesBatchWorker($entity_type_id, array &$context) {
    $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
    $entity_type_key = $entity_type->getKey('id');

    if (empty($context['sandbox']['total'])) {
      // Delete current usage statistics for these entities.
      \Drupal::service('entity_usage.usage')->bulkDeleteSources($entity_type_id);

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = -1;
      $context['sandbox']['total'] = (int) $entity_storage->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }

    $entity_ids = $entity_storage->getQuery()
      ->condition($entity_type_key, $context['sandbox']['current_id'], '>')
      ->range(0, 10)
      ->accessCheck(FALSE)
      ->sort($entity_type_key)
      ->execute();

    $entities = $entity_storage->loadMultiple($entity_ids);
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    foreach ($entities as $entity) {
      if ($entity->getEntityType()->isRevisionable()) {
        // Track all revisions and translations of the source entity. Sources are
        // tracked as if they were new entities.
        $result = $entity_storage->getQuery()->allRevisions()
          ->condition($entity->getEntityType()->getKey('id'), $entity->id())
          ->sort($entity->getEntityType()->getKey('revision'), 'DESC')
          ->execute();
        $revision_ids = array_keys($result);

        foreach ($revision_ids as $revision_id) {
          /** @var \Drupal\Core\Entity\ContentEntityInterface $entity_revision */
          if (!$entity_revision = $entity_storage->loadRevision($revision_id)) {
            continue;
          }

          foreach ($entity_revision->getTranslationLanguages() as $translation_language) {
            /** @var \Drupal\Core\Entity\ContentEntityInterface $translation */
            $translation = $entity_revision->getTranslation($translation_language->getId());

            if (!$translation->isRevisionTranslationAffected()) {
              continue;
            }

            \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($translation);
          }
        }
      }
      else {
        // Sources are tracked as if they were new entities.
        \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($entity);
        // Track all translations of the entity.
        foreach ($entity->getTranslationLanguages(FALSE) as $translation_language) {
          $translation = $entity->getTranslation($translation_language->getId());

          if (!$translation->isRevisionTranslationAffected()) {
            continue;
          }

          \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($translation);
        }
      }

      $context['sandbox']['progress']++;
      $context['sandbox']['current_id'] = $entity->id();
      $context['results'][] = $entity_type_id . ':' . $entity->id();
    }

    if ($context['sandbox']['progress'] < $context['sandbox']['total']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }

    $context['message'] = t('Updating entity usage for @entity_type: @current of @total', [
      '@entity_type' => $entity_type_id,
      '@current' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Finish callback for our batch processing.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   The results array.
   * @param array $operations
   *   The operations array.
   */
  public static function batchFinished($success, array $results, array $operations) {
    if ($success) {
      drupal_set_message(t('Recreated entity usage for @count entities.', ['@count' => count($results)]));
    }
    else {
      // An error occurred.
      // $operations contains the operations that remained unprocessed.
      $error_operation = reset($operations);
      drupal_set_message(
        t('An error occurred while processing @operation with arguments : @args',
          [
            '@operation' => $error_operation[0],
            '@args' => print_r($error_operation[0], TRUE),
          ]
        )
      );
    }
  }

}
