<?php

namespace Drupal\Tests\entity_usage\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_usage\Events\EntityUsageEvent;
use Drupal\entity_usage\Events\Events;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Tests the basic API operations of our tracking service..
 *
 * @group entity_usage
 *
 * @package Drupal\Tests\entity_usage\Kernel
 */
class EntityUsageTest extends EntityKernelTestBase {

  /**
   * Modules to install.
   *
   * @var array
   */
  public static $modules = ['entity_usage'];

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
   * @var \Drupal\Core\Entity\ContentEntityInterface[]
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

    $this->state = \Drupal::state();
    \Drupal::service('event_dispatcher')->addListener(Events::USAGE_ADD,
      [$this, 'usageAddEventRecorder']);
    \Drupal::service('event_dispatcher')->addListener(Events::USAGE_DELETE,
      [$this, 'usageDeleteEventRecorder']);
    \Drupal::service('event_dispatcher')->addListener(Events::BULK_DELETE_DESTINATIONS,
      [$this, 'usageBulkTargetDeleteEventRecorder']);
    \Drupal::service('event_dispatcher')->addListener(Events::BULK_DELETE_SOURCES,
      [$this, 'usageBulkSourceDeleteEventRecorder']);
  }

  /**
   * Tests the listSources() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::listSources
   * @covers \Drupal\entity_usage\EntityUsage::listTargets
   */
  public function testGetSources() {
    /** @var \Drupal\node\NodeInterface $target_entity */
    $target_entity = $this->testEntities[0];
    /** @var \Drupal\node\NodeInterface $source_entity */
    $source_entity = $this->testEntities[1];
    $field_name = 'body';
    $this->injectedDatabase->insert($this->tableName)
      ->fields([
        'target_id' => $target_entity->id(),
        'target_type' => $target_entity->getEntityTypeId(),
        'source_id' => $source_entity->id(),
        'source_type' => $source_entity->getEntityTypeId(),
        'source_langcode' => $source_entity->language()->getId(),
        'source_vid' => $source_entity->getRevisionId() ?: 0,
        'method' => 'entity_reference',
        'field_name' => $field_name,
        'count' => 1,
      ])
      ->execute();

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $source_usages = $entity_usage->listSources($target_entity);
    $this->assertEquals([
      $source_entity->getEntityTypeId() => [
        $source_entity->id() => [
          0 => [
            'source_langcode' => $source_entity->language()->getId(),
            'source_vid' => $source_entity->getRevisionId() ?: 0,
            'method' => 'entity_reference',
            'field_name' => $field_name,
            'count' => 1,
          ],
        ],
      ],
    ], $source_usages, 'Returned the correct usages.');

    $target_usages = $entity_usage->listTargets($source_entity);
    $this->assertEquals([
      $target_entity->getEntityTypeId() => [
        $target_entity->id() => [
          0 => [
            'method' => 'entity_reference',
            'field_name' => $field_name,
            'count' => 1,
          ],
        ],
      ],
    ], $target_usages, 'Returned the correct usages.');

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the add() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::add
   */
  public function testAddUsage() {
    $entity = $this->testEntities[0];
    $field_name = 'body';
    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $entity_usage->add($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 1, 'entity_reference', $field_name, 1);

    $event = \Drupal::state()->get('entity_usage_events_test.usage_add', []);

    $this->assertSame($event['event_name'], Events::USAGE_ADD);
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
      ->execute()
      ->fetchField();

    $this->assertEquals(1, $real_usage, 'Usage saved correctly to the database.');

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the delete() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::delete
   */
  public function testRemoveUsage() {
    $entity = $this->testEntities[0];
    $field_name = 'body';
    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');

    $this->injectedDatabase->insert($this->tableName)
      ->fields([
        'target_id' => $entity->id(),
        'target_type' => $entity->getEntityTypeId(),
        'source_id' => 1,
        'source_type' => 'foo',
        'source_langcode' => 'en',
        'source_vid' => 1,
        'method' => 'entity_reference',
        'field_name' => $field_name,
        'count' => 3,
      ])
      ->execute();

    // Normal decrement.
    $entity_usage->delete($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 1, 'entity_reference', $field_name, 1);

    $event = \Drupal::state()->get('entity_usage_events_test.usage_delete', []);

    $this->assertSame($event['event_name'], Events::USAGE_DELETE);
    $this->assertSame($event['target_id'], $entity->id());
    $this->assertSame($event['target_type'], $entity->getEntityTypeId());
    $this->assertSame($event['source_id'], 1);
    $this->assertSame($event['source_type'], 'foo');
    $this->assertSame($event['source_langcode'], 'en');
    $this->assertSame($event['source_vid'], 1);
    $this->assertSame($event['method'], 'entity_reference');
    $this->assertSame($event['field_name'], $field_name);
    $this->assertSame($event['count'], 1);

    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->condition('e.target_type', $entity->getEntityTypeId())
      ->condition('e.field_name', $field_name)
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count, 'The count was decremented correctly.');

    // Multiple decrement and removal.
    $entity_usage->delete($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 1, 'entity_reference', $field_name, 2);
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->condition('e.target_type', $entity->getEntityTypeId())
      ->condition('e.field_name', $field_name)
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'The count was removed entirely when empty.');

    // Non-existent decrement.
    $entity_usage->delete($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 1, 'entity_reference', $field_name, 2);
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->condition('e.target_type', $entity->getEntityTypeId())
      ->condition('e.field_name', $field_name)
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'Decrementing non-existing record complete.');

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
    $entity_usage->add($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 'en', 0, 'entity_reference', $field_name, 31);
    $real_usage = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.target_id', $entity->id())
      ->execute()
      ->fetchField();

    // In entity_usage_test_entity_usage_block_tracking() we block all
    // transactions that try to add "31" as count. We expect then the usage to
    // be 0.
    $this->assertEquals(0, $real_usage, 'Usage tracking correctly blocked.');

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
    $this->assertSame(FALSE, $count, 'Successfully deleted all records of this type.');

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the bulkDeleteSources() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::bulkDeleteSources
   */
  public function testBulkDeleteHosts() {
    $entity_type = $this->testEntities[0]->getEntityTypeId();

    // Create 2 fake registers on the database table, one for each entity.
    foreach ($this->testEntities as $entity) {
      $this->injectedDatabase->insert($this->tableName)
        ->fields([
          'target_id' => 1,
          'target_type' => 'foo',
          'source_id' => $entity->id(),
          'source_type' => $entity_type,
          'source_langcode' => $entity->language()->getId(),
          'source_vid' => $entity->getRevisionId() ?: 0,
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
    $this->assertSame($event['method'], NULL);
    $this->assertSame($event['field_name'], NULL);
    $this->assertSame($event['count'], NULL);

    // Check if there are no records left.
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.source_type', $entity_type)
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'Successfully deleted all records of this type.');

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
   * Reacts to save event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageAddEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_add', [
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
   * Reacts to delete event.
   *
   * @param \Drupal\entity_usage\Events\EntityUsageEvent $event
   *   The entity usage event.
   * @param string $name
   *   The name of the event.
   */
  public function usageDeleteEventRecorder(EntityUsageEvent $event, $name) {
    $this->state->set('entity_usage_events_test.usage_delete', [
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
