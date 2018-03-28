<?php

namespace Drupal\Tests\entity_usage\FunctionalJavascript;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\link\LinkItemInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\entity_usage\Traits\EntityUsageLastEntityQueryTrait;

/**
 * Functional tests for plugins that extend TextFieldEmbedBase.
 *
 * This should cover at least the logic specific to plugins:
 *  - Entity Embed
 *  - LinkIt
 *  - HtmlLink.
 *
 * @package Drupal\Tests\entity_usage\FunctionalJavascript
 *
 * @group entity_usage
 */
class EmbedTest extends EntityUsageJavascriptTestBase {

  use EntityUsageLastEntityQueryTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
//    'link',
  ];

  /**
   * Tests tracking of entities embedded with Entity Embed.
   */
  public function testEntityEmbedContent() {
    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();

    /** @var \Drupal\entity_usage\EntityUsage $usage_service */
    $usage_service = \Drupal::service('entity_usage.usage');
    $this->assertTrue(TRUE);

    //    // Create node 3 referencing node 2 using embedded text.
//    // $this->drupalGet('/node/add/eu_test_ct'); .
//    // $page->fillField('title[0][value]', 'Node 3'); .
//    // @TODO ^ The Ckeditor is creating some trouble to do this in a simple way.
//    // For now let's just avoid all this ckeditor interaction (which is not what
//    // we are really testing) and create a node programatically, which triggers
//    // the tracking as well.
//    $uuid_node2 = $node2->uuid();
//    $embedded_text = '<drupal-entity data-embed-button="node" data-entity-embed-display="entity_reference:entity_reference_label" data-entity-embed-display-settings="{&quot;link&quot;:1}" data-entity-type="node" data-entity-uuid="' . $uuid_node2 . '"></drupal-entity>';
//    $node3 = Node::create([
//      'type' => 'eu_test_ct',
//      'title' => 'Node 3',
//      'field_eu_test_rich_text' => [
//        'value' => $embedded_text,
//        'format' => 'eu_test_text_format',
//      ],
//    ]);
//    $node3->save();
//    // Check that we correctly registered the relation between N3 and N2.
//    $usage = $usage_service->listSources($node2);
//    $this->assertEquals($usage['node'], [
//      $node3->id() => [
//        0 => [
//          'source_langcode' => 'en',
//          'source_vid' => $node3->getRevisionId(),
//          'method' => 'entity_embed',
//          'field_name' => 'field_eu_test_rich_text',
//          'count' => 1,
//        ],
//      ],
//    ], 'Correct usage found.');

    //    // Create node 5 referencing node 4 using a linkit markup.
//    $embedded_text = '<p>foo <a data-entity-substitution="canonical" data-entity-type="node" data-entity-uuid="' . $node4->uuid() . '">linked text</a> bar</p>';
//    $node5 = Node::create([
//      'type' => 'eu_test_ct',
//      'title' => 'Node 5',
//      'field_eu_test_rich_text' => [
//        'value' => $embedded_text,
//        'format' => 'eu_test_text_format',
//      ],
//    ]);
//    $node5->save();
//    // Check that we registered correctly the relation between N5 and N2.
//    $usage = $usage_service->listSources($node4);
//    $this->assertEquals($usage['node'], [
//      $node5->id() => [
//        0 => [
//          'source_langcode' => 'en',
//          'source_vid' => $node5->getRevisionId(),
//          'method' => 'linkit',
//          'field_name' => 'field_eu_test_rich_text',
//          'count' => 1,
//        ],
//      ],
//    ], 'Correct usage found.');
//
//    // Create node 6 referencing a non existing UUID using a linkit markup to
//    // test removed entities.
//    $embedded_text = '<p>foo <a data-entity-substitution="canonical" data-entity-type="node" data-entity-uuid="c7cae398-3c36-47d4-8ef0-a17902e76ff4">I do not exists</a> bar</p>';
//    $node6 = Node::create([
//      'type' => 'eu_test_ct',
//      'title' => 'Node 6',
//      'field_eu_test_rich_text' => [
//        'value' => $embedded_text,
//        'format' => 'eu_test_text_format',
//      ],
//    ]);
//    $node6->save();
//    // Check that the usage for this source is empty.
//    $usage = $usage_service->listTargets($node6);
//    $this->assertEquals([], $usage, 'Correct usage found.');

  }

}
