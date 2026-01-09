<?php

namespace Drupal\makerspace_material_store\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\makerspace_material_store\Service\StorePaymentService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for store actions.
 */
class StoreController extends ControllerBase {

  /**
   * Serves the AddToTabForm as a standalone checkout page.
   */
  public function checkoutItemPage(NodeInterface $material) {
    if ($material->bundle() !== 'material') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    
    $build['form'] = $this->formBuilder()->getForm('Drupal\makerspace_material_store\Form\AddToTabForm', $material);
    
    // Add some page context.
    $build['#title'] = $this->t('Purchase: @name', ['@name' => $material->label()]);
    
    return $build;
  }

  /**
   * API Endpoint to add an item to a user's tab.
   *
   * Expects JSON: { "uid": 123, "material_id": 456, "quantity": 1.5, "memo": "Waterjet use" }
   */
  public function apiAddTabItem(Request $request) {
    $config = $this->config('makerspace_material_store.settings');
    $configured_key = $config->get('api_key');
    $provided_key = $request->headers->get('X-Store-API-Key') ?: $request->query->get('api_key');

    if (empty($configured_key) || $provided_key !== $configured_key) {
      return new JsonResponse(['error' => 'Invalid API Key'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!$data) {
      return new JsonResponse(['error' => 'Invalid JSON'], 400);
    }

    $uid = $data['uid'] ?? NULL;
    $material_id = $data['material_id'] ?? NULL;
    $qty = $data['quantity'] ?? 1;
    $memo = $data['memo'] ?? '';

    if (!$uid || !$material_id) {
      return new JsonResponse(['error' => 'Missing uid or material_id'], 400);
    }

    /** @var \Drupal\node\NodeInterface $material */
    $material = $this->entityTypeManager->getStorage('node')->load($material_id);
    if (!$material || $material->bundle() !== 'material') {
      return new JsonResponse(['error' => 'Invalid material ID'], 404);
    }

    try {
      // Create Transaction.
      $transaction = $this->entityTypeManager->getStorage('material_transaction')->create([
        'type' => 'purchase',
        'field_material_ref' => $material->id(),
        'field_quantity' => $qty,
        'field_transaction_status' => 'pending',
        'field_transaction_owner' => $uid,
        'field_transaction_amount' => $material->get('field_material_sales_cost')->value,
        'title' => $this->t('Auto Tab: @item', ['@item' => $material->label()]),
      ]);
      $transaction->save();

      // Deduct Inventory.
      $this->entityTypeManager->getStorage('material_inventory')->create([
        'type' => 'inventory_adjustment',
        'field_inventory_ref_material' => $material->id(),
        'field_inventory_quantity_change' => -$qty,
        'field_inventory_change_reason' => 'unpaid_tab',
        'field_inventory_change_memo' => $memo ?: $this->t('Auto-added via API'),
      ])->save();

      return new JsonResponse([
        'success' => TRUE,
        'transaction_id' => $transaction->id(),
        'message' => 'Item added to tab.',
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

  /**
   * Returns the Add to Tab form in a modal.
   */
  public function addToTabModal(NodeInterface $material) {
    $form = $this->formBuilder()->getForm('Drupal\makerspace_material_store\Form\AddToTabForm', $material);
    $response = new AjaxResponse();
    $response->addCommand(new OpenModalDialogCommand($this->t('Add to Tab'), $form, ['width' => '400']));
    return $response;
  }

  /**
   * The payment service.
   *
   * @var \Drupal\makerspace_material_store\Service\StorePaymentService
   */
  protected $paymentService;

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
   * Constructs the controller.
   */
  public function __construct(StorePaymentService $payment_service, EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user) {
    $this->paymentService = $payment_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_material_store.payment_service'),
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Redirects to PayPal for a single item "Buy Now".
   */
  public function buyNow(NodeInterface $material) {
    if ($material->bundle() !== 'material') {
      $this->messenger()->addError($this->t('Invalid material.'));
      return $this->redirect('<front>');
    }

    $url = $this->paymentService->getBuyNowUrl($material, 1);
    
    return new TrustedRedirectResponse($url);
  }

  /**
   * Adds an item to the user's "Tab".
   */
  public function addToTab(NodeInterface $material, Request $request) {
    if ($material->bundle() !== 'material') {
      $this->messenger()->addError($this->t('Invalid material.'));
      return $this->redirect('<front>');
    }

    $qty = $request->query->get('qty') ?: 1;
    if ($qty < 1) $qty = 1;

    // Check if material_transaction entity exists.
    try {
      $storage = $this->entityTypeManager->getStorage('material_transaction');
      $transaction = $storage->create([
        'type' => 'purchase',
        'field_material_ref' => $material->id(),
        'field_quantity' => $qty,
        'field_transaction_status' => 'pending',
        'field_transaction_owner' => $this->currentUser->id(),
        'field_transaction_amount' => $material->get('field_material_sales_cost')->value,
        'title' => $this->t('Tab Item: @item', ['@item' => $material->label()]),
      ]);
      $transaction->save();

      // Immediately deduct from inventory as "Unpaid Tab".
      $this->entityTypeManager->getStorage('material_inventory')->create([
        'type' => 'inventory_adjustment',
        'field_inventory_ref_material' => $material->id(),
        'field_inventory_quantity_change' => -$qty,
        'field_inventory_change_reason' => 'unpaid_tab',
        'field_inventory_change_memo' => $this->t('Added to tab by user @uid', ['@uid' => $this->currentUser->id()]),
      ])->save();

      $this->messenger()->addStatus($this->t('Added @qty x @item to your tab.', [
        '@qty' => $qty,
        '@item' => $material->label(),
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Could not add to tab: @error', ['@error' => $e->getMessage()]));
    }

    // Redirect back to store or material page.
    $referer = \Drupal::request()->headers->get('referer');
    if ($referer) {
      return new RedirectResponse($referer);
    }
    return $this->redirect('entity.node.canonical', ['node' => $material->id()]);
  }

  /**
   * Checks out all pending items in the user's tab.
   */
  public function checkoutTab() {
    // Fetch pending transactions for user.
    $storage = $this->entityTypeManager->getStorage('material_transaction');
    $query = $storage->getQuery()
      ->condition('field_transaction_owner', $this->currentUser->id())
      ->condition('field_transaction_status', 'pending')
      ->accessCheck(FALSE);
    $ids = $query->execute();

    if (empty($ids)) {
      $this->messenger()->addMessage($this->t('Your tab is empty.'));
      return $this->redirect('<front>'); // Or redirect to store.
    }

    $transactions = $storage->loadMultiple($ids);
    $items = [];
    $material_storage = $this->entityTypeManager->getStorage('node');

    foreach ($transactions as $transaction) {
      $material_id = $transaction->get('field_material_ref')->target_id;
      $material = $material_storage->load($material_id);
      
      if ($material) {
        $price = (float) $transaction->get('field_transaction_amount')->value;
        
        // If the item is free ($0.00), mark it paid immediately so it doesn't get stuck.
        if ($price <= 0) {
          $transaction->set('field_transaction_status', 'paid');
          $transaction->save();
          continue;
        }

        $items[] = [
          'material' => $material,
          'quantity' => (int) $transaction->get('field_quantity')->value,
          'price' => $price,
        ];
      }
    }

    $url = $this->paymentService->getCartUploadUrl($items);
    
    return new TrustedRedirectResponse($url);
  }

  /**
   * Displays the user's current tab.
   */
  public function viewTab() {
    $storage = $this->entityTypeManager->getStorage('material_transaction');
    $query = $storage->getQuery()
      ->condition('field_transaction_owner', $this->currentUser->id())
      ->condition('field_transaction_status', 'pending')
      ->accessCheck(FALSE);
    $ids = $query->execute();

    if (empty($ids)) {
      return [
        '#markup' => '<div class="alert alert-info py-4 text-center">' . 
                     '<h4>' . $this->t('Your tab is empty.') . '</h4>' .
                     '<a href="/store" class="btn btn-primary mt-2">' . $this->t('Go to Store') . '</a>' .
                     '</div>',
      ];
    }

    $transactions = $storage->loadMultiple($ids);
    $items_build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['list-group', 'mb-4', 'shadow-sm']],
    ];

    $grand_total = 0;

    foreach ($transactions as $transaction) {
      $material_id = $transaction->get('field_material_ref')->target_id;
      /** @var \Drupal\node\NodeInterface $material */
      $material = $this->entityTypeManager->getStorage('node')->load($material_id);
      $qty = (int) $transaction->get('field_quantity')->value;
      $price = (float) $transaction->get('field_transaction_amount')->value;
      $row_total = $qty * $price;
      $grand_total += $row_total;

      $remove_url = Url::fromRoute('makerspace_material_store.remove_from_tab', ['material_transaction' => $transaction->id()]);

      // Handle Image.
      $image_render = [];
      if ($material && $material->hasField('field_material_image') && !$material->get('field_material_image')->isEmpty()) {
        $image_render = [
          '#theme' => 'image_style',
          '#style_name' => 'thumbnail',
          '#uri' => $material->get('field_material_image')->entity->getFileUri(),
          '#attributes' => ['class' => ['img-thumbnail', 'me-3'], 'style' => 'width: 60px; height: 60px; object-fit: cover;'],
        ];
      }
      else {
        // Placeholder.
        $image_render = [
          '#markup' => '<div class="bg-light border text-muted d-flex align-items-center justify-content-center me-3" style="width: 60px; height: 60px;"><i class="fas fa-box small"></i></div>',
        ];
      }

      $items_build['item_' . $transaction->id()] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['list-group-item', 'p-3']],
        'row' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['d-flex', 'align-items-center', 'justify-content-between', 'flex-wrap']],
          'left' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['d-flex', 'align-items-center', 'flex-grow-1']],
            'image' => $image_render,
            'info' => [
              '#type' => 'container',
              'title' => [
                '#markup' => '<h5 class="mb-0">' . ($material ? $material->toLink()->toString() : $this->t('Unknown Material')) . '</h5>',
              ],
              'meta' => [
                '#markup' => '<div class="small text-muted">' . $this->t('@qty x $@price each', [
                  '@qty' => $qty,
                  '@price' => number_format($price, 2),
                ]) . ' &bull; ' . $this->t('Added @date', ['@date' => date('M d, Y', $transaction->get('created')->value)]) . '</div>',
              ],
            ],
          ],
          'right' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['text-end', 'mt-2', 'mt-sm-0']],
            'total' => [
              '#markup' => '<div class="h5 mb-1 text-success">$' . number_format($row_total, 2) . '</div>',
            ],
            'remove' => [
              '#type' => 'link',
              '#title' => Markup::create('<i class="fas fa-times me-1"></i>' . $this->t('Remove')),
              '#url' => $remove_url,
              '#attributes' => [
                'class' => ['btn', 'btn-sm', 'btn-outline-danger'],
                'onclick' => 'return confirm("' . $this->t('Are you sure you want to remove this? Only remove items added by mistake. A record of this removal will be kept and inventory will be put back on the system count.') . '");',
              ],
            ],
          ],
        ],
      ];
    }

    $build['items'] = $items_build;

    $build['summary_card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'bg-light', 'border-primary', 'mb-4']],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['card-body', 'text-center']],
        'total' => [
          '#markup' => '<div class="display-6 mb-3">' . $this->t('Total Due: <span class="text-success">$@total</span>', ['@total' => number_format($grand_total, 2)]) . '</div>',
        ],
        'checkout' => [
          '#type' => 'link',
          '#title' => Markup::create('<i class="fas fa-shopping-cart me-2"></i>' . $this->t('Checkout All Items (PayPal)')),
          '#url' => Url::fromRoute('makerspace_material_store.checkout_tab'),
          '#attributes' => ['class' => ['btn', 'btn-success', 'btn-lg', 'w-100', 'py-3']],
        ],
      ],
    ];

    return $build;
  }

    /**

     * Removes a transaction from the tab and refunds inventory.

     */

    public function removeFromTab(\Drupal\Core\Entity\ContentEntityInterface $material_transaction) {

      if (!$material_transaction) {

        $this->messenger()->addError($this->t('Transaction not found.'));

        return new RedirectResponse(Url::fromRoute('makerspace_material_store.view_tab')->toString());

      }

  

      // Access check.

      if ($material_transaction->get('field_transaction_owner')->target_id != $this->currentUser->id() && !$this->currentUser->hasPermission('administer store transactions')) {

        $this->messenger()->addError($this->t('Access denied.'));

        return new RedirectResponse(Url::fromRoute('makerspace_material_store.view_tab')->toString());

      }

  

      if ($material_transaction->get('field_transaction_status')->value !== 'pending') {

        $this->messenger()->addError($this->t('Cannot remove items that are not pending.'));

        return new RedirectResponse(Url::fromRoute('makerspace_material_store.view_tab')->toString());

      }

  

      $qty = (int) $material_transaction->get('field_quantity')->value;

      $material_id = $material_transaction->get('field_material_ref')->target_id;

  

      // Refund Inventory.

      try {

        $this->entityTypeManager->getStorage('material_inventory')->create([

          'type' => 'inventory_adjustment',

          'field_inventory_ref_material' => $material_id,

          'field_inventory_quantity_change' => $qty, // Positive to put back

          'field_inventory_change_reason' => 'restock',

          'field_inventory_change_memo' => $this->t('Removed from tab (Mistake) by user @uid', ['@uid' => $this->currentUser->id()]),

        ])->save();

  

        // Mark as removed instead of deleting, to keep a history of mistakes.

        $material_transaction->set('field_transaction_status', 'removed');

        $material_transaction->save();

  

        $this->messenger()->addStatus($this->t('Item marked as removed (mistake) and inventory refunded.'));

      } catch (\Exception $e) {

        $this->messenger()->addError($this->t('Error removing item: @msg', ['@msg' => $msg = $e->getMessage()]));

      }

  

      return new RedirectResponse(Url::fromRoute('makerspace_material_store.view_tab')->toString());

    }

    

        /**

    

         * Displays the user's purchase history.

    

         */

    

        public function viewHistory() {

    

          $storage = $this->entityTypeManager->getStorage('material_transaction');

    

          $query = $storage->getQuery()

    

            ->condition('field_transaction_owner', $this->currentUser->id())

    

            ->condition('field_transaction_status', 'paid')

    

            ->sort('created', 'DESC')

    

            ->accessCheck(FALSE);

    

          $ids = $query->execute();

    

      

    

          if (empty($ids)) {

    

            return [

    

              '#markup' => '<div class="alert alert-light py-4 text-center border">' . 

    

                           '<h4>' . $this->t('No purchase history found.') . '</h4>' .

    

                           '<a href="/store" class="btn btn-outline-primary mt-2">' . $this->t('Go to Store') . '</a>' .

    

                           '</div>',

    

            ];

    

          }

    

      

    

          $transactions = $storage->loadMultiple($ids);

    

          $build['list'] = [

    

            '#type' => 'container',

    

            '#attributes' => ['class' => ['list-group', 'shadow-sm']],

    

          ];

    

      

    

          foreach ($transactions as $transaction) {

    

            $material_id = $transaction->get('field_material_ref')->target_id;

    

            /** @var \Drupal\node\NodeInterface $material */

    

            $material = $this->entityTypeManager->getStorage('node')->load($material_id);

    

            $qty = (int) $transaction->get('field_quantity')->value;

    

            $price = (float) $transaction->get('field_transaction_amount')->value;

    

            $row_total = $qty * $price;

    

      

    

            // Handle Image.

    

            $image_render = [];

    

            if ($material && $material->hasField('field_material_image') && !$material->get('field_material_image')->isEmpty()) {

    

              $image_render = [

    

                '#theme' => 'image_style',

    

                '#style_name' => 'thumbnail',

    

                '#uri' => $material->get('field_material_image')->entity->getFileUri(),

    

                '#attributes' => ['class' => ['img-thumbnail', 'me-3'], 'style' => 'width: 50px; height: 50px; object-fit: cover;'],

    

              ];

    

            }

    

            else {

    

              $image_render = [

    

                '#markup' => '<div class="bg-light border text-muted d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;"><i class="fas fa-history small"></i></div>',

    

              ];

    

            }

    

      

    

            $build['list']['item_' . $transaction->id()] = [

    

              '#type' => 'container',

    

              '#attributes' => ['class' => ['list-group-item', 'p-3']],

    

              'row' => [

    

                '#type' => 'container',

    

                '#attributes' => ['class' => ['d-flex', 'align-items-center', 'justify-content-between']],

    

                'left' => [

    

                  '#type' => 'container',

    

                  '#attributes' => ['class' => ['d-flex', 'align-items-center']],

    

                  'image' => $image_render,

    

                  'info' => [

    

                    '#type' => 'container',

    

                    'title' => [

    

                      '#markup' => '<div class="fw-bold">' . ($material ? $material->toLink()->toString() : $this->t('Unknown Material')) . '</div>',

    

                    ],

    

                                  'date' => [

    

                                    '#markup' => '<div class="small text-muted">' . $this->t('Paid @date', ['@date' => date('M d, Y', $transaction->get('created')->value)]) . ' &bull; ' . $this->t('@qty units', ['@qty' => $qty]) . '</div>',

    

                                  ],

    

                  ],

    

                ],

    

                'right' => [

    

                  '#type' => 'container',

    

                  '#attributes' => ['class' => ['text-end']],

    

                  'total' => [
                    '#markup' => '<div class="fw-bold text-dark">$' . number_format($row_total, 2) . '</div>',
                  ],

    

                  'status' => [

    

                    '#markup' => '<span class="badge bg-success small">' . $this->t('Paid') . '</span>',

    

                  ],

    

                ],

    

              ],

    

            ];

    

          }

    

      

    

          $build['actions'] = [

    

            '#type' => 'container',

    

            '#attributes' => ['class' => ['mt-4']],

    

            'back' => [

    

              '#type' => 'link',

    

              '#title' => $this->t('Back to Store'),

    

              '#url' => Url::fromRoute('<front>'),

    

              '#attributes' => ['class' => ['btn', 'btn-secondary']],

    

            ],

    

          ];

    

      

    

          return $build;

    

        }

    

    }
