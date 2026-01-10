<?php

namespace Drupal\makerspace_material_store\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Provides tab limit calculations and enforcement helpers.
 */
class TabLimitService {

  use StringTranslationTrait;

  /**
   * Material transaction storage.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, TranslationInterface $string_translation) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->stringTranslation = $string_translation;
  }

  /**
   * Returns the user's current tab status and limit state.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to evaluate.
   * @param float $pending_addition
   *   Optional total dollar amount that is about to be added.
   *
   * @return array
   *   Status information, including:
   *   - blocked: TRUE if the user may not add more to their tab.
   *   - reason: Human-readable reason for the block (translated).
   *   - total: Current outstanding tab total.
   *   - projected_total: Total including the pending addition.
   *   - oldest_days: Age in days of the oldest pending item.
   */
  public function getStatus(AccountInterface $account, float $pending_addition = 0.0) {
    $status = [
      'blocked' => FALSE,
      'reason' => '',
      'total' => 0.0,
      'projected_total' => 0.0,
      'oldest_days' => 0,
    ];

    if (!$account || !$account->isAuthenticated()) {
      return $status;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('material_transaction');
      $query = $storage->getQuery()
        ->condition('field_transaction_owner', $account->id())
        ->condition('field_transaction_status', 'pending')
        ->sort('created', 'ASC')
        ->accessCheck(FALSE);
      $ids = $query->execute();

      if (!empty($ids)) {
        $transactions = $storage->loadMultiple($ids);
        $oldest = NULL;
        foreach ($transactions as $transaction) {
          $qty = (float) $transaction->get('field_quantity')->value;
          $price = (float) $transaction->get('field_transaction_amount')->value;
          $status['total'] += ($qty * $price);
          if ($oldest === NULL) {
            $oldest = (int) $transaction->get('created')->value;
          }
        }

        if ($oldest) {
          $status['oldest_days'] = (int) floor((time() - $oldest) / 86400);
        }
      }
    }
    catch (\Exception $e) {
      // If we cannot calculate status, leave defaults so we do not block users.
    }

    $status['projected_total'] = $status['total'] + $pending_addition;

    $config = $this->configFactory->get('makerspace_material_store.settings');
    $max_amount = (float) $config->get('max_tab_amount');
    $max_days = (int) $config->get('max_tab_days');

    if ($max_amount > 0 && $status['projected_total'] > $max_amount) {
      $status['blocked'] = TRUE;
      if ($status['total'] > $max_amount) {
        $status['reason'] = $this->t('Current balance ($@total) exceeds the $@max limit.', [
          '@total' => number_format($status['total'], 2),
          '@max' => number_format($max_amount, 2),
        ]);
      }
      else {
        $pending_formatted = number_format($pending_addition, 2);
        $status['reason'] = $this->t('Adding this item ($@pending) would exceed the $@max limit (Current balance: $@total).', [
          '@pending' => $pending_formatted,
          '@max' => number_format($max_amount, 2),
          '@total' => number_format($status['total'], 2),
        ]);
      }
    }
    elseif ($max_days > 0 && $status['oldest_days'] > $max_days) {
      $status['blocked'] = TRUE;
      $status['reason'] = $this->t('You have pending items that are @days days old (max allowed: @max days).', [
        '@days' => $status['oldest_days'],
        '@max' => $max_days,
      ]);
    }

    return $status;
  }

}
