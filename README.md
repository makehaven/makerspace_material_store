# Makerspace Material Store

This module manages the material store, allowing users to buy items via PayPal immediately or add them to a "Tab" to pay later in bulk.

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

## Configuration
*   **Settings:** Go to `/admin/config/makerspace/store` to set PayPal ID, Tab Limits, and API Key.
*   **Blocks:**
    *   **Store Tab & Actions:** Place on Material pages for member purchases.
    *   **Staff Material Dispense:** Place on Material pages (restrict to Staff/Instructor roles).
    *   **Material Inventory Quick Update:** Place on Material pages (restrict to inventory volunteers).
