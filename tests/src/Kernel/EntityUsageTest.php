<?php

namespace Drupal\Tests\entity_usage\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Tests the basic API operations of our tracking service..
 *
 * @group entity_usage
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
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->injectedDatabase = $this->container->get('database');

    $this->installSchema('entity_usage', ['entity_usage']);
    $this->tableName = 'entity_usage';

    // Create two test entities.
    $this->testEntities = $this->getTestEntities();

  }

  /**
   * Tests the listUsage() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::listUsage
   */
  public function testGetUsage() {
    $entity = $this->testEntities[0];
    $this->injectedDatabase->insert($this->tableName)
      ->fields([
        't_id' => $entity->id(),
        't_type' => $entity->getEntityTypeId(),
        're_id' => 1,
        're_type' => 'foo',
        'method' => 'entity_reference',
        'count' => 1,
      ])
      ->execute();

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $complete_usage = $entity_usage->listUsage($entity);
    $usage = $complete_usage['foo'][1];
    $this->assertEquals(1, $usage, 'Returned the correct count, without tracking method.');

    $complete_usage = $entity_usage->listUsage($entity, TRUE);
    $usage = $complete_usage['entity_reference']['foo'][1];
    $this->assertEquals(1, $usage, 'Returned the correct count, with tracking method.');

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
    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $entity_usage->add($entity->id(), $entity->getEntityTypeId(), '1', 'foo', 'entity_reference', 1);

    $real_usage = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.t_id', $entity->id())
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
    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');

    $this->injectedDatabase->insert($this->tableName)
      ->fields([
        't_id' => $entity->id(),
        't_type' => $entity->getEntityTypeId(),
        're_id' => 1,
        're_type' => 'foo',
        'method' => 'entity_reference',
        'count' => 3,
      ])
      ->execute();

    // Normal decrement.
    $entity_usage->delete($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 1);
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.t_id', $entity->id())
      ->condition('e.t_type', $entity->getEntityTypeId())
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count, 'The count was decremented correctly.');

    // Multiple decrement and removal.
    $entity_usage->delete($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 2);
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.t_id', $entity->id())
      ->condition('e.t_type', $entity->getEntityTypeId())
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'The count was removed entirely when empty.');

    // Non-existent decrement.
    $entity_usage->delete($entity->id(), $entity->getEntityTypeId(), 1, 'foo', 2);
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.t_id', $entity->id())
      ->condition('e.t_type', $entity->getEntityTypeId())
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'Decrementing non-existing record complete.');

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
          't_id' => $entity->id(),
          't_type' => $entity_type,
          're_id' => 1,
          're_type' => 'foo',
          'method' => 'entity_reference',
          'count' => 1,
        ])
        ->execute();
    }

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $entity_usage->bulkDeleteTargets($entity_type);

    // Check if there are no records left.
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.t_type', $entity_type)
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'Successfully deleted all records of this type.');

    // Clean back the environment.
    $this->injectedDatabase->truncate($this->tableName);
  }

  /**
   * Tests the bulkDeleteHosts() method.
   *
   * @covers \Drupal\entity_usage\EntityUsage::bulkDeleteHosts
   */
  public function testBulkDeleteHosts() {

    $entity_type = $this->testEntities[0]->getEntityTypeId();

    // Create 2 fake registers on the database table, one for each entity.
    foreach ($this->testEntities as $entity) {
      $this->injectedDatabase->insert($this->tableName)
        ->fields([
          't_id' => 1,
          't_type' => 'foo',
          're_id' => $entity->id(),
          're_type' => $entity_type,
          'method' => 'entity_reference',
          'count' => 1,
        ])
        ->execute();
    }

    /** @var \Drupal\entity_usage\EntityUsage $entity_usage */
    $entity_usage = $this->container->get('entity_usage.usage');
    $entity_usage->bulkDeleteHosts($entity_type);

    // Check if there are no records left.
    $count = $this->injectedDatabase->select($this->tableName, 'e')
      ->fields('e', ['count'])
      ->condition('e.re_type', $entity_type)
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

}
