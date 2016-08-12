<?php

namespace Drupal\entity_usage\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\entity_usage\EntityUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Form to launch batch tracking of existing entities.
 */
class BatchUpdateForm extends FormBase {

  /**
   * The EntityFieldManager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The EntityTypeManager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The EntityUsage service.
   *
   * @var \Drupal\entity_usage\EntityUsageInterface
   */
  protected $entityUsage;

  /**
   * The EntityTypeBundleInfo service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * BatchUpdateForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $field_manager
   *   The EntityFieldManager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_manager
   *   The EntityTypeManager service.
   * @param \Drupal\entity_usage\EntityUsageInterface $entity_usage
   *   The EntityUsage service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info
   *   The EntityTypeBundleInfo service.
   */
  public function __construct(
      EntityFieldManagerInterface $field_manager,
      EntityTypeManagerInterface $entity_manager,
      EntityUsageInterface $entity_usage,
      EntityTypeBundleInfoInterface $bundle_info
  ) {
    $this->entityFieldManager = $field_manager;
    $this->entityTypeManager = $entity_manager;
    $this->entityUsage = $entity_usage;
    $this->bundleInfo = $bundle_info;
  }

  /**
   * Plugin create function.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container for injecting our services.
   *
   * @return static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity_usage.usage'),
      $container->get('entity_type.bundle.info')
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

    $entity_types = $this->entityTypeManager->getDefinitions();
    $types = [];
    foreach ($entity_types as $type => $entity_type) {
      // Only look for content entities.
      if ($entity_type->isSubclassOf('\Drupal\Core\Entity\ContentEntityInterface')) {
        $types[$type] = $type;
      }
    }

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => t("This form allows you to reset and track again all entity usages in your system.<br /> It may be useful if you want to have available the information about the relationships between entities before installing the module.<br /><b>Be aware though that using this operation will delete all tracked statistics and re-create everything again.</b>"),
    ];
    $form['host_entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => t('Delete and re-create all usage statistics for these entity types:'),
      '#options' => $types,
      '#default_value' => $types,
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

    $host_entity_types = array_filter($form_state->getValue('host_entity_types'));
    // Delete current usage statistics for these entities.
    foreach ($host_entity_types as $type) {
      $this->entityUsage->bulkDeleteHosts($type);
    }

    // Generate a batch to re-create the statistics for all entities.
    // Note that if we force all statistics to be created, there is no need to
    // separate them between host / target cases. If all entities are going to
    // be re-tracked, tracking all of them as hosts is enough, because there
    // could never be a target without host.
    $batch = $this->generateBatch($host_entity_types);
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

    foreach ($types as $type) {
      $entities = $this->entityTypeManager->getStorage($type)->loadMultiple();
      foreach ($entities as $id => $entity) {
        $operations[] = [
          'Drupal\entity_usage\Form\BatchUpdateForm::updateHostsBatchWorker',
          [
            $entity,
            $this->t('Host operation in @name', ['@name' => $entity->getEntityTypeId() . ':' . $entity->id()]),
          ],
        ];
      }
    }

    $batch = [
      'operations' => $operations,
      'finished' => 'Drupal\entity_usage\Form\BatchUpdateForm::batchFinished',
      'title' => $this->t('Processing batch update.'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('This batch encountered an error.'),
    ];

    return $batch;
  }

  /**
   * Batch operation worker for re-creating statistics for entities when host.
   *
   * @param EntityInterface $entity
   *   The entity object.
   * @param string $operation_details
   *   Operation details information.
   * @param array $context
   *   The context array.
   */
  public static function updateHostsBatchWorker(EntityInterface $entity, $operation_details, &$context) {

    // Hosts are tracked as if they were new entities.
    \Drupal::service('entity_usage.entity_update_manager')->trackUpdateOnCreation($entity);

    $context['results'][] = $entity->getEntityTypeId() . ':' . $entity->id();

    $context['message'] = t('Running batch for entity @details', ['@details' => $operation_details]);
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
  public static function batchFinished($success, $results, $operations) {
    if ($success) {
      drupal_set_message(t('@count operations processed.', ['@count' => count($results)]));
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
