<?php

namespace Drupal\makerspace_material_store\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\makerspace_material_store\Service\StorePaymentService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to add items to tab or buy now.
 */
class AddToTabForm extends FormBase {

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
   * The payment service.
   *
   * @var \Drupal\makerspace_material_store\Service\StorePaymentService
   */
  protected $paymentService;

  /**
   * Constructs the form.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, StorePaymentService $payment_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->paymentService = $payment_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('makerspace_material_store.payment_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makerspace_material_store_add_to_tab_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $material = NULL) {
    if (!$material) {
      return ['#markup' => $this->t('Invalid material.')];
    }
    
    $form_state->set('material', $material);
    $form['#attributes']['class'][] = 'makerspace-store-add-form';
    $form['#attached']['library'][] = 'makerspace_material_store/store_ui';

    $default_qty = \Drupal::request()->query->get('qty') ?: 1;

    // Only show header if NOT in a modal (determined by request).
    if (!\Drupal::request()->isXmlHttpRequest()) {
      $form['store_header'] = [
        '#type' => 'markup',
        '#markup' => '<h4 class="mt-2 mb-3 border-bottom pb-2">' . $this->t('Purchase: @label', ['@label' => $material->label()]) . '</h4>',
      ];
    }

    $form['quantity_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mb-3']],
    ];

    $form['quantity_wrapper']['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#default_value' => $default_qty,
      '#step' => 'any',
      '#min' => 0.01,
      '#required' => TRUE,
      '#attributes' => [
        'class' => ['form-control', 'form-control-lg'],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mt-3', 'd-grid', 'gap-2']],
    ];

    if ($this->currentUser->hasPermission('use store tab')) {
      $form['actions']['add_to_tab'] = [
        '#type' => 'submit',
        '#value' => Markup::create('<i class="fas fa-plus-circle me-2"></i>' . $this->t('Add to My Tab')),
        '#submit' => ['::submitAddToTab'],
        '#ajax' => [
          'callback' => '::ajaxSubmit',
        ],
        '#attributes' => [
          'class' => ['btn', 'btn-lg', 'btn-primary', 'w-100', 'py-2'],
        ],
      ];
    }

    $form['actions']['buy_now'] = [
      '#type' => 'submit',
      '#value' => Markup::create('<i class="fab fa-paypal me-2"></i>' . $this->t('Buy Now (PayPal)')),
      '#attributes' => [
        'class' => ['btn', 'btn-lg', 'btn-outline-primary', 'w-100', 'py-2'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This is the default handler, used for "Buy Now".
    $material = $form_state->get('material');
    $qty = (float) $form_state->getValue('quantity');
    
    $url = $this->paymentService->getBuyNowUrl($material, $qty);
    $form_state->setResponse(new \Drupal\Core\Routing\TrustedRedirectResponse($url));
  }

  /**
   * Submit handler for "Add to Tab".
   */
  public function submitAddToTab(array &$form, FormStateInterface $form_state) {
    $material = $form_state->get('material');
    $qty = (float) $form_state->getValue('quantity');

    try {
      // Create Transaction.
      $transaction = $this->entityTypeManager->getStorage('material_transaction')->create([
        'type' => 'purchase',
        'field_material_ref' => $material->id(),
        'field_quantity' => $qty,
        'field_transaction_status' => 'pending',
        'field_transaction_owner' => $this->currentUser->id(),
        'field_transaction_amount' => $material->get('field_material_sales_cost')->value,
        'title' => $this->t('Tab Item: @item', ['@item' => $material->label()]),
      ]);
      $transaction->save();

      // Deduct Inventory immediately.
      $this->entityTypeManager->getStorage('material_inventory')->create([
        'type' => 'inventory_adjustment',
        'field_inventory_ref_material' => $material->id(),
        'field_inventory_quantity_change' => -$qty,
        'field_inventory_change_reason' => 'unpaid_tab',
        'field_inventory_change_memo' => $this->t('Added to tab by user @uid', ['@uid' => $this->currentUser->id()]),
      ])->save();

      $this->messenger()->addStatus($this->t('Added @qty x @item to your tab. <a href=":url">View Tab</a>', [
        '@qty' => $qty,
        '@item' => $material->label(),
        ':url' => Url::fromRoute('makerspace_material_store.view_tab')->toString(),
      ]));
      
      // We rely on the block to show the updated tab summary.
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * AJAX callback for form submission.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    if ($form_state->hasAnyErrors()) {
      return $form;
    }
    
    $response->addCommand(new CloseModalDialogCommand());
    // Redirect back to original page to refresh messages and tab summary.
    $referer = \Drupal::request()->headers->get('referer');
    $response->addCommand(new RedirectCommand($referer));
    return $response;
  }

}
