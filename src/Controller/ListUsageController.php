<?php

namespace Drupal\entity_usage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Tests\Fixtures\Entity;

/**
 * Controller routines for page example routes.
 */
class ListUsageController extends ControllerBase {

  /**
   * Constructs a page with descriptive content.
   *
   * Our router maps this method to the path 'examples/page_example'.
   */
//  public function description() {
//    // Make our links. First the simple page.
//    $page_example_simple_link = Link::createFromRoute($this->t('simple page'), 'page_example_simple')->toString();
//    // Now the arguments page.
//    $arguments_url = Url::fromRoute('page_example_arguments', array('first' => '23', 'second' => '56'));
//    $page_example_arguments_link = Link::fromTextAndUrl($this->t('arguments page'), $arguments_url)->toString();
//
//    // Assemble the markup.
//    $build = array(
//      '#markup' => $this->t('<p>The Page example module provides two pages, "simple" and "arguments".</p><p>The @simple_link just returns a renderable array for display.</p><p>The @arguments_link takes two arguments and displays them, as in @arguments_url</p>',
//        array(
//          '@simple_link' => $page_example_simple_link,
//          '@arguments_link' => $page_example_arguments_link,
//          '@arguments_url' => $arguments_url->toString(),
//        )
//      ),
//    );
//
//    return $build;
//  }


  /**
   * A more complex _controller callback that takes arguments.
   *
   * This callback is mapped to the path
   * 'examples/page_example/arguments/{first}/{second}'.
   *
   * The arguments in brackets are passed to this callback from the page URL.
   * The placeholder names "first" and "second" can have any value but should
   * match the callback method variable names; i.e. $first and $second.
   *
   * This function also demonstrates a more complex render array in the returned
   * values. Instead of rendering the HTML with theme('item_list'), content is
   * left un-rendered, and the theme function name is set using #theme. This
   * content will now be rendered as late as possible, giving more parts of the
   * system a chance to change it if necessary.
   *
   * Consult @link http://drupal.org/node/930760 Render Arrays documentation
   * @endlink for details.
   *
   * @param string $first
   *   A string to use, should be a number.
   * @param string $second
   *   Another string to use, should be a number.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   If the parameters are invalid.
   */
  public function arguments($first, $second) {
    // Make sure you don't trust the URL to be safe! Always check for exploits.
    if (!is_numeric($first) || !is_numeric($second)) {
      // We will just show a standard "access denied" page in this case.
      throw new AccessDeniedHttpException();
    }

    $list[] = $this->t("First number was @number.", array('@number' => $first));
    $list[] = $this->t("Second number was @number.", array('@number' => $second));
    $list[] = $this->t('The total was @number.', array('@number' => $first + $second));

    $render_array['page_example_arguments'] = array(
      // The theme function to apply to the #items.
      '#theme' => 'item_list',
      // The list itself.
      '#items' => $list,
      '#title' => $this->t('Argument Information'),
    );
    return $render_array;
  }

  /**
   * Lists the usage of a given entity.
   *
   * @param string $type
   *   The entity type.
   * @param int $id
   *   The entity ID.
   */
  public function listUsagePage($type, $id) {
    $entity_types = array_keys(\Drupal::entityTypeManager()->getDefinitions());
    if (!is_string($type) || !is_numeric($id) || !in_array($type, $entity_types)) {
      throw new NotFoundHttpException;
    }
    $foo = 'bar';
    $entity = \Drupal::entityTypeManager()->getStorage($type)->load($id);
    if ($entity) {
      $usages = \Drupal::service('entity_usage.usage')->listUsage($entity);
      if (empty($usages)) {
        // Entity exists but not used.
        $build = [
          '#markup' => t('There are no recorded usages for entity of type: @type with id: @id', ['@type' => $type, '@id' => $id]),
        ];
      }
      else {
        // Entity is being used.
        $header = [t('Referencing entity'), t('Referencing entity type'), t('Count')];
        $rows = [];
        foreach ($usages as $re_type => $type_usages) {
          foreach ($type_usages as $re_id => $count) {
            $referencing_entity = \Drupal::entityTypeManager()->getStorage($re_type)->load($re_id);
            if ($referencing_entity) {
              $rows[] = [
                $referencing_entity->toLink(),
                $re_type,
                $count,
              ];
            }
          }
        }
        $build = [
          '#theme' => 'table',
          '#rows' => $rows,
          '#header' => $header,
        ];
      }
    }
    else {
      // Non-existing entity in database.
      $build = [
        '#markup' => t('Could not find the entity of type: @type with id: @id', ['@type' => $type, '@id' => $id]),
      ];
    }
    return $build;
  }

  /**
   * Title page callback.
   *
   * @param string $type
   *   The entity type.
   * @param int $id
   *   The entity id.
   *
   * @return string
   *   The title to be used on this page.
   */
  public function getTitle($type, $id) {
    $entity = \Drupal::entityTypeManager()->getStorage($type)->load($id);
    if ($entity) {
      return t('Entity usage information for @entity_label', ['@entity_label' => $entity->label()]);
    }
    else {
      return t('Entity Usage List');
    }
  }
}

