<?php

namespace Drupal\makerspace_material_store\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\node\NodeInterface;

/**
 * Service to generate PayPal payment URLs.
 */
class StorePaymentService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Generates a PayPal Buy Now URL for a single material.
   *
   * @param \Drupal\node\NodeInterface $material
   *   The material node.
   * @param int $quantity
   *   The quantity.
   *
   * @return string
   *   The PayPal URL.
   */
  public function getBuyNowUrl(NodeInterface $material, int $quantity = 1): string {
    $config = $this->configFactory->get('makerspace_material_store.settings');
    $business = $config->get('paypal_business_id') ?: 'info@makehaven.org'; // Fallback
    $return_url = $config->get('return_url');
    $cancel_url = $config->get('cancel_url');
    $notify_url = $config->get('notify_url'); // The IPN listener

    // Get price.
    $price = $material->get('field_material_sales_cost')->value;
    $item_name = $material->label();

    $query = [
      'cmd' => '_xclick',
      'business' => $business,
      'item_name' => $item_name,
      'item_number' => $material->id(),
      'amount' => $price,
      'quantity' => $quantity,
      'currency_code' => 'USD',
      'no_shipping' => '1',
      'no_note' => '1',
      'solution_type' => 'sole',
      'landing_page' => 'billing',
      'custom' => json_encode(['uid' => \Drupal::currentUser()->id(), 'type' => 'buy_now']),
    ];

    if ($return_url) {
      $query['return'] = $return_url;
    }
    if ($cancel_url) {
      $query['cancel_return'] = $cancel_url;
    }
    if ($notify_url) {
      $query['notify_url'] = $notify_url;
    }

    return 'https://www.paypal.com/cgi-bin/webscr?' . http_build_query($query);
  }

  /**
   * Generates a PayPal Cart Upload URL for multiple items.
   *
   * @param array $items
   *   An array of items. Each item must be an associative array with:
   *   - material: NodeInterface (the material)
   *   - quantity: int
   *   - name: string (optional override)
   *   - price: float (optional override)
   *
   * @return string
   *   The PayPal URL.
   */
  public function getCartUploadUrl(array $items): string {
    $config = $this->configFactory->get('makerspace_material_store.settings');
    $business = $config->get('paypal_business_id') ?: 'info@makehaven.org';
    $return_url = $config->get('return_url');
    $cancel_url = $config->get('cancel_url');
    $notify_url = $config->get('notify_url');

    $query = [
      'cmd' => '_cart',
      'upload' => '1',
      'business' => $business,
      'currency_code' => 'USD',
      'no_shipping' => '1',
      'solution_type' => 'sole',
      'landing_page' => 'billing',
      'custom' => json_encode(['uid' => \Drupal::currentUser()->id(), 'type' => 'tab_checkout']),
    ];

    $i = 1;
    foreach ($items as $item) {
      /** @var \Drupal\node\NodeInterface $material */
      $material = $item['material'];
      $price = (float) ($item['price'] ?? $material->get('field_material_sales_cost')->value);
      $name = $item['name'] ?? $material->label();
      $qty = $item['quantity'] ?? 1;

      // PayPal does not allow 0.00 amount items in a cart.
      if ($price <= 0) {
        continue;
      }

      // Truncate name to 100 chars (PayPal limit is 127, but we play it safe).
      $clean_name = substr(strip_tags($name), 0, 100);

      $query["item_name_$i"] = $clean_name;
      $query["item_number_$i"] = $material->id();
      $query["amount_$i"] = number_format($price, 2, '.', '');
      $query["quantity_$i"] = $qty;

      $i++;
    }

    if ($return_url) {
      $query['return'] = $return_url;
    }
    if ($cancel_url) {
      $query['cancel_return'] = $cancel_url;
    }
    if ($notify_url) {
      $query['notify_url'] = $notify_url;
    }

    return 'https://www.paypal.com/cgi-bin/webscr?' . http_build_query($query);
  }

}
