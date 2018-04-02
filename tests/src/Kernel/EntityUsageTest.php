<?php

namespace Drupal\Tests\entity_usage\Kernel;

use Drupal\Core\Entity\RevisionableInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_usage\Events\EntityUsageEvent;
use Drupal\entity_usage\Events\Events;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Tests the basic API operations of our tracking service.
 *
 * @group entity_usage
 *
 * @package Drupal\Tests\entity_usage\Kernel
 */
class EntityUsageTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = ['entity_test', 'entity_usage'];

  /**
   * The entity type used in this test.
   *
   * @var string
   */
  protected $entityType = 'entity_test';

  /**
   * The bundle used in this test.
   *
   * @var string
   */
  protected $bundle = 'entity_test';

  /**
   * Some test entities.
   *
   * @var \Drupal\Core\Entity\EntityInterface[]
   */
  protected $testEntities;

  /**
   * The injected database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $injectedDatabase;

  /**
   * The name of the table that stores entity usage information.
   *
   * @var string
   */
  protected $tableName;

  /**
   * State service for recording information received by event listeners.
   *
   * @var \Drupal\Core\State\State
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->injectedDatabase = $this->container->get('database');

    $this->installSchema('entity_usage', ['entity_usage']);
    $this->tableName = 'entity_usage';

    // Create two test entities.
    $this->testEntities = $this->getTestEntities();

    $this->state = $this->container->get('state');
    $event_dispatcher = $this->container->get('event_dispatcher');
    $event_dispatcher->addListener(Events::USAGE_REGISTER,
      [$this, 'usageRegisterEventRecorder']);
    $event_dispatcher->addListener(Events::DELETE_BY_FIELD,
      [$this, 'usageDeleteByFieldEventRecorder']);
    $event_dispatcher->addListener(Events::DELETE_BY_SOURCE_ENTITY,
      [$this, 'usageDeleteBySourceEntityEventRecorder']);
    $event_dispatcher->addListener(Events::DELETE_BY_TARGET_ENTITY,
      [$this, 'usageDeleteByTargetEntityEventRecorder']);
    $event_dispatcher->addListener(Events::BULK_DELETE_DESTINATIONS,
      [$this, 'usageBulkTargetDeleteEventRecorder']);
    $event_dispatcher->addListener(Events::BULK_DELETE_SOURCES,
      [$this, 'usageBulkSourceDeleteEventRecorder']);
  }

  /**
   * Tests the listSources() and listTargets() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::listSources
   * @covers \Drupal\entity_usage\EntityUsage::listTargets
   */
  public function testlistSources() {
    /** @var \Drupal\Core\Entity\EntityInterface $target_entity */
    $target_entity = $this->testEntities[0];
    /** @var \Drupal\Core\Entity\EntityInterface $source_entity */
    $source_entity = $this->testEntities[1];
    $source_vid = ($source_entity instanceof RevisionableInterface && $source_entity->getRevisionId()) ? $source_entity->getRevisionId() : 0;
    $field_name = 'body';
    $this->injectedDatabase->insert($this->tableName)
      ->fields([
        'target_id' => $target_entity->id(),
        'target_type' => $target_entity->getEntityTypeId(),
        'source_id' => $source_entity->id(),
        'source_type' => $source_entity->getEntityTypeId(),
        'source_langcode' => $source_entity->language()->getId(),
        'source_vid' => $source_vid,
        'method' => 'entity_reference',
        'field_name' => $field_name,
        'count' => 1,
      ])
      ->execute();

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $real_source_list = $entity_usage->listSources($target_entity);
    $expected_source_list = [
      $source_entity->getEntityTypeId() => [
        (string) $source_entity->id() => [
          0 => [
            'source_langcode' => $source_entity->language()->getId(),
            'source_vid' => $source_entity->getRevisionId() ?: 0,
            'method' => 'entity_reference',
            'field_name' => $field_name,
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertEquals($expected_source_list, $real_source_list);

    $real_target_list = $entity_usage->listTargets($source_entity);
    $expected_target_list = [
      $target_entity->getEntityTypeId() => [
        (string) $target_entity->id() => [
          0 => [
            'method' => 'entity_reference',
            'field_name' => $field_name,
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertEquals($expected_target_list, $real_target_list);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the registerUsage() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::registerUsage
   */
  public function testRegisterUsage() {
    $entity = $this->testEntities[0];
    $field_name = 'body';
    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');

    // Register a new usage.
    $entity_usage->registerUsage($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 1, 'entity_reference', $field_name, 1);

    $event = \Drupal::state()->get('entity_usage_events_test.usage_register', []);

    $this->assertSame($event['event_name'], Events::USAGE_REGISTER);
    $this->assertSame($event['target_id'], $entity->id());
    $this->assertSame($event['target_type'], $entity->getEntityTypeId());
    $this->assertSame($event['source_id'], 1);
    $this->assertSame($event['source_type'], 'foo');
    $this->assertSame($event['source_langcode'], 'en');
    $this->assertSame($event['source_vid'], 1);
    $this->assertSame($event['method'], 'entity_reference');
    $this->assertSame($event['field_name'], $field_name);
    $this->assertSame($event['count'], 1);

    $real_usage = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->condition('e.target_type', $entity->getEntityTypeId())
      ->execute()
      ->fetchField();

    $this->assertEquals(1, $real_usage);

    // Delete the record.
    $entity_usage->registerUsage($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 1, 'entity_reference', $field_name, 0);

    $real_usage = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->condition('e.target_type', $entity->getEntityTypeId())
      ->execute()
      ->fetchField();

    $this->assertSame(FALSE, $real_usage);

    // Test that config settings are respected.
    $this->container->get('config.factory')
      ->getEditable('entity_usage.settings')
      // No entities tracked at all.
      ->set('track_enabled_target_entity_types', [])
      ->save();
    drupal_flush_all_caches();
    $this->container->get('entity_usage.usage')->registerUsage($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 1, 'entity_reference', $field_name, 1);

    $real_usage = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->condition('e.target_type', $entity->getEntityTypeId())
      ->execute()
      ->fetchField();

    $this->assertSame(FALSE, $real_usage);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests that our hook correctly blocks a usage from being tracked.
   */
  public function testEntityUsageBlockTrackingHook() {
    $this->container->get('module_installer')->install([
      'path',
      'views',
      'entity_usage_test',
    ]);

    $entity = $this->testEntities[0];
    $field_name = 'body';
    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $entity_usage->registerUsage($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 0, 'entity_reference', $field_name, 31);
    $real_usage = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->execute()
      ->fetchField();

    // In entity_usage_test_entity_usage_block_tracking() we block all
    // transactions that try to add "31" as count. We expect then the usage to
    // be 0.
    $this->assertEquals(0, $real_usage);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the bulkDeleteTargets() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::bulkDeleteTargets
   */
  public function testBulkDeleteTargets() {
    $entity_type = $this->testEntities[0]->getEntityTypeId();

    // Create 2 fake registers on the database table, one for each entity.
    foreach ($this->testEntities as $entity) {
      $this->injectedDatabase->insert($this->tableName)
        ->fields([
          'target_id' => $entity->id(),
          'target_type' => $entity_type,
          'source_id' => 1,
          'source_type' => 'foo',
          'source_langcode' => 'en',
          'source_vid' => 1,
          'method' => 'entity_reference',
          'field_name' => 'body',
          'count' => 1,
        ])
        ->execute();
    }

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $entity_usage->bulkDeleteTargets($entity_type);

    $event = \Drupal::state()->get('entity_usage_events_test.usage_bulk_delete_targets', []);

    $this->assertSame($event['event_name'], Events::BULK_DELETE_DESTINATIONS);
    $this->assertSame($event['target_id'], NULL);
    $this->assertSame($event['target_type'], $entity_type);
    $this->assertSame($event['source_id'], NULL);
    $this->assertSame($event['source_type'], NULL);
    $this->assertSame($event['source_langcode'], NULL);
    $this->assertSame($event['source_vid'], NULL);
    $this->assertSame($event['method'], NULL);
    $this->assertSame($event['field_name'], NULL);
    $this->assertSame($event['count'], NULL);

    // Check if there are no records left.
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_type', $entity_type)
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the bulkDeleteSources() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::bulkDeleteSources
   */
  public function testBulkDeleteSources() {
    $entity_type = $this->testEntities[0]->getEntityTypeId();

    // Create 2 fake registers on the database table, one for each entity.
    foreach ($this->testEntities as $entity) {
      $source_vid = ($entity instanceof RevisionableInterface && $entity->getRevisionId()) ? $entity->getRevisionId() : 0;
      $this->injectedDatabase->insert($this->tableName)
        ->fields([
          'target_id' => 1,
          'target_type' => 'foo',
          'source_id' => $entity->id(),
          'source_type' => $entity_type,
          'source_langcode' => $entity->language()->getId(),
          'source_vid' => $source_vid,
          'method' => 'entity_reference',
          'field_name' => 'body',
          'count' => 1,
        ])
        ->execute();
    }

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $entity_usage->bulkDeleteSources($entity_type);

    $event = \Drupal::state()->get('entity_usage_events_test.usage_bulk_delete_sources', []);

    $this->assertSame($event['event_name'], Events::BULK_DELETE_SOURCES);
    $this->assertSame($event['target_id'], NULL);
    $this->assertSame($event['target_type'], NULL);
    $this->assertSame($event['source_id'], NULL);
    $this->assertSame($event['source_type'], $entity_type);
    $this->assertSame($event['source_langcode'], NULL);
    $this->assertSame($event['source_vid'], NULL);
    $this->assertSame($event['method'], NULL);
    $this->assertSame($event['field_name'], NULL);
    $this->assertSame($event['count'], NULL);

    // Check if there are no records left.
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.source_type', $entity_type)
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the deleteByField() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::deleteByField
   */
  public function testDeleteByField() {
    $entity_type = $this->testEntities[0]->getEntityTypeId();

    // Create 2 fake registers on the database table, one for each entity.
    $i = 0;
    foreach ($this->testEntities as $entity) {
      $source_vid = ($entity instanceof RevisionableInterface && $entity->getRevisionId()) ? $entity->getRevisionId() : 0;
      $this->injectedDatabase->insert($this->tableName)
        ->fields([
          'target_id' => 1,
          'target_type' => 'foo',
          'source_id' => $entity->id(),
          'source_type' => $entity_type,
          'source_langcode' => $entity->language()->getId(),
          'source_vid' => $source_vid,
          'method' => 'entity_reference',
          'field_name' => 'body' . $i++,
          'count' => 1,
        ])
        ->execute();
    }

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    // Delete only one of them, by field.
    $entity_usage->deleteByField($entity_type, 'body1');

    $event = \Drupal::state()->get('entity_usage_events_test.usage_delete_by_field', []);

    $this->assertSame($event['event_name'], Events::DELETE_BY_FIELD);
    $this->assertSame($event['target_id'], NULL);
    $this->assertSame($event['target_type'], NULL);
    $this->assertSame($event['source_id'], NULL);
    $this->assertSame($event['source_type'], $entity_type);
    $this->assertSame($event['source_langcode'], NULL);
    $this->assertSame($event['source_vid'], NULL);
    $this->assertSame($event['method'], NULL);
    $this->assertSame($event['field_name'], 'body1');
    $this->assertSame($event['count'], NULL);

    $result = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e')
      ->condition('e.source_type', $entity_type)
      ->execute()
      ->fetchAll();
    $source_vid = ($this->testEntities[0] instanceof RevisionableInterface && $this->testEntities[0]->getRevisionId()) ? $this->testEntities[0]->getRevisionId() : 0;
    $expected_result = [
      'target_id' => '1',
      'target_id_string' => NULL,
      'target_type' => 'foo',
      'source_id' => (string) $this->testEntities[0]->id(),
      'source_id_string' => NULL,
      'source_type' => $entity_type,
      'source_langcode' => $this->testEntities[0]->language()->getId(),
      'source_vid' => $source_vid,
      'method' => 'entity_reference',
      'field_name' => 'body0',
      'count' => 1,
    ];
    $this->assertEquals([(object) $expected_result], $result);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the deleteBySourceEntity() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::deleteBySourceEntity
   */
  public function testDeleteBySourceEntity() {
    // Create 2 fake registers on the database table, one for each entity.
    $i = 0;
    foreach ($this->testEntities as $entity) {
      $i++;
      $source_vid = ($entity instanceof RevisionableInterface && $entity->getRevisionId()) ? $entity->getRevisionId() : 0;
      $this->injectedDatabase->insert($this->tableName)
        ->fields([
          'target_id' => $i,
          'target_type' => 'fake_type_' . $i,
          'source_id' => $entity->id(),
          'source_type' => $entity->getEntityTypeId(),
          'source_langcode' => $entity->language()->getId(),
          'source_vid' => $source_vid,
          'method' => 'entity_reference',
          'field_name' => 'body',
          'count' => 1,
        ])
        ->execute();
    }

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    // Delete only one of them, by source.
    $entity_usage->deleteBySourceEntity($this->testEntities[0]->id(), $this->testEntities[0]->getEntityTypeId());

    $event = \Drupal::state()->get('entity_usage_events_test.usage_delete_by_source_entity', []);

    $this->assertSame($event['event_name'], Events::DELETE_BY_SOURCE_ENTITY);
    $this->assertSame($event['target_id'], NULL);
    $this->assertSame($event['target_type'], NULL);
    $this->assertSame($event['source_id'], $this->testEntities[0]->id());
    $this->assertSame($event['source_type'], $this->testEntities[0]->getEntityTypeId());
    $this->assertSame($event['source_langcode'], NULL);
    $this->assertSame($event['source_vid'], NULL);
    $this->assertSame($event['method'], NULL);
    $this->assertSame($event['field_name'], NULL);
    $this->assertSame($event['count'], NULL);

    // The non-affected record is still there.
    $real_target_list = $entity_usage->listTargets($this->testEntities[1]);
    $expected_target_list = [
      'fake_type_2' => [
        '2' => [
          0 => [
            'method' => 'entity_reference',
            'field_name' => 'body',
            'count' => '1',
          ],
        ],
      ],
    ];
    $this->assertEquals($expected_target_list, $real_target_list);

    // The affected record is gone.
    $real_target_list = $entity_usage->listSources($this->testEntities[0]);
    $this->assertEquals([], $real_target_list);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the deleteByTargetEntity() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::deleteByTargetEntity
   */
  public function testDeleteByTargetEntity() {
    // Create 2 fake registers on the database table, one for each entity.
    $i = 0;
    foreach ($this->testEntities as $entity) {
      $i++;
      $this->injectedDatabase->insert($this->tableName)
        ->fields([
          'target_id' => $entity->id(),
          'target_type' => $entity->getEntityTypeId(),
          'source_id' => $i,
          'source_type' => 'fake_type_' . $i,
          'source_langcode' => 'en',
          'source_vid' => $i,
          'method' => 'entity_reference',
          'field_name' => 'body' . $i,
          'count' => 1,
        ])
        ->execute();
    }

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    // Delete only one of them, by target.
    $entity_usage->deleteByTargetEntity($this->testEntities[0]->id(), $this->testEntities[0]->getEntityTypeId());

    $event = \Drupal::state()->get('entity_usage_events_test.usage_delete_by_target_entity', []);

    $this->assertSame($event['event_name'], Events::DELETE_BY_TARGET_ENTITY);
    $this->assertSame($event['target_id'], $this->testEntities[0]->id());
    $this->assertSame($event['target_type'], $this->testEntities[0]->getEntityTypeId());
    $this->assertSame($event['source_id'], NULL);
    $this->assertSame($event['source_type'], NULL);
    $this->assertSame($event['source_langcode'], NULL);
    $this->assertSame($event['method'], NULL);
    $this->assertSame($event['field_name'], NULL);
    $this->assertSame($event['count'], NULL);

    // The non-affected record is still there.
    $real_source_list = $entity_usage->listSources($this->testEntities[1]);
    $expected_source_list = [
      'fake_type_2' => [
        '2' => [
          0 => [
            'source_langcode' => 'en',
            'source_vid' => '2',
            'method' => 'entity_reference',
            'field_name' => 'body2',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertEquals($expected_source_list, $real_source_list);

    // The affected record is gone.
    $real_source_list = $entity_usage->listSources($this->testEntities[0]);
    $this->assertEquals([], $real_source_list);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the legacy listUsage() and listReferencedEntities() methods.
   *
   * @covers \Drupal\entity_usage\EntityUsage::listUsage
   * @covers \Drupal\entity_usage\EntityUsage::listReferencedEntities
   */
  public function testLegacyMethods() {
    /** @var \Drupal\Core\Entity\EntityInterface $target_entity */
    $target_entity = $this->testEntities[0];
    /** @var \Drupal\Core\Entity\EntityInterface $source_entity */
    $source_entity = $this->testEntities[1];
    $source_vid = ($source_entity instanceof RevisionableInterface && $source_entity->getRevisionId()) ? $source_entity->getRevisionId() : 0;
    $field_name = 'body';
    // Create two records in the database, so we correctly ensure the counts are
    // being summed.
    $this->injectedDatabase->insert($this->tableName)
      ->fields([
        'target_id' => $target_entity->id(),
        'target_type' => $target_entity->getEntityTypeId(),
        'source_id' => $source_entity->id(),
        'source_type' => $source_entity->getEntityTypeId(),
        'source_langcode' => $source_entity->language()->getId(),
        'source_vid' => $source_vid,
        'method' => 'entity_reference',
        'field_name' => $field_name,
        'count' => 2,
      ])
      ->execute();
    $this->injectedDatabase->insert($this->tableName)
      ->fields([
        'target_id' => $target_entity->id(),
        'target_type' => $target_entity->getEntityTypeId(),
        'source_id' => $source_entity->id(),
        'source_type' => $source_entity->getEntityTypeId(),
        'source_langcode' => $source_entity->language()->getId(),
        'source_vid' => $source_vid + 1,
        'method' => 'entity_reference',
        'field_name' => $field_name,
        'count' => 3,
      ])
      ->execute();

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $real_usage_list = $entity_usage->listUsage($target_entity);
    $expected_usage_list = [
      $source_entity->getEntityTypeId() => [
        (string) $source_entity->id() => 5,
      ],
    ];
    $this->assertEquals($expected_usage_list, $real_usage_list);

    $real_target_list = $entity_usage->listReferencedEntities($source_entity);
    $expected_target_list = [
      $target_entity->getEntityTypeId() => [
        (string) $target_entity->id() => 5,
      ],
    ];
    $this->assertEquals($expected_target_list, $real_target_list);

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Creates two test entities.
   *
   * @return array
   *   An array of entity objects.
   */
  protected function getTestEntities() {
    $content_entity_1 = EntityTest::create(['name' => $this->randomMachineName()]);
    $content_entity_1->save();
    $content_entity_2 = EntityTest::create(['name' => $this->randomMachineName()]);
    $content_entity_2->save();

    return [
      $content_entity_1,
      $content_entity_2,
    ];
  }

  /**
   * Reacts to a register event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageRegisterEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_register', [
      'event_name' => $name,
      'target_id' => $event->getTargetEntityId(),
      'target_type' => $event->getTargetEntityType(),
      'source_id' => $event->getSourceEntityId(),
      'source_type' => $event->getSourceEntityType(),
      'source_langcode' => $event->getSourceEntityLangcode(),
      'source_vid' => $event->getSourceEntityRevisionId(),
      'method' => $event->getMethod(),
      'field_name' => $event->getFieldName(),
      'count' => $event->getCount(),
    ]);
  }

  /**
   * Reacts to delete by field event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageDeleteByFieldEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_delete_by_field', [
      'event_name' => $name,
      'target_id' => $event->getTargetEntityId(),
      'target_type' => $event->getTargetEntityType(),
      'source_id' => $event->getSourceEntityId(),
      'source_type' => $event->getSourceEntityType(),
      'source_langcode' => $event->getSourceEntityLangcode(),
      'source_vid' => $event->getSourceEntityRevisionId(),
      'method' => $event->getMethod(),
      'field_name' => $event->getFieldName(),
      'count' => $event->getCount(),
    ]);
  }

  /**
   * Reacts to delete by source entity event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageDeleteBySourceEntityEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_delete_by_source_entity', [
      'event_name' => $name,
      'target_id' => $event->getTargetEntityId(),
      'target_type' => $event->getTargetEntityType(),
      'source_id' => $event->getSourceEntityId(),
      'source_type' => $event->getSourceEntityType(),
      'source_langcode' => $event->getSourceEntityLangcode(),
      'source_vid' => $event->getSourceEntityRevisionId(),
      'method' => $event->getMethod(),
      'field_name' => $event->getFieldName(),
      'count' => $event->getCount(),
    ]);
  }

  /**
   * Reacts to delete by target entity event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageDeleteByTargetEntityEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_delete_by_target_entity', [
      'event_name' => $name,
      'target_id' => $event->getTargetEntityId(),
      'target_type' => $event->getTargetEntityType(),
      'source_id' => $event->getSourceEntityId(),
      'source_type' => $event->getSourceEntityType(),
      'source_langcode' => $event->getSourceEntityLangcode(),
      'source_vid' => $event->getSourceEntityRevisionId(),
      'method' => $event->getMethod(),
      'field_name' => $event->getFieldName(),
      'count' => $event->getCount(),
    ]);
  }

  /**
   * Reacts to bulk target delete event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageBulkTargetDeleteEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_bulk_delete_targets', [
      'event_name' => $name,
      'target_id' => $event->getTargetEntityId(),
      'target_type' => $event->getTargetEntityType(),
      'source_id' => $event->getSourceEntityId(),
      'source_type' => $event->getSourceEntityType(),
      'source_langcode' => $event->getSourceEntityLangcode(),
      'source_vid' => $event->getSourceEntityRevisionId(),
      'method' => $event->getMethod(),
      'field_name' => $event->getFieldName(),
      'count' => $event->getCount(),
    ]);
  }

  /**
   * Reacts to bulk source delete event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageBulkSourceDeleteEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_bulk_delete_sources', [
      'event_name' => $name,
      'target_id' => $event->getTargetEntityId(),
      'target_type' => $event->getTargetEntityType(),
      'source_id' => $event->getSourceEntityId(),
      'source_type' => $event->getSourceEntityType(),
      'source_langcode' => $event->getSourceEntityLangcode(),
      'source_vid' => $event->getSourceEntityRevisionId(),
      'method' => $event->getMethod(),
      'field_name' => $event->getFieldName(),
      'count' => $event->getCount(),
    ]);
  }

}
