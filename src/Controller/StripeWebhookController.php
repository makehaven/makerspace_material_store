<?php

namespace Drupal\makerspace_material_store\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Stripe\Webhook;

/**
 * Handles Stripe webhooks for store tab invoices.
 */
class StripeWebhookController extends ControllerBase {

  /**
   * Handle a Stripe webhook request.
   */
  public function handle(Request $request): Response {
    $config = $this->config('makerspace_material_store.settings');
    $secret = (string) $config->get('stripe_webhook_secret');
    if ($secret === '') {
      return new Response('Webhook secret not configured.', 400);
    }

    $payload = $request->getContent();
    $signature = $request->headers->get('Stripe-Signature');

    try {
      $event = Webhook::constructEvent($payload, $signature, $secret);
    }
    catch (\UnexpectedValueException $e) {
      return new Response('Invalid payload.', 400);
    }
    catch (\Stripe\Exception\SignatureVerificationException $e) {
      return new Response('Invalid signature.', 400);
    }

    $type = $event->type ?? '';
    $object = $event->data->object ?? NULL;
    if (!$object || !isset($object->metadata)) {
      return new Response('Ignored.', 200);
    }

    $metadata = (array) $object->metadata;
    if (($metadata['source_system'] ?? '') !== 'makerspace_material_store') {
      return new Response('Ignored.', 200);
    }

    $transaction_ids = $this->parseTransactionIds($metadata['tab_transaction_ids'] ?? '');
    if (!$transaction_ids) {
      return new Response('No transactions.', 200);
    }

    if ($type === 'invoice.paid') {
      $this->markTransactionsPaid($transaction_ids, (string) ($object->id ?? ''));
    }
    elseif ($type === 'invoice.payment_failed') {
      $this->blockUser((string) ($metadata['drupal_uid'] ?? ''));
      $this->setInvoiceIdOnTransactions($transaction_ids, (string) ($object->id ?? ''));
    }
    elseif ($type === 'invoice.finalized') {
      $this->setInvoiceIdOnTransactions($transaction_ids, (string) ($object->id ?? ''));
    }

    return new Response('ok', 200);
  }

  /**
   * Convert a comma-separated list into integer IDs.
   */
  protected function parseTransactionIds(string $value): array {
    $parts = array_filter(array_map('trim', explode(',', $value)));
    $ids = [];
    foreach ($parts as $part) {
      if (ctype_digit($part)) {
        $ids[] = (int) $part;
      }
    }
    return $ids;
  }

  /**
   * Mark transactions as paid and store the invoice ID.
   */
  protected function markTransactionsPaid(array $transaction_ids, string $invoice_id): void {
    $storage = $this->entityTypeManager()->getStorage('material_transaction');
    $transactions = $storage->loadMultiple($transaction_ids);
    foreach ($transactions as $transaction) {
      if ($transaction->hasField('field_store_stripe_invoice_id') && $invoice_id !== '') {
        $transaction->set('field_store_stripe_invoice_id', $invoice_id);
      }
      $transaction->set('field_transaction_status', 'paid');
      $transaction->save();
    }
  }

  /**
   * Store the Stripe invoice ID on pending transactions.
   */
  protected function setInvoiceIdOnTransactions(array $transaction_ids, string $invoice_id): void {
    if ($invoice_id === '') {
      return;
    }
    $storage = $this->entityTypeManager()->getStorage('material_transaction');
    $transactions = $storage->loadMultiple($transaction_ids);
    foreach ($transactions as $transaction) {
      if ($transaction->hasField('field_store_stripe_invoice_id')) {
        $transaction->set('field_store_stripe_invoice_id', $invoice_id);
        $transaction->save();
      }
    }
  }

  /**
   * Block a user from adding to their tab.
   */
  protected function blockUser(string $uid): void {
    if ($uid === '' || !ctype_digit($uid)) {
      return;
    }
    $user = $this->entityTypeManager()->getStorage('user')->load((int) $uid);
    if ($user && $user->hasField('field_store_tab_blocked')) {
      $user->set('field_store_tab_blocked', 1);
      $user->save();
    }
  }

}
