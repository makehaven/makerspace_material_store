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
use Drupal\makerspace_material_store\Service\TabLimitService;
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
   * The tab limit service.
   *
   * @var \Drupal\makerspace_material_store\Service\TabLimitService
   */
  protected $tabLimitService;

  /**
   * Constructs the block.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, RouteMatchInterface $route_match, \Drupal\Core\Form\FormBuilderInterface $form_builder, TabLimitService $tab_limit_service) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
    $this->formBuilder = $form_builder;
    $this->tabLimitService = $tab_limit_service;
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
      $container->get('form_builder'),
      $container->get('makerspace_material_store.tab_limit')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $node = $this->routeMatch->getParameter('node');
    $material_param = $this->routeMatch->getParameter('material');
    
    $current_material = NULL;

    // 1. Try 'node' parameter (Object or ID).
    if ($node instanceof NodeInterface) {
      $current_material = $node;
    } elseif (is_numeric($node)) {
      $current_material = $this->entityTypeManager->getStorage('node')->load($node);
    }

    // 2. Try 'material' parameter.
    if (!$current_material) {
      if ($material_param instanceof NodeInterface) {
        $current_material = $material_param;
      } elseif (is_numeric($material_param)) {
        $current_material = $this->entityTypeManager->getStorage('node')->load($material_param);
      }
    }
    
    // 3. Fallback: Check URL arguments (View support).
    if (!$current_material) {
      $arg_0 = $this->routeMatch->getParameter('arg_0');
      
      if ($arg_0) {
        if (is_numeric($arg_0)) {
          // Argument is NID.
          $current_material = $this->entityTypeManager->getStorage('node')->load($arg_0);
        }
        elseif (is_string($arg_0)) {
          // Argument might be an alias (e.g. /store/material/my-slug).
          // We need to resolve the alias to an internal path.
          // Note: The route might be /store/material/{arg_0}.
          $path = '/store/material/' . $arg_0; // Optimistic guess based on user report
          
          // Better approach: Check if the current path alias resolves to a node.
          $current_path = \Drupal::service('path.current')->getPath();
          $params = Url::fromUri("internal:" . $current_path)->getRouteParameters();
          if (isset($params['node'])) {
             $current_material = $this->entityTypeManager->getStorage('node')->load($params['node']);
          }
          
          // If that didn't work (because we are on a View route that masks the path),
          // try to look up the alias of the *argument* specifically if we assume it's a material name?
          // Actually, if it's a View taking a slug, resolving the slug is complex without knowing the pattern.
          
          // However, if the user sees /store/material/slug, and that IS the alias of the node:
          if (!$current_material) {
             // Try to find a node where the alias matches the current path?
             // That's expensive.
             
             // Let's assume the View passes the NID as a hidden argument or the block is just context aware.
             // But if the previous code worked, it means $node was available.
             
             // Let's try one specific thing: The 'node' parameter might be 'material' in some custom routes.
             // We handled that above.
          }
        }
      }
    }
    
    // 1. "Add to Tab" / "Buy Now" Actions for current material.
    if ($current_material && $current_material instanceof NodeInterface && $current_material->bundle() === 'material') {
      $build['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['store-actions', 'mb-3', 'd-grid', 'gap-2']],
      ];

      if ($this->currentUser->isAuthenticated()) {
        $tab_status = $this->tabLimitService->getStatus($this->currentUser, 0.0, ['skip_terms' => TRUE]);
        $config = \Drupal::config('makerspace_material_store.settings');
        $require_terms = (bool) $config->get('require_terms_acceptance');
        $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
        $terms_accepted = $user && $user->hasField('field_store_tab_terms_accepted') && (bool) $user->get('field_store_tab_terms_accepted')->value;

        if ($this->currentUser->hasPermission('use store tab') && $tab_status['eligible'] && !$tab_status['blocked'] && $require_terms && !$terms_accepted) {
          $build['actions']['terms_notice'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['alert', 'alert-info', 'small', 'mb-2']],
            'message' => [
              '#markup' => '<strong>' . $this->t('Set Up a Tab') . '</strong><p class="mb-1">' . $this->t('Sign up to have a tab paid on a schedule. Open “Add to Tab” to review and accept the terms.') . '</p>',
            ],
          ];
        }
        
        // CASE 1: NOT ELIGIBLE (No Stripe Account, etc.)
        if (isset($tab_status['eligible']) && !$tab_status['eligible']) {
          $build['actions']['buy_now'] = [
            '#type' => 'link',
            '#title' => Markup::create('<i class="far fa-credit-card me-2"></i>' . $this->t('Buy Now')),
            '#url' => Url::fromRoute('makerspace_material_store.buy_now', ['material' => $current_material->id()]),
            '#attributes' => [
              'class' => ['btn', 'btn-lg', 'btn-outline-primary', 'w-100', 'py-2'],
            ],
          ];

          $build['actions']['add_to_cart'] = [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fab fa-paypal me-2"></i>' . $this->t('Add to Cart')),
            '#url' => Url::fromRoute('makerspace_material_store.add_to_cart', ['material' => $current_material->id()]),
            '#attributes' => [
              'class' => ['btn', 'btn-lg', 'btn-outline-secondary', 'w-100', 'py-2'],
            ],
          ];
        }
        // CASE 2: ELIGIBLE BUT BLOCKED (Limit reached)
        elseif ($tab_status['blocked']) {
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
            '#title' => Markup::create('<i class="far fa-credit-card me-2"></i>' . $this->t('Pay for This Item Now')),
            '#url' => Url::fromRoute('makerspace_material_store.buy_now', ['material' => $current_material->id()]),
            '#attributes' => [
              'class' => ['btn', 'btn-md', 'btn-outline-primary', 'w-100'],
            ],
          ];
        }
        // CASE 3: ELIGIBLE AND ACTIVE
        else {
          // Standard Actions.
          
          // 1. Buy Now (Credit Card) - Always visible.
          $build['actions']['buy_now'] = [
            '#type' => 'link',
            '#title' => Markup::create('<i class="far fa-credit-card me-2"></i>' . $this->t('Buy Now')),
            '#url' => Url::fromRoute('makerspace_material_store.buy_now', ['material' => $current_material->id()]),
            '#attributes' => [
              'class' => ['btn', 'btn-lg', 'btn-outline-primary', 'w-100', 'py-2'],
            ],
          ];

          // 2. Add to Tab.
          if ($this->currentUser->hasPermission('use store tab')) {
            $build['actions']['add_to_tab'] = [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fas fa-plus-circle me-2"></i>' . $this->t('Add to My Tab')),
              '#url' => Url::fromRoute('makerspace_material_store.add_to_tab_modal', ['material' => $current_material->id()]),
              '#attributes' => [
                'class' => ['btn', 'btn-lg', 'btn-primary', 'w-100', 'py-2', 'use-ajax', 'store-add-to-tab-link'],
                'data-dialog-type' => 'modal',
                'data-dialog-options' => json_encode(['width' => 400]),
              ],
            ];
          }
          else {
            // Fallback for permissions issue (though usually covered by 'eligible' logic if permissions were checked there).
            // But if they have Stripe but NO permission, fallback to Cart.
             $build['actions']['add_to_cart'] = [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fab fa-paypal me-2"></i>' . $this->t('Add to Cart')),
              '#url' => Url::fromRoute('makerspace_material_store.add_to_cart', ['material' => $current_material->id()]),
              '#attributes' => [
                'class' => ['btn', 'btn-lg', 'btn-outline-secondary', 'w-100', 'py-2'],
              ],
            ];
          }
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
    $tab_status = $this->currentUser->isAuthenticated()
      ? $this->tabLimitService->getStatus($this->currentUser, 0.0, ['skip_terms' => TRUE])
      : ['eligible' => FALSE];

    if ($this->currentUser->hasPermission('use store tab') && !empty($tab_status['eligible'])) {
       try {
         if ($this->entityTypeManager->hasDefinition('material_transaction')) {
           $storage = $this->entityTypeManager->getStorage('material_transaction');
           $query = $storage->getQuery()
             ->condition('field_transaction_owner', $this->currentUser->id())
             ->condition('field_transaction_status', 'pending')
             ->accessCheck(FALSE);
           $ids = $query->execute();
           $count = count($ids);
           
           $total = 0;
           if ($count > 0) {
             $transactions = $storage->loadMultiple($ids);
             foreach ($transactions as $t) {
               $total += (float) $t->get('field_transaction_amount')->value * (float) $t->get('field_quantity')->value;
             }
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
                          '<span class="h4 mb-0 ' . ($total > 0 ? 'text-success' : 'text-muted') . '">$' . number_format($total, 2) . '</span>' .
                          '</div>',
             ],
             'checkout' => [
               '#type' => 'link',
               '#title' => Markup::create('<i class="fas fa-shopping-cart me-2"></i>' . $this->t('Review & Checkout')),
               '#url' => Url::fromRoute('makerspace_material_store.view_tab'),
               '#attributes' => ['class' => ['btn', 'btn-lg', ($count > 0 ? 'btn-success' : 'btn-outline-secondary'), 'w-100', 'py-2']],
             ],
           ];
         }
       } catch (\Exception $e) {}
    }
    elseif ($this->currentUser->isAuthenticated()) {
       // Show PayPal Cart link for users without tab permission
       // Standard PayPal view cart link
       $config = \Drupal::config('makerspace_material_store.settings');
       $business = $config->get('paypal_business_id') ?: 'info@makehaven.org';
       $cart_url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_cart&business=' . urlencode($business) . '&display=1';
       
       $build['tab_summary'] = [
         '#type' => 'container',
         '#attributes' => ['class' => ['store-tab-summary', 'mt-3', 'pt-3', 'border-top']],
         'checkout' => [
           '#type' => 'link',
           '#title' => Markup::create('<i class="fab fa-paypal me-2"></i>' . $this->t('View PayPal Cart')),
           '#url' => Url::fromUri($cart_url),
           '#attributes' => ['class' => ['btn', 'btn-lg', 'btn-outline-primary', 'w-100', 'py-2']],
         ],
       ];
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
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return [
      'route',
      'url.path',
      'user',
      'user.permissions',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }
}
