# Project Context: Makerspace Material Store

## Project Overview
This project is a custom Drupal module (`makerspace_material_store`) designed to manage a Makerspace material store. It facilitates material purchases, inventory tracking, and a "Tab" system for members to defer payments.

## Key Features
- **Purchase Methods:**
  - **Buy Now:** Immediate payment via PayPal.
  - **Add to Tab:** Defer payment to a user's tab.
  - **Auto-Charge:** Automatically charges user tabs via Stripe (cron-based).
- **Inventory Management:**
  - Integrates with `material_inventory` ECK entity.
  - Inventory is deducted immediately upon adding to a tab (Status: `unpaid_tab`).
  - Supports refunding inventory if an item is removed from a tab (Status: `restock`).
- **Tab Limits:**
  - Configurable limits on maximum tab amount (default $250).
  - Configurable limits on maximum age of unpaid items (default 90 days).
  - User-specific blocks (`field_store_tab_blocked`).
- **Access Control:**
  - Permissions to use the tab system.
  - Requirement for Terms Acceptance and/or Stripe account presence.

## Architecture

### Services
- **`StorePaymentService`:** Generates PayPal URLs for "Buy Now", "Add to Cart", and "Cart Upload" (bulk tab checkout).
- **`TabLimitService`:** Calculates user tab status, enforcing monetary and age limits, and checking for Stripe/Terms requirements.
- **`StoreAutoCharger`:** Handles the automated charging of user tabs using stored Stripe Customer IDs via the `mh_stripe` module helper.

### Entities & Data Model
- **`material_transaction` (ECK):** Records individual line items.
  - `purchase` bundle.
  - Fields: `field_material_ref`, `field_quantity`, `field_transaction_status` (pending, paid, removed), `field_transaction_owner`, `field_transaction_amount`.
- **`material_inventory` (ECK):** Records inventory adjustments.
  - `inventory_adjustment` bundle.
- **`node` (Material):** Represents the products being sold.
- **`user`:** Extends user entities with:
  - `field_store_tab_autocharge`: Boolean to enable auto-pay.
  - `field_store_tab_blocked`: Boolean to freeze a tab.
  - `field_store_tab_terms_accepted`: Boolean for legal consent.

### Key Workflows
1.  **Adding to Tab:**
    - User clicks "Add to Tab".
    - `TabLimitService` checks limits.
    - `AddToTabForm` checks terms acceptance.
    - Transaction created (`pending`), Inventory deducted (`unpaid_tab`).
2.  **Checkout (Manual):**
    - User views tab (`StoreController::viewTab`).
    - Clicks checkout -> Redirects to PayPal Cart Upload.
3.  **Checkout (Auto):**
    - `makerspace_material_store_cron` triggers `StoreAutoCharger`.
    - Finds users with `field_store_tab_autocharge`.
    - Charges card via Stripe PaymentIntent.
    - Marks transactions `paid` on success.

## Configuration
- **Settings Path:** `/admin/config/makerspace/store`
- **Config Object:** `makerspace_material_store.settings`
  - `paypal_business_id`: PayPal destination.
  - `max_tab_amount`: Monetary limit.
  - `max_tab_days`: Time limit.
  - `require_stripe_for_tab`: Enforce Stripe ID presence.
  - `require_terms_acceptance`: Enforce TOS.

## Development & Operations
- **Dependencies:** `drupal:node`, `eck:eck`, `drupal:user`, `paypal_inventory_listener`, `mh_stripe` (optional but required for auto-charge).
- **Updates:** Run `drush updb` to apply schema updates (e.g., adding user fields).
- **Cron:** Essential for the `StoreAutoCharger` to function.
