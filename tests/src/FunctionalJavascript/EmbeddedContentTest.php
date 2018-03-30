<?php

namespace Drupal\Tests\entity_usage\FunctionalJavascript;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\entity_usage\Traits\EntityUsageLastEntityQueryTrait;

/**
 * Basic functional tests for the usage tracking of embedded content.
 *
 * This should test logic specific for plugins:
 * - Entity Embed
 * - LinkIt
 * - HtmlLink.
 *
 * @package Drupal\Tests\entity_usage\FunctionalJavascript
 *
 * @group entity_usage
 */
class EmbeddedContentTest extends EntityUsageJavascriptTestBase {

  use EntityUsageLastEntityQueryTrait;

  /**
   * Tests the Entity Embed parsing.
   */
  public function testEntityEmbed() {
    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();

    /** @var \Drupal\entity_usage\EntityUsage $usage_service */
    $usage_service = \Drupal::service('entity_usage.usage');

    // Create node 1.
    $this->drupalGet('/node/add/eu_test_ct');
    $page->fillField('title[0][value]', 'Node 1');
    $page->pressButton('Save');
    $session->wait(500);
    $assert_session->pageTextContains('eu_test_ct Node 1 has been created.');
    $this->saveHtmlOutput();
    $node1 = $this->getLastEntityOfType('node', TRUE);

    // Nobody is using this guy for now.
    $usage = $usage_service->listSources($node1);
    $this->assertEquals([], $usage);

    // Create node 2 referencing node 1 using an Entity Embed markup.
    $embedded_text = '<drupal-entity data-embed-button="node" data-entity-embed-display="entity_reference:entity_reference_label" data-entity-embed-display-settings="{&quot;link&quot;:1}" data-entity-type="node" data-entity-uuid="' . $node1->uuid() . '"></drupal-entity>';
    $node2 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 2',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node2->save();
    // Check that we correctly registered the relation between N2 and N1.
    $usage = $usage_service->listSources($node1);
    $expected = [
      'node' => [
        $node2->id() => [
          [
            'source_langcode' => $node2->language()->getId(),
            'source_vid' => $node2->getRevisionId(),
            'method' => 'entity_embed',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);

    // Create node 3 referencing N2 and N1 on the same field.
    $embedded_text .= '<p>Foo bar</p>' . '<drupal-entity data-embed-button="node" data-entity-embed-display="entity_reference:entity_reference_label" data-entity-embed-display-settings="{&quot;link&quot;:1}" data-entity-type="node" data-entity-uuid="' . $node2->uuid() . '"></drupal-entity>';
    $node3 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 3',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node3->save();
    // Check that both targets are tracked.
    $usage = $usage_service->listTargets($node3);
    $expected = [
      'node' => [
        $node1->id() => [
          [
            'method' => 'entity_embed',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
        $node2->id() => [
          [
            'method' => 'entity_embed',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);
  }

  /**
   * Tests the LinkIt parsing.
   */
  public function testLinkIt() {
    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();

    /** @var \Drupal\entity_usage\EntityUsage $usage_service */
    $usage_service = \Drupal::service('entity_usage.usage');

    // Create node 1.
    $this->drupalGet('/node/add/eu_test_ct');
    $page->fillField('title[0][value]', 'Node 1');
    $page->pressButton('Save');
    $session->wait(500);
    $assert_session->pageTextContains('eu_test_ct Node 1 has been created.');
    $this->saveHtmlOutput();
    $node1 = $this->getLastEntityOfType('node', TRUE);

    // Nobody is using this guy for now.
    $usage = $usage_service->listSources($node1);
    $this->assertEquals([], $usage);

    // Create node 2 referencing node 1 using a linkit markup.
    $embedded_text = '<p>foo <a data-entity-substitution="canonical" data-entity-type="node" data-entity-uuid="' . $node1->uuid() . '">linked text</a> bar</p>';
    $node2 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 2',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node2->save();
    // Check that we correctly registered the relation between N2 and N1.
    $usage = $usage_service->listSources($node1);
    $expected = [
      'node' => [
        $node2->id() => [
          [
            'source_langcode' => $node2->language()->getId(),
            'source_vid' => $node2->getRevisionId(),
            'method' => 'linkit',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);

    // Create node 3 referencing N2 and N1 on the same field.
    $embedded_text .= '<p>Foo bar</p>' . '<p>foo2 <a data-entity-substitution="canonical" data-entity-type="node" data-entity-uuid="' . $node2->uuid() . '">linked text 2</a> bar 2</p>';
    $node3 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 3',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node3->save();
    // Check that both targets are tracked.
    $usage = $usage_service->listTargets($node3);
    $expected = [
      'node' => [
        $node1->id() => [
          [
            'method' => 'linkit',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
        $node2->id() => [
          [
            'method' => 'linkit',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);

    // Create node 4 referencing a non existing UUID using a linkit markup to
    // test removed entities.
    $embedded_text = '<p>foo <a data-entity-substitution="canonical" data-entity-type="node" data-entity-uuid="c7cae398-3c36-47d4-8ef0-a17902e76ff4">I do not exists</a> bar</p>';
    $node4 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 4',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node4->save();
    // Check that the usage for this source is empty.
    $usage = $usage_service->listTargets($node4);
    $this->assertEquals([], $usage);
  }

  /**
   * Tests the HtmlLink parsing.
   */
  public function testHtmlLink() {
    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();

    /** @var \Drupal\entity_usage\EntityUsage $usage_service */
    $usage_service = \Drupal::service('entity_usage.usage');

    // Create node 1.
    $this->drupalGet('/node/add/eu_test_ct');
    $page->fillField('title[0][value]', 'Node 1');
    $page->pressButton('Save');
    $session->wait(500);
    $assert_session->pageTextContains('eu_test_ct Node 1 has been created.');
    $this->saveHtmlOutput();
    $node1 = $this->getLastEntityOfType('node', TRUE);

    // Nobody is using this guy for now.
    $usage = $usage_service->listSources($node1);
    $this->assertEquals([], $usage);

    // Create node 2 referencing node 1 using a normal link markup.
    $embedded_text = '<p>foo <a href="/node/' . $node1->id() . '">linked text</a> bar</p>';
    $node2 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 2',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node2->save();
    // Check that we correctly registered the relation between N2 and N1.
    $usage = $usage_service->listSources($node1);
    $expected = [
      'node' => [
        $node2->id() => [
          [
            'source_langcode' => $node2->language()->getId(),
            'source_vid' => $node2->getRevisionId(),
            'method' => 'html_link',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);

    // Create node 3 referencing N2 and N1 on the same field.
    $embedded_text .= '<p>Foo bar</p>' . '<p>foo2 <a href="/node/' . $node2->id() . '">linked text 2</a> bar 2</p>';
    $node3 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 3',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node3->save();
    // Check that both targets are tracked.
    $usage = $usage_service->listTargets($node3);
    $expected = [
      'node' => [
        $node1->id() => [
          [
            'method' => 'html_link',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
        $node2->id() => [
          [
            'method' => 'html_link',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);

    // Create node 4 referencing a non existing path to test removed entities.
    $embedded_text = '<p>foo <a href="/node/4324">linked text foo 2</a> bar</p>';
    $node4 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 4',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node4->save();
    // Check that the usage for this source is empty.
    $usage = $usage_service->listTargets($node4);
    $this->assertEquals([], $usage);

    // Create node 5 referencing node 4 using an absolute URL.
    $embedded_text = '<p>foo <a href="' . $node4->toUrl()->setAbsolute(TRUE)->toString() . '">linked text</a> bar</p>';
    // Whitelist the local hostname so we can test absolute URLs.
    $current_request = \Drupal::request();
    $config = \Drupal::configFactory()->getEditable('entity_usage.settings');
    $config->set('site_domains', [$current_request->getHttpHost() . $current_request->getBasePath()]);
    $config->save();
    $node5 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 5',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node5->save();
    // Check that we correctly registered the relation between N5 and N4.
    $usage = $usage_service->listSources($node4);
    $expected = [
      'node' => [
        $node5->id() => [
          [
            'source_langcode' => $node5->language()->getId(),
            'source_vid' => $node5->getRevisionId(),
            'method' => 'html_link',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);

    // Create a different field and make sure that a plugin tracking two
    // different field types works as expected.
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_eu_test_normal_text',
      'entity_type' => 'node',
      'type' => 'text',
    ]);
    $storage->save();
    FieldConfig::create([
      'bundle' => 'eu_test_ct',
      'entity_type' => 'node',
      'field_name' => 'field_eu_test_normal_text',
      'label' => 'Normal text',
    ])->save();

    // Create node 6 referencing N5 twice, once on each field.
    $embedded_text = '<p>Foo bar</p>' . '<p>foo2 <a href="/node/' . $node5->id() . '">linked text 5</a> bar</p>';
    $node6 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 6',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
      'field_eu_test_normal_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node6->save();
    // Check that both targets are tracked.
    $usage = $usage_service->listTargets($node6);
    $expected = [
      'node' => [
        $node5->id() => [
          [
            'method' => 'html_link',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
          [
            'method' => 'html_link',
            'field_name' => 'field_eu_test_normal_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);

    // Create node 7 referencing node 6 using an aliased URL.
    $alias_url = '/i-am-an-alias';
    \Drupal::service('path.alias_storage')->save('/node/' . $node6->id(), $alias_url, $node6->language()->getId());
    $embedded_text = '<p>foo <a href="' . $alias_url . '">linked text</a> bar</p>';
    $node7 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Node 7',
      'field_eu_test_rich_text' => [
        'value' => $embedded_text,
        'format' => 'eu_test_text_format',
      ],
    ]);
    $node7->save();
    // Check that we correctly registered the relation between N5 and N4.
    $usage = $usage_service->listSources($node6);
    $expected = [
      'node' => [
        $node7->id() => [
          [
            'source_langcode' => $node7->language()->getId(),
            'source_vid' => $node7->getRevisionId(),
            'method' => 'html_link',
            'field_name' => 'field_eu_test_rich_text',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertequals($expected, $usage);
  }

}
