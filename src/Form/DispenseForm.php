<?php

namespace Drupal\makerspace_material_store\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for staff to dispense materials for internal use.
 */
class DispenseForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the form.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'makerspace_material_store_dispense_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, NodeInterface $material = NULL) {
    if (!$material) {
      return ['#markup' => $this->t('Invalid material.')];
    }
    
    $form_state->set('material', $material);

    $form['dispense_header'] = [
      '#type' => 'markup',
      '#markup' => '<h4 class="mt-2 mb-3">' . $this->t('Staff Dispense: @label', ['@label' => $material->label()]) . '</h4>',
    ];

    $form['quantity'] = [
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#default_value' => 1,
      '#min' => 1,
      '#required' => TRUE,
      '#attributes' => ['class' => ['form-control-lg']],
    ];

    $form['reason'] = [
      '#type' => 'select',
      '#title' => $this->t('Reason'),
      '#options' => [
        'workshop_supplies' => $this->t('Workshop Supplies (Class)'),
        'program_supplies' => $this->t('Program Supplies'),
        'shop_use' => $this->t('Shop Use (Maintenance/Fabrication)'),
        'office_use' => $this->t('Office Use'),
        'lossage' => $this->t('Lossage / Damaged'),
      ],
      '#required' => TRUE,
      '#attributes' => ['class' => ['form-select-lg']],
    ];

    $form['notes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Optional project or class name.'),
      '#attributes' => ['placeholder' => $this->t('Project or class name...')],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['mt-3']],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Record Usage'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['btn-lg', 'btn-warning', 'w-100']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $material = $form_state->get('material');
    $qty = (int) $form_state->getValue('quantity');
    $reason = $form_state->getValue('reason');
    $notes = $form_state->getValue('notes');

    try {
      $adjustment = $this->entityTypeManager->getStorage('material_inventory')->create([
        'type' => 'inventory_adjustment',
        'field_inventory_ref_material' => $material->id(),
        'field_inventory_quantity_change' => -$qty, // Negative for usage
        'field_inventory_change_reason' => $reason,
        'field_inventory_change_memo' => $notes,
      ]);
      $adjustment->save();

      $this->messenger()->addStatus($this->t('Dispensed @qty of @title.', ['@qty' => $qty, '@title' => $material->label()]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }

    $form_state->setRedirect('entity.node.canonical', ['node' => $material->id()]);
  }
}
