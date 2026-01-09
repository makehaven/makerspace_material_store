<?php

namespace Drupal\makerspace_material_store\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Store Tab' block.
 *
 * @Block(
 *   id = "makerspace_store_tab_block",
 *   admin_label = @Translation("Store Tab & Actions"),
 *   category = @Translation("MakeHaven"),
 * )
 */
class StoreTabBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Constructs the block.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, RouteMatchInterface $route_match, \Drupal\Core\Form\FormBuilderInterface $form_builder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $node = $this->routeMatch->getParameter('node');
    
    // 1. "Add to Tab" / "Buy Now" Actions for current material.
    if ($node instanceof NodeInterface && $node->bundle() === 'material') {
      $build['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['store-actions', 'mb-3', 'd-grid', 'gap-2']],
      ];

      if ($this->currentUser->isAuthenticated()) {
        $tab_status = $this->getTabStatus();
        
        if ($tab_status['blocked']) {
          $build['actions']['blocked_notice'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['alert', 'alert-warning', 'small', 'mb-2']],
            'message' => [
              '#markup' => '<strong>' . $this->t('Tab Limit Reached') . '</strong><p class="mb-1">' . $tab_status['reason'] . '</p>',
            ],
          ];

          $build['actions']['checkout_now'] = [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fas fa-shopping-cart me-2"></i>' . $this->t('Pay Tab to Continue')),
            '#url' => Url::fromRoute('makerspace_material_store.view_tab'),
            '#attributes' => [
              'class' => ['btn', 'btn-lg', 'btn-success', 'w-100', 'py-2'],
            ],
          ];

          $build['actions']['buy_now_direct'] = [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fab fa-paypal me-2"></i>' . $this->t('Pay for This Item Now')),
            '#url' => Url::fromRoute('makerspace_material_store.buy_now', ['material' => $node->id()]),
            '#attributes' => [
              'class' => ['btn', 'btn-md', 'btn-outline-primary', 'w-100'],
            ],
          ];
        }
        else {
          if ($this->currentUser->hasPermission('use store tab')) {
            $build['actions']['add_to_tab'] = [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fas fa-plus-circle me-2"></i>' . $this->t('Add to My Tab')),
              '#url' => Url::fromRoute('makerspace_material_store.add_to_tab_modal', ['material' => $node->id()]),
              '#attributes' => [
                'class' => ['btn', 'btn-lg', 'btn-primary', 'w-100', 'py-2', 'use-ajax', 'store-add-to-tab-link'],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => json_encode(['width' => 400]),
              ],
            ];
          }

          $build['actions']['buy_now'] = [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fab fa-paypal me-2"></i>' . $this->t('Buy Now')),
            '#url' => Url::fromRoute('makerspace_material_store.buy_now', ['material' => $node->id()]),
            '#attributes' => [
              'class' => ['btn', 'btn-lg', 'btn-outline-primary', 'w-100', 'py-2'],
            ],
          ];
        }
      }
      else {
        $build['login_notice'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['d-grid', 'gap-2', 'text-center']],
          'text' => [
            '#markup' => '<p class="mb-2 small text-muted">' . $this->t('Log in to buy now or add to your tab.') . '</p>',
          ],
          'link' => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fas fa-sign-in-alt me-2"></i>' . $this->t('Log in to Purchase')),
            '#url' => Url::fromRoute('user.login', [], ['query' => ['destination' => \Drupal::request()->getRequestUri()]]),
            '#attributes' => [
              'class' => ['btn', 'btn-lg', 'btn-primary', 'w-100', 'py-2'],
            ],
          ],
        ];
      }
    }

    // 2. Current Tab Summary.
    if ($this->currentUser->hasPermission('use store tab')) {
       try {
         if ($this->entityTypeManager->hasDefinition('material_transaction')) {
           $storage = $this->entityTypeManager->getStorage('material_transaction');
           $query = $storage->getQuery()
             ->condition('field_transaction_owner', $this->currentUser->id())
             ->condition('field_transaction_status', 'pending')
             ->accessCheck(FALSE);
           $ids = $query->execute();
           $count = count($ids);
           
           if ($count > 0) {
             $transactions = $storage->loadMultiple($ids);
             $total = 0;
             foreach ($transactions as $t) {
               $total += (float) $t->get('field_transaction_amount')->value * (float) $t->get('field_quantity')->value;
             }

             $build['tab_summary'] = [
               '#type' => 'container',
               '#attributes' => ['class' => ['store-tab-summary', 'mt-3', 'pt-3', 'border-top']],
               'header' => [
                 '#markup' => '<h5 class="card-title">' . $this->t('My Current Tab') . '</h5>',
               ],
               'details' => [
                 '#markup' => '<div class="d-flex justify-content-between align-items-center mb-3">' . 
                            '<span>' . $this->t('@count items', ['@count' => $count]) . '</span>' .
                            '<span class="h4 mb-0 text-success">$' . number_format($total, 2) . '</span>' .
                            '</div>',
               ],
               'checkout' => [
                 '#type' => 'link',
                 '#title' => Markup::create('<i class="fas fa-shopping-cart me-2"></i>' . $this->t('Review & Checkout')),
                 '#url' => Url::fromRoute('makerspace_material_store.view_tab'),
                 '#attributes' => ['class' => ['btn', 'btn-lg', 'btn-success', 'w-100', 'py-2']],
               ],
             ];
           }
         }
       } catch (\Exception $e) {}
    }

    // 3. History Link.
    if ($this->currentUser->isAuthenticated()) {
      $build['history'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['text-center', 'mt-3']],
        'link' => [
          '#type' => 'link',
          '#title' => Markup::create('<i class="fas fa-history me-1"></i>' . $this->t('View Purchase History')),
          '#url' => Url::fromRoute('makerspace_material_store.purchase_history'),
          '#attributes' => ['class' => ['small', 'text-muted']],
        ],
      ];
    }

    return [
      '#prefix' => '<div class="makerspace-material-store-block card mb-4 shadow-sm" style="background-color: #ffffff; border-top: 4px solid #0056b3;"><div class="card-body p-3">',
      '#suffix' => '</div></div>',
      'content' => $build,
      '#attached' => [
        'library' => [
          'core/drupal.dialog',
          'core/drupal.dialog.ajax',
          'core/drupal.ajax',
          'bootstrap_barrio/fontawesome',
          'makerspace_material_store/store_ui',
        ],
      ],
    ];
  }

  /**
   * Calculates current tab status and limits.
   */
  protected function getTabStatus() {
    $status = [
      'blocked' => FALSE,
      'reason' => '',
      'total' => 0,
      'oldest_days' => 0,
    ];

    if (!$this->currentUser->isAuthenticated()) {
      return $status;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('material_transaction');
      $query = $storage->getQuery()
        ->condition('field_transaction_owner', $this->currentUser->id())
        ->condition('field_transaction_status', 'pending')
        ->sort('created', 'ASC')
        ->accessCheck(FALSE);
      $ids = $query->execute();

      if (!empty($ids)) {
        $transactions = $storage->loadMultiple($ids);
        $oldest = NULL;
        foreach ($transactions as $t) {
          $qty = (int) $t->get('field_quantity')->value;
          $price = (float) $t->get('field_transaction_amount')->value;
          $status['total'] += ($qty * $price);
          if ($oldest === NULL) {
            $oldest = (int) $t->get('created')->value;
          }
        }

        if ($oldest) {
          $status['oldest_days'] = floor((time() - $oldest) / 86400);
        }
      }

      // Check Limits.
      $config = \Drupal::config('makerspace_material_store.settings');
      $max_amount = (float) $config->get('max_tab_amount');
      $max_days = (int) $config->get('max_tab_days');

      if ($max_amount > 0 && $status['total'] > $max_amount) {
        $status['blocked'] = TRUE;
        $status['reason'] = $this->t('Current balance ($@total) exceeds the $@max limit.', [
          '@total' => number_format($status['total'], 2),
          '@max' => number_format($max_amount, 2),
        ]);
      }
      elseif ($max_days > 0 && $status['oldest_days'] > $max_days) {
        $status['blocked'] = TRUE;
        $status['reason'] = $this->t('You have pending items that are @days days old (Max: @max).', [
          '@days' => $status['oldest_days'],
          '@max' => $max_days,
        ]);
      }
    }
    catch (\Exception $e) {}

    return $status;
  }
}
