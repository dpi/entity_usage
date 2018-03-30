<?php

namespace Drupal\Tests\entity_usage\FunctionalJavascript;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\media\Functional\MediaFunctionalTestCreateMediaTypeTrait;

/**
 * Tests the configuration form.
 *
 * @package Drupal\Tests\entity_usage\FunctionalJavascript
 *
 * @group entity_usage
 */
class ConfigurationFormTest extends EntityUsageJavascriptTestBase {

  use MediaFunctionalTestCreateMediaTypeTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'file',
    'image',
    'media',
    'media_test_source',
  ];

  /**
   * Tests the config form.
   */
  public function testConfigForm() {
    $this->drupalPlaceBlock('local_tasks_block');
    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();

    // Create a media type and media asset.
    $media_type = $this->createMediaType();
    $media1 = Media::create([
      'bundle' => $media_type->id(),
      'name' => 'Target media 1',
    ]);
    $media1->save();
    // Create an entity reference field pointing to a media entity. We will use
    // this to test different entity types tracking settings.
    $storage = FieldStorageConfig::create([
      'field_name' => 'field_eu_test_related_media',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'media',
      ],
    ]);
    $storage->save();
    FieldConfig::create([
      'bundle' => 'eu_test_ct',
      'entity_type' => 'node',
      'field_name' => 'field_eu_test_related_media',
      'label' => 'Related Media',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => [$media_type->id()],
          'auto_create' => FALSE,
        ],
      ],
    ])->save();
    // Define our widget and formatter for this field.
    entity_get_form_display('node', 'eu_test_ct', 'default')
      ->setComponent('field_eu_test_related_media', [
        'type' => 'entity_reference_autocomplete',
      ])
      ->save();
    entity_get_display('node', 'eu_test_ct', 'default')
      ->setComponent('field_eu_test_related_media', [
        'type' => 'entity_reference_label',
      ])
      ->save();

    $all_entity_types = \Drupal::entityTypeManager()->getDefinitions();
    $content_entity_types = [];
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    $entity_types = [];
    $tabs = [];
    foreach ($all_entity_types as $entity_type) {
      if (($entity_type instanceof ContentEntityTypeInterface)) {
        $content_entity_types[$entity_type->id()] = $entity_type->getLabel();
      }
      $entity_types[$entity_type->id()] = $entity_type->getLabel();
      if ($entity_type->hasLinkTemplate('canonical')) {
        $tabs[$entity_type->id()] = $entity_type->getLabel();
      }
    }
    unset($content_entity_types['file']);
    unset($content_entity_types['user']);

    // Check the form is using the expected permission-based access.
    $this->drupalGet('/admin/config/entity-usage/settings');
    $assert_session->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser([
      'bypass node access',
      'administer entity usage',
      'access entity usage statistics',
    ]));
    $this->drupalGet('/admin/config/entity-usage/settings');
    $assert_session->statusCodeEquals(200);

    $assert_session->titleEquals('Entity Usage Settings | Drupal');

    // Test the local tasks configuration.
    $node = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node',
    ]);
    $node->save();
    $this->drupalGet("/node/{$node->id()}");
    $assert_session->pageTextNotContains('Usage');
    $this->drupalGet('/admin/config/entity-usage/settings');
    $summary = $assert_session->elementExists('css', '#edit-local-task-enabled-entity-types summary');
    $this->assertEquals('Enabled local tasks', $summary->getText());
    $assert_session->pageTextContains('Check in which entity types there should be a tab (local task) linking to the usage page.');
    foreach ($tabs as $entity_type_id => $entity_type) {
      $field_name = "local_task_enabled_entity_types[entity_types][$entity_type_id]";
      $assert_session->fieldExists($field_name);
      // By default none of the tabs should be enabled.
      $assert_session->checkboxNotChecked($field_name);
    }
    // Enable it for nodes.
    $page->checkField('local_task_enabled_entity_types[entity_types][node]');
    $page->pressButton('Save configuration');
    $this->saveHtmlOutput();
    $assert_session->pageTextContains('The configuration options have been saved.');
    $assert_session->checkboxChecked('local_task_enabled_entity_types[entity_types][node]');
    $node1 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node 1',
    ]);
    $node1->save();
    $this->drupalGet("/node/{$node1->id()}");
    $assert_session->pageTextContains('Usage');
    $page->clickLink('Usage');
    $this->saveHtmlOutput();
    // We should be at /node/*/usage.
    $this->assertContains("/node/{$node1->id()}/usage", $session->getCurrentUrl());
    $assert_session->pageTextContains('Entity usage information for Test node 1');
    $assert_session->pageTextContains('There are no recorded usages for ');
    // We still have the local tabs available.
    $page->clickLink('View');
    $this->saveHtmlOutput();
    // We should be back at the node view.
    $assert_session->titleEquals('Test node 1 | Drupal');

    // Test enabled source entity types config.
    $this->drupalGet('/admin/config/entity-usage/settings');
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-source-entity-types summary');
    $this->assertEquals('Enabled source entity types', $summary->getText());
    $assert_session->pageTextContains('Check which entity types should be tracked when source.');
    foreach ($entity_types as $entity_type_id => $entity_type) {
      $field_name = "track_enabled_source_entity_types[entity_types][$entity_type_id]";
      $assert_session->fieldExists($field_name);
      // By default all content entity types are tracked.
      if (in_array($entity_type_id, array_keys($content_entity_types))) {
        $assert_session->checkboxChecked($field_name);
      }
      else {
        $assert_session->checkboxNotChecked($field_name);
      }
    }

    // Test enabled target entity types config.
    $this->drupalGet('/admin/config/entity-usage/settings');
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-target-entity-types summary');
    $this->assertEquals('Enabled target entity types', $summary->getText());
    $assert_session->pageTextContains('Check which entity types should be tracked when target.');
    foreach ($entity_types as $entity_type_id => $entity_type) {
      $field_name = "track_enabled_target_entity_types[entity_types][$entity_type_id]";
      $assert_session->fieldExists($field_name);
      // By default all content entity types are tracked.
      if (in_array($entity_type_id, array_keys($content_entity_types))) {
        $assert_session->checkboxChecked($field_name);
      }
      else {
        $assert_session->checkboxNotChecked($field_name);
      }
    }

    // Test that the source / target configuration works.
    // When both node and media are enabled, creating a node pointing to that
    // media asset should record an usage.
    $node2 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node 2',
      'field_eu_test_related_media' => [$media1->id()],
    ]);
    $node2->save();
    $usage = \Drupal::service('entity_usage.usage')->listSources($media1);
    $expected = [
      'node' => [
        $node2->id() => [
          [
            'source_langcode' => $node2->language()->getId(),
            'source_vid' => $node2->getRevisionId(),
            'method' => 'entity_reference',
            'field_name' => 'field_eu_test_related_media',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertEquals($expected, $usage);
    $node2->delete();
    $usage = \Drupal::service('entity_usage.usage')->listSources($media1);
    $this->assertEquals([], $usage);
    // Disabling media as target should prevent the record from being tracked.
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-target-entity-types summary');
    $summary->click();
    $page->uncheckField('track_enabled_target_entity_types[entity_types][media]');
    $page->pressButton('Save configuration');
    $this->saveHtmlOutput();
    drupal_flush_all_caches();
    $assert_session->pageTextContains('The configuration options have been saved.');
    $node3 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node 3',
      'field_eu_test_related_media' => [$media1->id()],
    ]);
    $node3->save();
    $usage = \Drupal::service('entity_usage.usage')->listSources($media1);
    $this->assertEquals([], $usage);
    // Enabling media as target and disabling node as source should be the same.
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-source-entity-types summary');
    $summary->click();
    $page->uncheckField('track_enabled_source_entity_types[entity_types][node]');
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-target-entity-types summary');
    $summary->click();
    $page->checkField('track_enabled_target_entity_types[entity_types][media]');
    $page->pressButton('Save configuration');
    $this->saveHtmlOutput();
    drupal_flush_all_caches();
    $assert_session->pageTextContains('The configuration options have been saved.');
    $node4 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node 4',
      'field_eu_test_related_media' => [$media1->id()],
    ]);
    $node4->save();
    $usage = \Drupal::service('entity_usage.usage')->listSources($media1);
    $this->assertEquals([], $usage);
    // Enable back both of them and we start tracking again.
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-source-entity-types summary');
    $summary->click();
    $page->checkField('track_enabled_source_entity_types[entity_types][node]');
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-target-entity-types summary');
    $summary->click();
    $page->checkField('track_enabled_target_entity_types[entity_types][media]');
    $page->pressButton('Save configuration');
    $this->saveHtmlOutput();
    drupal_flush_all_caches();
    $assert_session->pageTextContains('The configuration options have been saved.');
    $node5 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node 5',
      'field_eu_test_related_media' => [$media1->id()],
    ]);
    $node5->save();
    $usage = \Drupal::service('entity_usage.usage')->listSources($media1);
    $expected = [
      'node' => [
        $node5->id() => [
          [
            'source_langcode' => $node5->language()->getId(),
            'source_vid' => $node5->getRevisionId(),
            'method' => 'entity_reference',
            'field_name' => 'field_eu_test_related_media',
            'count' => 1,
          ],
        ],
      ],
    ];
    $this->assertEquals($expected, $usage);
    $node5->delete();
    $usage = \Drupal::service('entity_usage.usage')->listSources($media1);
    $this->assertEquals([], $usage);

    // Test enabled plugins.
    $this->drupalGet('/admin/config/entity-usage/settings');
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-plugins summary');
    $this->assertEquals('Enabled tracking plugins', $summary->getText());
    $assert_session->pageTextContains('The following plugins were found in the system and can provide usage tracking. Check all plugins that should be active.');
    $plugins = \Drupal::service('plugin.manager.entity_usage.track')->getDefinitions();
    foreach ($plugins as $plugin_id => $plugin) {
      $field_name = "track_enabled_plugins[plugins][$plugin_id]";
      $assert_session->fieldExists($field_name);
      $assert_session->pageTextContains($plugin['label']);
      if (!empty($plugin['description'])) {
        $assert_session->pageTextContains($plugin['description']);
      }
      // By default all plugins are active.
      $assert_session->checkboxChecked($field_name);
    }
    // Disable entity_reference and check usage is not tracked.
    $summary = $assert_session->elementExists('css', '#edit-track-enabled-plugins summary');
    $this->assertEquals('Enabled tracking plugins', $summary->getText());
    $summary->click();
    $page->uncheckField('track_enabled_plugins[plugins][entity_reference]');
    $page->pressButton('Save configuration');
    $this->saveHtmlOutput();
    drupal_flush_all_caches();
    $assert_session->pageTextContains('The configuration options have been saved.');
    $node6 = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node 6',
      'field_eu_test_related_media' => [$media1->id()],
    ]);
    $node6->save();
    $usage = \Drupal::service('entity_usage.usage')->listSources($media1);
    $this->assertEquals([], $usage);

    // Test generic settings.
    $this->drupalGet('/admin/config/entity-usage/settings');
    $summary = $assert_session->elementExists('css', '#edit-generic-settings summary');
    $this->assertEquals('Generic', $summary->getText());
    $assert_session->fieldExists('track_enabled_base_fields');
    // It should be off by default.
    $assert_session->checkboxNotChecked('track_enabled_base_fields');
    $assert_session->pageTextContains('Track referencing basefields');
    $assert_session->pageTextContains('If enabled, relationships generated through non-configurable fields (basefields) will also be tracked.');
    // Check the allowed domains element is there.
    $assert_session->elementExists('css', 'textarea[name="site_domains"]');
    $assert_session->elementContains('css', '#edit-generic-settings', 'Domains for this website');
    $assert_session->elementContains('css', '#edit-generic-settings', 'A comma or new-line separated list of domain names for this website. Absolute URL\'s in content will be checked against these domains to allow usage tracking.');
  }

}
