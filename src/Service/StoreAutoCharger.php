<?php

namespace Drupal\makerspace_material_store\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mh_stripe\Service\StripeHelper;

/**
 * Service to handle auto-charging of user tabs.
 */
class StoreAutoCharger {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Stripe helper.
   *
   * @var \Drupal\mh_stripe\Service\StripeHelper|null
   */
  protected $stripeHelper;

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\mh_stripe\Service\StripeHelper|null $stripe_helper
   *   The Stripe helper service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ?StripeHelper $stripe_helper = NULL) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('makerspace_material_store');
    $this->stripeHelper = $stripe_helper;
  }

  /**
   * Processes auto-charges for all users with the flag enabled.
   */
  public function processAutoCharges() {
    if (!$this->stripeHelper) {
      $this->logger->error('StripeHelper service not found. Cannot process auto-charges.');
      return;
    }

    if (!$this->isLastDayOfMonth()) {
      return;
    }

    // 1. Find users with auto-charge enabled.
    $user_storage = $this->entityTypeManager->getStorage('user');
    $query = $user_storage->getQuery()
      ->condition('status', 1)
      ->condition('field_store_tab_autocharge', 1);
    $blocked_group = $query->orConditionGroup()
      ->condition('field_store_tab_blocked', 0)
      ->condition('field_store_tab_blocked', NULL, 'IS NULL');
    $query->condition($blocked_group)
      ->accessCheck(FALSE);
    $uids = $query->execute();

    if (empty($uids)) {
      return;
    }

    foreach ($uids as $uid) {
      $this->processUserCharge($uid);
    }
  }

  /**
   * Processes a single user.
   *
   * @param int $uid
   *   The user ID.
   */
  protected function processUserCharge($uid) {
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account) {
      return;
    }

    // Get pending transactions.
    $transaction_storage = $this->entityTypeManager->getStorage('material_transaction');
    $query = $transaction_storage->getQuery()
      ->condition('field_transaction_owner', $uid)
      ->condition('field_transaction_status', 'pending')
      ->accessCheck(FALSE);
    $tids = $query->execute();

    if (empty($tids)) {
      return;
    }

    $transactions = $transaction_storage->loadMultiple($tids);
    $total_amount = 0.0;
    $transaction_ids = [];

    foreach ($transactions as $transaction) {
      if ($transaction->hasField('field_store_stripe_invoice_id') && !$transaction->get('field_store_stripe_invoice_id')->isEmpty()) {
        continue;
      }
      $qty = (float) $transaction->get('field_quantity')->value;
      $price = (float) $transaction->get('field_transaction_amount')->value;
      $total_amount += ($qty * $price);
      $transaction_ids[] = $transaction->id();
    }

    if (!$transaction_ids) {
      return;
    }

    // Minimum charge amount (Stripe requires at least $0.50 usually).
    // Let's stick to $1.00 minimum to avoid small transaction fees eating it up.
    if ($total_amount < 1.00) {
      return;
    }

    // Get Stripe Customer ID.
    $customer_field = $this->stripeHelper->customerFieldName() ?? 'field_stripe_customer_id';
    $customer_id = NULL;
    if ($account->hasField($customer_field) && !$account->get($customer_field)->isEmpty()) {
      $customer_id = $account->get($customer_field)->value;
    }

    if (!$customer_id) {
      $this->logger->warning('User @uid has auto-charge enabled but no Stripe Customer ID.', ['@uid' => $uid]);
      // Should we disable the flag? Maybe not, they might add it later.
      return;
    }

    // Attempt Charge.
    try {
      $client = $this->stripeHelper->client();
      $amount_cents = (int) round($total_amount * 100);
      $metadata = $this->buildInvoiceMetadata($uid, $transaction_ids);

      foreach ($transactions as $transaction) {
        $qty = (float) $transaction->get('field_quantity')->value;
        $price = (float) $transaction->get('field_transaction_amount')->value;
        if ($price <= 0) {
          continue;
        }
        $material_id = $transaction->get('field_material_ref')->target_id;
        $material = $this->entityTypeManager->getStorage('node')->load($material_id);
        $item_name = $material ? $material->label() : 'Item';
        $line_total_cents = (int) round($qty * $price * 100);
        if ($line_total_cents <= 0) {
          continue;
        }

        $client->invoiceItems->create([
          'customer' => $customer_id,
          'amount' => $line_total_cents,
          'currency' => 'usd',
          'description' => sprintf('%s x %s', $qty, $item_name),
          'metadata' => [
            'source_system' => 'makerspace_material_store',
            'transaction_type' => 'store_tab',
            'drupal_uid' => (string) $uid,
            'transaction_id' => (string) $transaction->id(),
            'material_node_id' => (string) $material_id,
            'material_name' => $item_name,
            'quantity' => (string) $qty,
            'unit_price' => number_format($price, 2, '.', ''),
          ],
        ]);
      }

      $invoice = $client->invoices->create([
        'customer' => $customer_id,
        'collection_method' => 'charge_automatically',
        'auto_advance' => true,
        'metadata' => $metadata,
        'description' => sprintf('Makerspace Store Tab (%s)', $metadata['tab_period']),
      ]);
      $client->invoices->finalizeInvoice($invoice->id);
      $paid = $client->invoices->pay($invoice->id, ['off_session' => true]);

      foreach ($transactions as $transaction) {
        if ($transaction->hasField('field_store_stripe_invoice_id')) {
          $transaction->set('field_store_stripe_invoice_id', $invoice->id);
          $transaction->save();
        }
      }

      if ($paid->status === 'paid') {
        foreach ($transactions as $transaction) {
          $transaction->set('field_transaction_status', 'paid');
          $transaction->save();
        }
        $this->logger->info('Charged user @uid $@amount for @count items.', [
          '@uid' => $uid,
          '@amount' => $total_amount,
          '@count' => count($transactions),
        ]);
      }
      else {
        throw new \Exception('Invoice status: ' . $paid->status);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to auto-charge user @uid: @error', [
        '@uid' => $uid,
        '@error' => $e->getMessage(),
      ]);

      // Block the tab so they know something is wrong.
      if ($account->hasField('field_store_tab_blocked')) {
        $account->set('field_store_tab_blocked', 1);
        $account->save();
      }
    }
  }

  /**
   * Determine if today is the last day of the month in site timezone.
   */
  protected function isLastDayOfMonth(): bool {
    $timezone = (string) $this->configFactory->get('system.date')->get('timezone.default');
    if ($timezone === '') {
      $timezone = date_default_timezone_get();
    }
    $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));
    return (int) $now->format('j') === (int) $now->format('t');
  }

  /**
   * Build invoice metadata for Xero routing and reconciliation.
   */
  protected function buildInvoiceMetadata(int $uid, array $transaction_ids): array {
    sort($transaction_ids);
    $timezone = (string) $this->configFactory->get('system.date')->get('timezone.default');
    if ($timezone === '') {
      $timezone = date_default_timezone_get();
    }
    $period = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('Y-m');

    return [
      'source_system' => 'makerspace_material_store',
      'transaction_type' => 'store_tab',
      'drupal_uid' => (string) $uid,
      'tab_transaction_ids' => implode(',', $transaction_ids),
      'tab_period' => $period,
    ];
  }

}
