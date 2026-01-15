<?php

namespace Drupal\makerspace_material_store\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports recent Stripe-invoiced store transactions.
 */
class StoreStripeInvoiceReportController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * Constructs the controller.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * Builds a table of recent Stripe-invoiced transactions.
   */
  public function report() {
    $storage = $this->entityTypeManager->getStorage('material_transaction');
    $query = $storage->getQuery()
      ->condition('field_store_stripe_invoice_id', NULL, 'IS NOT NULL')
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->accessCheck(FALSE);
    $ids = $query->execute();

    if (empty($ids)) {
      return [
        '#markup' => $this->t('No Stripe-invoiced store transactions found.'),
      ];
    }

    $transactions = $storage->loadMultiple($ids);
    $rows = [];
    $user_storage = $this->entityTypeManager->getStorage('user');

    foreach ($transactions as $transaction) {
      $invoice_id = (string) ($transaction->get('field_store_stripe_invoice_id')->value ?? '');
      $status = (string) ($transaction->get('field_transaction_status')->value ?? '');
      $qty = (float) ($transaction->get('field_quantity')->value ?? 0);
      $price = (float) ($transaction->get('field_transaction_amount')->value ?? 0);
      $total = $qty * $price;
      $created = (int) ($transaction->get('created')->value ?? 0);
      $uid = (int) ($transaction->get('field_transaction_owner')->target_id ?? 0);
      $user = $uid ? $user_storage->load($uid) : NULL;

      $rows[] = [
        'invoice_id' => $invoice_id,
        'transaction_id' => $transaction->id(),
        'member' => $user ? $user->toLink()->toRenderable() : $this->t('Unknown'),
        'status' => $status,
        'total' => '$' . number_format($total, 2),
        'created' => $created ? $this->dateFormatter->format($created, 'short') : '',
      ];
    }

    return [
      '#type' => 'table',
      '#header' => [
        $this->t('Stripe Invoice'),
        $this->t('Transaction ID'),
        $this->t('Member'),
        $this->t('Status'),
        $this->t('Total'),
        $this->t('Created'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No Stripe-invoiced store transactions found.'),
    ];
  }

}
