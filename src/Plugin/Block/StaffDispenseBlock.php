<?php

namespace Drupal\Makerspace_material_store\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Staff Material Dispense' block.
 *
 * @Block(
 *   id = "makerspace_staff_dispense_block",
 *   admin_label = @Translation("Staff Material Dispense"),
 *   category = @Translation("MakeHaven"),
 * )
 */
class StaffDispenseBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs the block.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    
    if (!$node instanceof NodeInterface || $node->bundle() !== 'material') {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['makerspace-staff-dispense-block', 'card', 'mb-4', 'shadow-sm'],
        'style' => 'background-color: #fffdf5; border-top: 4px solid #ffc107;',
      ],
      'content' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['card-body', 'p-3']],
        'header' => [
          '#markup' => '<h5 class="card-title text-dark">' . $this->t('Staff Actions') . '</h5>',
        ],
        'dispense' => [
          '#type' => 'link',
          '#title' => Markup::create('<i class="fas fa-hand-holding me-2"></i>' . $this->t('Dispense (Internal/Edu)')),
          '#url' => Url::fromRoute('makerspace_material_store.dispense_form', ['material' => $node->id()]),
          '#attributes' => [
            'class' => ['btn', 'btn-warning', 'btn-lg', 'w-100', 'text-dark', 'fw-bold', 'py-2'],
          ],
        ],
      ],
    ];

    return $build;
  }
}
