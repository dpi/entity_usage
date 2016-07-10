<?php

namespace Drupal\entity_usage\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\entity_usage\EntityUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
      $this->entityUsage->bulkDeleteHosts($type);
    }
    $types['hosts'] = $host_entity_types;

    $target_entity_types = array_filter($form_state->getValue('target_entity_types'));
    // Delete current usage statistics for these entities.
    foreach ($target_entity_types as $type) {
      $this->entityUsage->bulkDeleteTargets($type);
    }
    $types['targets'] = $target_entity_types;

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
      $entities = $this->entityTypeManager->getStorage($type)->loadMultiple();
      foreach ($entities as $id => $entity) {
        $operations[] = [
          'entity_usage_update_hosts_batch_worker',
          [
            $entity,
            $this->t('Host operation in @name', ['@name' => $entity->getEntityTypeId() . ':' . $entity->id()]),
          ],
        ];
      }
    }

    // Generation for target entity types:
    $entity_types = $types['targets'];

    $this->populateTargetBatchOperations($entity_types, $operations);

    $batch = [
      'operations' => $operations,
      'finished' => 'entity_usage_update_batch_fihished',
      'title' => $this->t('Processing batch update.'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('This batch encountered an error.'),
    ];

    return $batch;
  }

  /**
   * Populate a batch operations array for treating target entities types.
   *
   * This will iterate over all entity_reference fields on the system (in all
   * entities) checking whether the 'target_type' defined in each of them
   * matches any of the $entity_types received. If there is a match, then there
   * might be a set of entities that need tracking, and this information is then
   * packed into the operations array to be trated by the batch worker.
   * // @TODO There must be a better way of retrieving this info! ^^
   *
   * @param array $entity_types
   *   An array of entity types that need to have statistics re-created.
   * @param array $operations
   *   The $operations array, passed-in by reference.
   */
  private function populateTargetBatchOperations(array $entity_types, array &$operations) {
    // @TODO There must be a better way of doing this!
    $all_entities = $this->entityTypeManager->getDefinitions();
    $content_entities = array_filter($all_entities, function ($v) {
      return $v->isSubclassOf('\Drupal\Core\Entity\ContentEntityBase');
    });
    foreach (array_keys($content_entities) as $type) {
      $entityref_fields_on_this_entity_type = $this->entityFieldManager->getFieldMapByFieldType('entity_reference')[$type];
      // Clean out basefields.
      $basefields = $this->entityFieldManager->getBaseFieldDefinitions($type);
      $entityref_on_this_entity = array_diff_key($entityref_fields_on_this_entity_type, $basefields);
      $field_names = [];
      if (!empty($entityref_on_this_entity)) {
        $field_names = array_keys($entityref_on_this_entity);
      }
      $bundles_on_this_type = $this->bundleInfo->getBundleInfo($type);
      foreach ($bundles_on_this_type as $bundle => $bundle_arr) {
        $definitions = $this->entityFieldManager->getFieldDefinitions($type, $bundle);
        $entityref_fields_on_this_bundle = array_intersect($field_names, array_keys($definitions));
        foreach ($entityref_fields_on_this_bundle as $field_name) {
          $target_entity_type = $definitions[$field_name]->getSetting('target_type');
          if (in_array($target_entity_type, $entity_types)) {
            // If there is a match we need to re-track these entities.
            $referencing_entity_type = $type;
            $operations[] = [
              'entity_usage_update_targets_batch_worker',
              [
                $target_entity_type,
                $referencing_entity_type,
                $field_name,
                '(' . $target_entity_type . ':' . $referencing_entity_type . ':' . $field_name . ')',
              ],
            ];
          }
        }
      }
    }
  }

}
