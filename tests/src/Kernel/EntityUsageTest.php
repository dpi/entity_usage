<?php

namespace Drupal\entity_usage\Tests\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Tests\EntityReference\EntityReferenceTestTrait;


/**
 * Tests basic usage tracking on generic entities.
 *
 * @group entity_usage
 */
class EntityUsageTest extends EntityKernelTestBase {

  use EntityReferenceTestTrait;

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
   * The name of the field used in this test.
   *
   * @var string
   */
  protected $fieldName = 'field_test';

  /**
   * The entity to be referenced in this test.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $referencedEntity;

  /**
   * Some test entities.
   *
   * @var \Drupal\Core\Entity\EntityInterface[]
   */
  protected $testEntities;

  /**
   * The injected database connection;
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $injectedDatabase;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

//    // Use Classy theme for testing markup output.
//    \Drupal::service('theme_handler')->install(['classy']);
//    \Drupal::service('theme_handler')->setDefault('classy');
    $this->installEntitySchema('entity_test');
//    // Grant the 'view test entity' permission.
//    $this->installConfig(['user']);
//    Role::load(RoleInterface::ANONYMOUS_ID)
//      ->grantPermission('view test entity')
//      ->save();

    $this->createEntityReferenceField($this->entityType, $this->bundle, $this->fieldName, 'Field test', $this->entityType, 'default', [], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED);

    // Set up a field, so that the entity that'll be referenced bubbles up a
    // cache tag when rendering it entirely.
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => $this->entityType,
      'type' => 'text',
      'settings' => array(),
    ])->save();
    FieldConfig::create([
      'entity_type' => $this->entityType,
      'bundle' => $this->bundle,
      'field_name' => 'body',
      'label' => 'Body',
    ])->save();
    \Drupal::entityTypeManager()
      ->getStorage('entity_view_display')
      ->load($this->entityType . '.' . $this->bundle . '.' . 'default')
      ->setComponent('body', [
        'type' => 'text_default',
        'settings' => [],
      ])
      ->save();

    FilterFormat::create(array(
      'format' => 'full_html',
      'name' => 'Full HTML',
    ))->save();

    // Create the entity to be referenced.
    $this->referencedEntity = $this->container->get('entity_type.manager')
      ->getStorage($this->entityType)
      ->create(['name' => $this->randomMachineName()]);
    $this->referencedEntity->body = [
      'value' => '<p>Lorem ipsum</p>',
      'format' => 'full_html',
    ];
    $this->referencedEntity->save();

    $this->testEntities = $this->getTestEntities();

    $this->injectedDatabase = $this->container->get('database');
  }

  /**
   * @covers \Drupal\entity_usage\DatabaseEntityUsageUsageBackend::listUsage().
   */
  public function testGetUsage() {
    $entity = $this->testEntities[0];
    $this->injectedDatabase->insert('entity_usage')
      ->fields([
        't_id' => $entity->id(), // Target entity id.
        't_type' => $entity->getEntityTypeId(), // Target entity type.
        're_id' => 1, // Referencing entity id.
        're_type' => 'foo', // Referencing entity type.
        'reference_method' => 'entity_reference',
        'count' => 1,
      ])
      ->execute();

    // We assume ::listUsage() will take only $entity as parameter.
    $usage = $this->container->get('entity_usage.usage')->listUsage($entity);

    $this->assertEquals(1, $usage, 'Returned the correct count.');

    // Clean back the environment.
    $this->injectedDatabase->truncate('entity_usage');
  }

  /**
   * @covers \Drupal\entity_usage\DatabaseEntityUsageUsageBackend::add().
   */
  function testAddUsage() {
    $entity = $this->testEntities[0];
    $entity_usage = $this->container->get('entity_usage.usage');
    // Assuming ::add() will take: $entity (target), $re_id, $re_type, $method, $count.
    $entity_usage->add($entity, '1', 'foo', 'entity_reference', 1);

    $real_usage = $this->injectedDatabase->select('entity_usage')
      ->fields('f')
      ->condition('f.t_id', $entity->id())
      ->execute()
      ->fetchAllAssoc('re_id');

    $this->assertEquals(1, $real_usage[1]->count, 'Returned the correct count.');

    // Clean back the environment.
    $this->injectedDatabase->truncate('entity_usage');

  }

  /**
   * @covers \Drupal\entity_usage\DatabaseEntityUsageUsageBackend::delete().
   */
  function testRemoveUsage() {
    $entity = $this->testEntities[0];
    $entity_usage = $this->container->get('entity_usage.usage');

    $this->injectedDatabase->insert('entity_usage')
      ->fields([
        't_id' => $entity->id(), // Target entity id.
        't_type' => $entity->getEntityTypeId(), // Target entity type.
        're_id' => 1, // Referencing entity id.
        're_type' => 'foo', // Referencing entity type.
        'reference_method' => 'entity_reference',
        'count' => 3,
      ])
      ->execute();

    // Normal decrement.
    // Assuming ::delete() will take $entity, $re_id, $re_type, $count.
    $entity_usage->delete($entity, 1, 'foo', 1);
    $count = $this->injectedDatabase->select('entity_usage', 'e')
      ->fields('e', ['count'])
      ->condition('e.t_id', $entity->id())
      ->execute()
      ->fetchField();
    $this->assertEquals(2, $count, 'The count was decremented correctly.');

    // Multiple decrement and removal.
    $entity_usage->delete($entity, 1, 'foo', 2);
    $count = $this->injectedDatabase->select('entity_usage', 'e')
      ->fields('e', ['count'])
      ->condition('e.t_id', $entity->id())
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'The count was removed entirely when empty.');

    // Non-existent decrement.
    $entity_usage->delete($entity, 1, 'foo', 2);
    $count = $this->injectedDatabase->select('entity_usage', 'e')
      ->fields('e', ['count'])
      ->condition('e.t_id', $entity->id())
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'Decrementing non-exist record complete.');

  }

  /**
   * Tests basic entity tracking on test entities using entityreference fields.
   */
  public function testBasicUsageTracking() {

    // First check usage is 0 for the referenced entity.

    // Create an entity referencing it and check that the usage increases to 1.
    $field_name = $this->fieldName;
    $referencing_entity = $this->container->get('entity_type.manager')
      ->getStorage($this->entityType)
      ->create(array('name' => $this->randomMachineName()));
    $referencing_entity->save();
    $referencing_entity->{$field_name}->entity = $this->referencedEntity;

    // Update other values on the referencing entity, check usage remains 1.

    // Delete the value from the entityreference field and check that the
    // usage goes back to 0.

    // Create a reference again, check the value is back to 1.

    // Delete the whole referencing entity, check usage goes back to 0.

    // Create a reference again, check the value is back to 1.

    // Unpublish the host entity, check usage goes back to 0.

  }

//  /**
//   * Tests the entity reference field with all its supported field widgets.
//   */
//  public function testSupportedEntityTypesAndWidgets() {
//    foreach ($this->getTestEntities() as $key => $referenced_entities) {
//      $this->fieldName = 'field_test_' . $referenced_entities[0]->getEntityTypeId();
//
//      // Create an Entity reference field.
//      $this->createEntityReferenceField($this->entityType, $this->bundle, $this->fieldName, $this->fieldName, $referenced_entities[0]->getEntityTypeId(), 'default', array(), 2);
//
//      // Test the default 'entity_reference_autocomplete' widget.
//      entity_get_form_display($this->entityType, $this->bundle, 'default')->setComponent($this->fieldName)->save();
//
//      $entity_name = $this->randomMachineName();
//      $edit = array(
//        'name[0][value]' => $entity_name,
//        $this->fieldName . '[0][target_id]' => $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')',
//        // Test an input of the entity label without a ' (entity_id)' suffix.
//        $this->fieldName . '[1][target_id]' => $referenced_entities[1]->label(),
//      );
//      $this->drupalPostForm($this->entityType . '/add', $edit, t('Save'));
//      $this->assertFieldValues($entity_name, $referenced_entities);
//
//      // Try to post the form again with no modification and check if the field
//      // values remain the same.
//      $entity = current(entity_load_multiple_by_properties($this->entityType, array('name' => $entity_name)));
//      $this->drupalGet($this->entityType . '/manage/' . $entity->id() . '/edit');
//      $this->assertFieldByName($this->fieldName . '[0][target_id]', $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')');
//      $this->assertFieldByName($this->fieldName . '[1][target_id]', $referenced_entities[1]->label() . ' (' . $referenced_entities[1]->id() . ')');
//
//      $this->drupalPostForm(NULL, array(), t('Save'));
//      $this->assertFieldValues($entity_name, $referenced_entities);
//
//      // Test the 'entity_reference_autocomplete_tags' widget.
//      entity_get_form_display($this->entityType, $this->bundle, 'default')->setComponent($this->fieldName, array(
//        'type' => 'entity_reference_autocomplete_tags',
//      ))->save();
//
//      $entity_name = $this->randomMachineName();
//      $target_id = $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')';
//      // Test an input of the entity label without a ' (entity_id)' suffix.
//      $target_id .= ', ' . $referenced_entities[1]->label();
//      $edit = array(
//        'name[0][value]' => $entity_name,
//        $this->fieldName . '[target_id]' => $target_id,
//      );
//      $this->drupalPostForm($this->entityType . '/add', $edit, t('Save'));
//      $this->assertFieldValues($entity_name, $referenced_entities);
//
//      // Try to post the form again with no modification and check if the field
//      // values remain the same.
//      $entity = current(entity_load_multiple_by_properties($this->entityType, array('name' => $entity_name)));
//      $this->drupalGet($this->entityType . '/manage/' . $entity->id() . '/edit');
//      $this->assertFieldByName($this->fieldName . '[target_id]', $target_id . ' (' . $referenced_entities[1]->id() . ')');
//
//      $this->drupalPostForm(NULL, array(), t('Save'));
//      $this->assertFieldValues($entity_name, $referenced_entities);
//
//      // Test all the other widgets supported by the entity reference field.
//      // Since we don't know the form structure for these widgets, just test
//      // that editing and saving an already created entity works.
//      $exclude = array('entity_reference_autocomplete', 'entity_reference_autocomplete_tags');
//      $entity = current(entity_load_multiple_by_properties($this->entityType, array('name' => $entity_name)));
//      $supported_widgets = \Drupal::service('plugin.manager.field.widget')->getOptions('entity_reference');
//      $supported_widget_types = array_diff(array_keys($supported_widgets), $exclude);
//
//      foreach ($supported_widget_types as $widget_type) {
//        entity_get_form_display($this->entityType, $this->bundle, 'default')->setComponent($this->fieldName, array(
//          'type' => $widget_type,
//        ))->save();
//
//        $this->drupalPostForm($this->entityType . '/manage/' . $entity->id() . '/edit', array(), t('Save'));
//        $this->assertFieldValues($entity_name, $referenced_entities);
//      }
//
//      // Reset to the default 'entity_reference_autocomplete' widget.
//      entity_get_form_display($this->entityType, $this->bundle, 'default')->setComponent($this->fieldName)->save();
//
//      // Set first entity as the default_value.
//      $field_edit = array(
//        'default_value_input[' . $this->fieldName . '][0][target_id]' => $referenced_entities[0]->label() . ' (' . $referenced_entities[0]->id() . ')',
//      );
//      if ($key == 'content') {
//        $field_edit['settings[handler_settings][target_bundles][' . $referenced_entities[0]->getEntityTypeId() . ']'] = TRUE;
//      }
//      $this->drupalPostForm($this->entityType . '/structure/' . $this->bundle . '/fields/' . $this->entityType . '.' . $this->bundle . '.' . $this->fieldName, $field_edit, t('Save settings'));
//      // Ensure the configuration has the expected dependency on the entity that
//      // is being used a default value.
//      $field = FieldConfig::loadByName($this->entityType, $this->bundle, $this->fieldName);
//      $this->assertTrue(in_array($referenced_entities[0]->getConfigDependencyName(), $field->getDependencies()[$key]), SafeMarkup::format('Expected @type dependency @name found', ['@type' => $key, '@name' => $referenced_entities[0]->getConfigDependencyName()]));
//      // Ensure that the field can be imported without change even after the
//      // default value deleted.
//      $referenced_entities[0]->delete();
//      // Reload the field since deleting the default value can change the field.
//      \Drupal::entityManager()->getStorage($field->getEntityTypeId())->resetCache([$field->id()]);
//      $field = FieldConfig::loadByName($this->entityType, $this->bundle, $this->fieldName);
//      $this->assertConfigEntityImport($field);
//
//      // Once the default value has been removed after saving the dependency
//      // should be removed.
//      $field = FieldConfig::loadByName($this->entityType, $this->bundle, $this->fieldName);
//      $field->save();
//      $dependencies = $field->getDependencies();
//      $this->assertFalse(isset($dependencies[$key]) && in_array($referenced_entities[0]->getConfigDependencyName(), $dependencies[$key]), SafeMarkup::format('@type dependency @name does not exist.', ['@type' => $key, '@name' => $referenced_entities[0]->getConfigDependencyName()]));
//    }
//  }

//  /**
//   * Asserts that the reference field values are correct.
//   *
//   * @param string $entity_name
//   *   The name of the test entity.
//   * @param \Drupal\Core\Entity\EntityInterface[] $referenced_entities
//   *   An array of referenced entities.
//   */
//  protected function assertFieldValues($entity_name, $referenced_entities) {
//    $entity = current(entity_load_multiple_by_properties($this->entityType, array('name' => $entity_name)));
//
//    $this->assertTrue($entity, format_string('%entity_type: Entity found in the database.', array('%entity_type' => $this->entityType)));
//
//    $this->assertEqual($entity->{$this->fieldName}->target_id, $referenced_entities[0]->id());
//    $this->assertEqual($entity->{$this->fieldName}->entity->id(), $referenced_entities[0]->id());
//    $this->assertEqual($entity->{$this->fieldName}->entity->label(), $referenced_entities[0]->label());
//
//    $this->assertEqual($entity->{$this->fieldName}[1]->target_id, $referenced_entities[1]->id());
//    $this->assertEqual($entity->{$this->fieldName}[1]->entity->id(), $referenced_entities[1]->id());
//    $this->assertEqual($entity->{$this->fieldName}[1]->entity->label(), $referenced_entities[1]->label());
//  }

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
