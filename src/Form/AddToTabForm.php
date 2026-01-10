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
use Drupal\makerspace_material_store\Service\TabLimitService;
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
   * Tab limit service.
   *
   * @var \Drupal\makerspace_material_store\Service\TabLimitService
   */
  protected $tabLimitService;

  /**
   * Constructs the form.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountInterface $current_user, StorePaymentService $payment_service, TabLimitService $tab_limit_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->paymentService = $payment_service;
    $this->tabLimitService = $tab_limit_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('makerspace_material_store.payment_service'),
      $container->get('makerspace_material_store.tab_limit')
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
    $unit_cost = $this->getMaterialUnitCost($material);

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

    $pending_total = $unit_cost * (float) $default_qty;
    $limit_status = $this->tabLimitService->getStatus($this->currentUser, $pending_total);

    if ($limit_status['blocked']) {
      $form['limit_notice'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['alert', 'alert-warning']],
        'message' => [
          '#markup' => '<strong>' . $this->t('Tab Limit Reached') . '</strong><p class="mb-0">' . $limit_status['reason'] . '</p>',
        ],
      ];
    }

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

      if ($limit_status['blocked']) {
        $form['actions']['add_to_tab']['#disabled'] = TRUE;
      }
    }

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
    $unit_cost = $this->getMaterialUnitCost($material);
    $pending_total = $unit_cost * $qty;

    $limit_status = $this->tabLimitService->getStatus($this->currentUser, $pending_total);
    if ($limit_status['blocked']) {
      $message = $this->t('Cannot add to tab: @reason', ['@reason' => $limit_status['reason']]);
      $this->messenger()->addError($message);
      $form_state->setErrorByName('quantity', $message);
      return;
    }

    try {
      // Create Transaction.
      $transaction = $this->entityTypeManager->getStorage('material_transaction')->create([
        'type' => 'purchase',
        'field_material_ref' => $material->id(),
        'field_quantity' => $qty,
        'field_transaction_status' => 'pending',
        'field_transaction_owner' => $this->currentUser->id(),
        'field_transaction_amount' => $material->get('field_material_unit_cost')->value,
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

      $this->messenger()->addStatus(Markup::create(
        $this->t('Added @qty x @item to your tab. ', [
          '@qty' => $qty,
          '@item' => $material->label(),
        ]) . 
        '<a href="' . Url::fromRoute('makerspace_material_store.view_tab')->toString() . '" class="btn btn-sm btn-success ms-2"><i class="fas fa-shopping-cart me-1"></i> ' . $this->t('View Tab') . '</a>'
      ));
      
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
    
    // Redirect based on configuration.
    $config = \Drupal::config('makerspace_material_store.settings');
    $redirect_option = $config->get('post_add_redirect') ?: 'store';
    
    if ($redirect_option === 'cart') {
      $url = Url::fromRoute('makerspace_material_store.view_tab')->toString();
    }
    elseif ($redirect_option === 'item') {
      $material = $form_state->get('material');
      $url = Url::fromRoute('entity.node.canonical', ['node' => $material->id()])->toString();
    }
    else {
      // Default to store.
      $url = '/store';
    }
    
    // Force reload/redirect to ensure messages are seen and block is updated.
    $response->addCommand(new RedirectCommand($url));
    return $response;
  }

  /**
   * Returns the unit cost for a material node.
   */
  protected function getMaterialUnitCost(NodeInterface $material) {
    if ($material->hasField('field_material_unit_cost') && !$material->get('field_material_unit_cost')->isEmpty()) {
      return (float) $material->get('field_material_unit_cost')->value;
    }
    return 0.0;
  }

}
