<?php

namespace Drupal\Tests\entity_usage\FunctionalJavascript;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\node\Entity\Node;

/**
 * Tests the configuration form.
 *
 * @package Drupal\Tests\entity_usage\FunctionalJavascript
 *
 * @group entity_usage
 */
class ConfigurationFormTest extends EntityUsageJavascriptTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
  ];

  /**
   * Tests the config form.
   */
  public function testConfigForm() {
    $this->drupalPlaceBlock('local_tasks_block');
    $session = $this->getSession();
    $page = $session->getPage();
    $assert_session = $this->assertSession();

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
    $node = Node::create([
      'type' => 'eu_test_ct',
      'title' => 'Test node',
    ]);
    $node->save();
    $this->drupalGet("/node/{$node->id()}");
    $assert_session->pageTextContains('Usage');
    $page->clickLink('Usage');
    $this->saveHtmlOutput();
    // We should be at /node/*/usage.
    $this->assertTrue(strpos($session->getCurrentUrl(), "/node/{$node->id()}/usage") !== FALSE);
    $assert_session->pageTextContains('Entity usage information for Test node');
    $assert_session->pageTextContains('There are no recorded usages for ');
    // We still have the local tabs available.
    $page->clickLink('View');
    $this->saveHtmlOutput();
    // We should be back at the node view.
    $assert_session->titleEquals('Test node | Drupal');

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
    // @todo Finish me testing the actual functionality.

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
    // @todo Finish me testing the actual functionality.

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
    // @todo Finish me testing the actual functionality.

    // Test generic settings.
    $this->drupalGet('/admin/config/entity-usage/settings');
    $summary = $assert_session->elementExists('css', '#edit-generic-settings summary');
    $this->assertEquals('Generic', $summary->getText());
    $assert_session->fieldExists('track_enabled_base_fields');
    // It should be off by default.
    $assert_session->checkboxNotChecked('track_enabled_base_fields');
    $assert_session->pageTextContains('Track referencing basefields');
    $assert_session->pageTextContains('If enabled, relationships generated through non-configurable fields (basefields) will also be tracked.');
    // @todo Finish me testing the actual functionality.
  }

}
