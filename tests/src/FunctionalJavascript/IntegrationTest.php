<?php

namespace Drupal\Tests\entity_usage\FunctionalJavascript;

use Drupal\node\Entity\Node;

/**
 * Basic tests for the entity_reference and entity_embed tracking plugins.
 *
 * @package Drupal\Tests\entity_usage\FunctionalJavascript
 *
 * @group entity_usage
 */
class IntegrationTest extends EntityUsageJavascriptTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [];

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * Tests the tracking of nodes in some simple CRUD operations.
   */
  public function testCRUDTracking() {

    $page = $this->getSession()->getPage();
    /** @var \Drupal\entity_usage\EntityUsage $usage_service */
    $usage_service = \Drupal::service('entity_usage.usage');

    // Create node 1.
    $this->drupalGet('/node/add/eu_test_ct');
    $page->fillField('title[0][value]', 'Node 1');
    $page->pressButton('Save and publish');
    $this->assertSession()->pageTextContains('eu_test_ct Node 1 has been created.');
    $this->saveHtmlOutput();
    $node1 = Node::load(1);

    // Create node 2 referencing node 1 using reference field.
    $this->drupalGet('/node/add/eu_test_ct');
    $page->fillField('title[0][value]', 'Node 2');
    $page->fillField('field_eu_test_related_nodes[0][target_id]', 'Node 1 (1)');
    $page->pressButton('Save and publish');
    $this->assertSession()->pageTextContains('eu_test_ct Node 2 has been created.');
    $this->saveHtmlOutput();
    $node2 = Node::load(2);
    // Check that we registered correctly the relation between N2 and N1.
    $usage = $usage_service->listUsage($node1);
    $this->assertEquals($usage['node'], ['2' => '1'], 'Correct usage found.');
    // Check that the method stored for the tracking is "entity_reference".
    $usage = $usage_service->listUsage($node1, TRUE);
    $this->assertEquals($usage['entity_reference']['node'], ['2' => '1'], 'Correct usage found.');

    // Create node 3 referencing node 2 using embedded text.
    // $this->drupalGet('/node/add/eu_test_ct'); .
    // $page->fillField('title[0][value]', 'Node 3'); .
    // @TODO ^ The Ckeditor is creating some trouble to do this in a simple way.
    // For now let's just avoid all this ckeditor interaction (which is not what
    // we are really testing) and create a node programatically, which triggers
    // the tracking as well.
    $uuid_node2 = $node2->uuid();
    $embedded_text = '<drupal-entity data-embed-button="node" data-entity-embed-display="entity_reference:entity_reference_label" data-entity-embed-display-settings="{&quot;link&quot;:1}" data-entity-type="node" data-entity-uuid="' . $uuid_node2 . '"></drupal-entity>';
    $node3 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 3',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node3->save();
    // Check that we registered correctly the relation between N3 and N2.
    $usage = $usage_service->listUsage($node2);
    $this->assertEquals($usage['node'], ['3' => '1'], 'Correct usage found.');
    // Check that the method stored for the tracking is "entity_embed".
    $usage = $usage_service->listUsage($node2, TRUE);
    $this->assertEquals($usage['entity_embed']['node'], ['3' => '1'], 'Correct usage found.');

    // Delete node 2 and verify that we clean up usages.
    $node2->delete();
    $usage = $usage_service->listUsage($node1);
    $this->assertEquals($usage, [], 'Usage for node1 correctly cleaned up.');
    $database = \Drupal::database();
    $count = $database->select('entity_usage', 'e')
      ->fields('e', ['count'])
      ->condition('e.t_type', 'node')
      ->condition('e.t_id', '2')
      ->execute()
      ->fetchField();
    $this->assertSame(FALSE, $count, 'Usage for node2 correctly cleaned up.');

  }

}
