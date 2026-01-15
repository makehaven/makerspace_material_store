# Makerspace Material Store

This module manages the material store, allowing users to buy items via PayPal immediately or add them to a "Tab" to pay later in bulk. It also supports Stripe auto-charge for member tabs (invoice-based, end-of-month).

## Prerequisites

### 1. ECK Entity: Material Transaction
You must create an ECK Entity Type named `material_transaction`.
*   **Label:** Material Transaction
*   **Machine Name:** `material_transaction`
*   **Base Fields:**
    *   [x] Title
    *   [x] Created
    *   [x] Changed
    *   [ ] Author (Leave unchecked - we use `field_transaction_owner`)
    *   [ ] Status (Leave unchecked - we use `field_transaction_status`)

**Bundle:** `purchase`

**Fields:**
*   `field_material_ref` (Entity Reference -> Content: Material)
*   `field_quantity` (Decimal) - Supports fractional amounts (e.g. 1.5 hours)
*   `field_transaction_status` (List text) - Allowed values:
    *   `pending|Pending`
    *   `paid|Paid`
    *   `removed|Removed`
*   `field_transaction_owner` (Entity Reference -> User)
*   `field_transaction_amount` (Decimal) - Stores the price at the time of addition.

---

## Integration Methods

### A. Checkout Landing Page (Best for Workstations)
Redirect a user to this URL when they finish using a machine. It requires login and presents a clean page with "Add to Tab" or "Buy Now" options.
*   **URL:** `/store/checkout-item/[MaterialNID]?qty=[Quantity]`
*   **Example:** `/store/checkout-item/456?qty=2.5`

### B. Secure API (Best for Background Processes)
Used by automated software to add items to a user's tab without them needing to click anything.
*   **Endpoint:** `POST /api/store/tab/add`
*   **Auth:** Include header `X-Store-API-Key: [YourKey]` (set in admin settings).
*   **JSON Body:**
    ```json
    {
      "uid": 123,
      "material_id": 456,
      "quantity": 1.5,
      "memo": "Waterjet use"
    }
    ```

### C. Direct Action Links (Best for QR codes/Views)
Add an item to a tab immediately and redirect the user back.
*   **URL:** `/store/add-to-tab/[MaterialNID]?qty=[Quantity]`
*   **Example:** `/store/add-to-tab/456?qty=1`

---

## Inventory Logic

1.  **Add to Tab:** Inventory is deducted **immediately** (Reason: `unpaid_tab`). Shelf count is always accurate.
2.  **Checkout Tab:** PayPal link is generated with `type=tab_checkout`. The IPN listener records the sale but sets the quantity change to **0** to avoid double-deducting.
3.  **Remove (Mistake):** Inventory is **refunded (+1)** and the record is marked as `Removed`.
4.  **Buy Now:** Inventory is deducted only after payment is confirmed via IPN.

---

## Stripe Integration & Auto-Charge

This module supports automatic charging of user tabs via Stripe. It uses **Stripe invoices with line items**, not direct PaymentIntents. That is intentional:
* Invoices preserve line-item detail for Stripe exports and Xero rules.
* Invoice metadata is used to tag store charges so they can be routed separately from memberships or storage.
* Charging is limited to **the last day of the month** in the **site timezone**, to avoid repeated mid-month charges.

### Prerequisites
*   **Module:** `mh_stripe` (or a custom module providing `\Drupal\mh_stripe\Service\StripeHelper`).
*   **User Field:** `field_stripe_customer_id` (Text) - Must contain the user's Stripe Customer ID (cus_...).
*   **User Fields:** Created by update hook `makerspace_material_store_update_9002`:
    *   `field_store_tab_autocharge` (Boolean)
    *   `field_store_tab_blocked` (Boolean)
    *   `field_store_tab_terms_accepted` (Boolean)
    *   `field_store_tab_terms_accepted_at` (Timestamp)

### Auto-Charge Logic (Intended Behavior)
1.  **Trigger:** Runs on Drupal Cron **but exits unless today is the last day of the month** (site timezone).
2.  **Selection:** Selects users with `field_store_tab_autocharge = 1` and `field_store_tab_blocked != 1`.
3.  **Threshold:** Processes charges if the pending tab balance is >= $1.00.
4.  **Action:** Creates Stripe **invoice items** for each tab line, then creates/finalizes/pays an invoice.
5.  **Result:**
    *   **Success:** Pending transactions are marked as `paid`.
    *   **Failure:** The user's tab is blocked (`field_store_tab_blocked = 1`) to prevent further debt until resolved.
    *   **Already invoiced:** Pending transactions with a stored Stripe invoice ID are skipped to avoid duplicate invoice items.

### Stripe Metadata
Invoices and invoice items include metadata for Xero routing:
* `source_system=makerspace_material_store`
* `transaction_type=store_tab`
* `drupal_uid=<uid>`
* `tab_transaction_ids=<comma list>`
* `tab_period=YYYY-MM`
* Item-level metadata includes material node ID, name, quantity, and unit price.

### Stripe Webhook
Configure Stripe to POST events to `/store/stripe/webhook` using the secret set in the store settings. This endpoint:
* Marks transactions paid on `invoice.paid`.
* Stores the invoice ID on transactions when `invoice.finalized` or `invoice.payment_failed` fires.
* Blocks the user on `invoice.payment_failed`.

---

## Configuration
*   **Settings:** Go to `/admin/config/makerspace/store` to set PayPal ID, Tab Limits, Stripe tab rules, webhook secret, and API Key.
*   **Blocks:**
    *   **Store Tab & Actions:** Place on Material pages for member purchases.
    *   **Staff Material Dispense:** Place on Material pages (restrict to Staff/Instructor roles).
    *   **Material Inventory Quick Update:** Place on Material pages (restrict to inventory volunteers).
*   **Report:** `/admin/reports/store/stripe-invoices` shows the most recent Stripe-invoiced store transactions.
