# Dibonke Bookshop database setup

## Installation status

The repository does **not yet contain a verified, complete fresh-install base schema**. The inherited SQL files are extensions and cannot be treated as a standalone database installation.

Use a separate development database named `dibonke_bookshop_db`. Do not point Dibonke at the source application's database or import live customer records.

Before a fresh installation can be declared ready, the project still needs to:
1. Define one canonical Dibonke base schema for every module.
2. Reconcile all PHP queries, inserts, updates, indexes and foreign keys against that schema.
3. Keep only Dibonke retail terminology and safe setup defaults.
4. Import the schema into a newly created empty MariaDB database.
5. Run module workflows and transaction tests against that clean database.

## Current SQL files

| File | Purpose | Important dependency |
|---|---|---|
| `dibonke_products_inventory.sql` | Additive product type and ISBN extension | Requires an existing `products` table |
| `price_lists.sql` | Standard/wholesale price lists and product price tiers | Requires `products`, `customers` and `sales` |
| `supply_chain_mpesa.sql` | Purchase orders, goods receipts, supplier payments and M-PESA transaction storage | Requires base `users`, `suppliers` and `settings` tables |\n| `dibonke_goods_receiving_scanner.sql` | Adds supplier invoice date/total fields and grants Manager/Storekeeper receiving permissions | Requires `goods_receipts`, `roles` and `role_permissions` |
| `dibonke_staff_roles.sql` | Staff profiles and unified role permissions | Requires the existing `users` table |
| `dibonke_launch_operations.sql` | Sales returns, cash shifts, stock counts, audit events and eTIMS queue | Requires the existing retail tables plus `roles`, `role_permissions` and `settings`; uses indexed references without foreign-key constraints to tolerate inherited ID definitions |
| `dibonke_finance_billing_permissions.sql` | Adds Finance and Billing module permissions to existing roles | Requires `roles` and `role_permissions` from the staff/roles migration |
| `dibonke_products_inventory_preflight.sql` | Read-only check of product, POS, purchase and stock-ledger dependencies | Run with the target Dibonke database selected |

The preflight script performs SELECTs against `information_schema` only. It does not create or alter tables and is safe to use before planning migrations. Missing columns or foreign keys must be reconciled deliberately; do not apply one-off ALTER statements to a live database.

## Products and inventory contract

The current product workflow expects these tables and relationships:

- `products`: product name, broad type, optional ISBN, SKU/barcode, unit, category/brand/supplier links, buying/selling/wholesale prices, stock quantity, reorder level and status.
- `categories`: user-managed category names and descriptions. Categories are data-driven; the three product types (Book, Stationery, General) are not a substitute for detailed categories.
- `brands`: optional publisher/brand lookup.
- `suppliers`: optional preferred supplier lookup.
- `price_lists` and `product_prices`: active Standard and optional Wholesale prices, including quantity tiers.
- `stock_movements`: opening stock, purchases, sales, returns, damage and manual adjustments.
- `users`: the user reference attached to stock ledger entries.
- `sale_items` and `purchase_items`: historical product references that prevent unsafe product deletion.

The POS also reads `customers`, `sales`, `settings` and `activity_logs`. Purchasing reads `purchases`, `purchase_items`, `suppliers`, `supplier_payments` and `settings`. These dependent tables must be part of the eventual canonical base schema, even though they are not all owned by the Products module.

### Product identifiers and pricing

- ISBN is optional and intended for books. ISBN-10 and ISBN-13 check digits are validated; spaces and hyphens are removed before storage so equivalent formatted ISBNs resolve to the same unique value.
- SKU and barcode remain available for books, stationery and other retail items.
- Categories are flexible and maintained separately from the broad product type.
- Buying price is the purchase-cost reference; selling price is the standard retail price; wholesale price is optional and is synchronized to the Wholesale price list when configured.
- The Standard price list is required by product creation and the POS. Wholesale pricing requires an active Wholesale price list.

### Stock handling

- New products may be created with opening stock; the same transaction writes an `Opening Stock` ledger entry.
- Purchase orders do not change stock. Posting a goods receipt increases stock only for the quantities actually received and writes `Purchase` movements. Partial receipts remain open against the PO.
- Completed sales decrease product stock and write `Sale` movements.
- Manual increases, decreases and physical-count corrections are recorded as `Adjustment` movements. The adjustment workflow locks the product row and updates the product balance and ledger in one transaction.
- The adjustment action requires the existing `stock_movements.create` permission; no role grants were changed as part of this work.
- Editing product details does not directly overwrite stock. Use the stock adjustment workflow, purchases or sales so the ledger remains traceable.

**Current quantity limitation:** the inherited products, purchase, POS and stock-ledger handlers use whole-number quantities. The present workflow is therefore intended for piece/pack-style units. Supporting fractional units such as kilograms or metres requires a coordinated DECIMAL schema and PHP change across products, purchases, sales, stock movements, receipts and reports; changing only the products column would be unsafe.

## Safe migration guidance

1. Back up the target development database before any migration.
2. Run `dibonke_products_inventory_preflight.sql` and review all missing dependencies.
3. Build/import the canonical base schema before applying extension scripts.
4. Apply each extension only after its documented parent tables exist.
5. Verify foreign keys, unique indexes and default price-list rows.
6. Test opening stock, purchase receiving, POS sale, price-list selection and manual stock adjustment on test records.

No production data, passwords, API credentials or live customer records belong in committed SQL files.


## Finance and Billing modules

- **Finance** is an operational cash summary built from existing completed sales, customer collections, supplier payments and expense records. Customer collections already included in a sale's paid amount are not counted a second time; later customer payments without a sale link are added separately.
- **Billing & Invoices** is the central register for invoices created by completed POS sales. It filters by date, customer/invoice search and payment status, and links to the existing receipt and customer collection workflows.
- These first versions do not create a second invoice ledger or duplicate payments. They are operational summaries, not a double-entry general ledger, bank reconciliation, tax filing engine or standalone recurring-invoice system.
- Apply `dibonke_finance_billing_permissions.sql` after the staff/roles migration. Administrator and Manager receive view access; Sales and Cashier receive Billing view access. The new dashboard pages are read-only; posting sales, collections, supplier payments and expenses continues through their existing workflows.


### Finance navigation and scope

The Finance section groups the Finance Overview, cashbook Ledger, Financial Control and Expenses. Billing & Invoices remains alongside Finance as a connected operational module.

- **Ledger:** chronological cash movements from completed POS receipts, later customer collections not already linked to a sale, supplier payments and expenses. It is not a double-entry general ledger.
- **Financial Control:** date-filtered cash summaries, payment-channel comparisons, current receivables/payables and basic data-quality / pending-payment alerts. It is not a bank reconciliation or statutory audit.
- **Expenses:** the existing expense register and posting workflow is reached from the Finance navigation. Its existing expense records remain the source of truth.
- General Settings now shows only company, currency, operational and document-numbering preferences. M-PESA credentials remain on the dedicated M-PESA page; secret fields are masked and blank secret submissions preserve the saved value. This UI masking does not by itself encrypt legacy values already stored in the settings table.


## Launch operations extension

Apply `dibonke_launch_operations.sql` after the base tables, Supply Chain extension and staff/roles permissions exist. It adds separate records for sales returns, cashier shifts, physical stock counts, audit events and an eTIMS invoice queue. It also seeds module permissions and a unified Launch Operations navigation permission.

The Launch Operations workspace currently provides:
- Return requests against original POS sale lines, approval/rejection and stock restoration through the stock movement ledger.
- Cashier shift opening and closing with opening float, expected cash, counted cash and variance.
- Physical stock count sessions, scanned/manual quantity entry and approved variance posting.
- An audit event register for actions performed in these new workflows.
- An eTIMS document queue for completed sales.

Important limitations before production:
- A return approval records the refund method but does not itself transfer money or create a separate refund payment transaction. The actual refund must be executed and reconciled through the selected payment channel.
- Cash shift expected cash currently includes opening float and completed Cash POS sales by the cashier. It does not yet reconcile later customer collections, mixed tender splits, cash refunds, paid-outs or cash drops. Do not use it as a final cash-control sign-off until those workflows are extended and tested.
- Audit events are captured by the new Launch Operations actions only. Existing historical `activity_logs` are not backfilled, and existing module actions have not all been instrumented yet.
- eTIMS is queue/readiness only. No live KRA API submission, signing, QR-code generation or acknowledgement handling is enabled. Obtain the correct KRA integration credentials/specification and test environment before enabling live tax invoicing.
- The current stock-count workflow snapshots active products when a count is created. Complete and approve counts carefully; only entered quantities are posted.

Back up the database before migration and test these workflows with test sales and products before launch.

### Launch operations completion migration

After the initial `dibonke_launch_operations.sql` migration, apply `dibonke_launch_operations_completion.sql`. This additive extension adds explicit return settlement records and cashier cash-in/cash-out movement records. It does not send money through M-PESA or a bank; the payment must be executed through the selected channel and its reference recorded. The cash shift close calculation uses posted cash transactions and recorded shift movements. Mixed POS tenders remain excluded until the POS records tender splits separately.

Completion migration details:
- `sales_return_payments` records actual refund settlements and their payment references; approved returns first reduce the original sale/customer receivable where a balance is still due, then any remaining amount must be settled.
- Cash refunds require an open cashier shift. Cash expenses and supplier cash payments recorded while a shift is open are linked to that shift using `expenses.cash_shift_id` and `supplier_payments.cash_shift_id`. Cash-in/cash-out adjustments are recorded in `cash_shift_movements`.
- `cash_shift_guard` and `stock_count_guard` serialize creation to prevent duplicate concurrent open shifts/count sessions.
- Users with Cash Management permission must open a shift before recording Cash POS sales, customer collections, supplier payments, purchase payments, cash expenses or cash refunds.
- Physical stock count approval is blocked until every product in the count snapshot has a counted quantity. SKU/barcode scanning increments the matching physical count by one; manual entry remains available.
- The shared audit writer records core sales, purchase, payments, expenses, customer, product, staff, user, role and receiving events. Older historical records remain in the legacy `activity_logs` table and are not backfilled into the structured audit register.
- eTIMS queueing stores a normalized Dibonke invoice data snapshot. It is not a KRA request and does not submit, sign, or receive acknowledgements from KRA. Live integration remains disabled until KRA's current interface specification, credentials, certificate/device details and sandbox access are supplied and tested.
- Cash shifts cannot allocate mixed POS tender because the existing POS does not persist individual tender splits. Such sales are deliberately excluded from expected cash; record each actual cash movement in the shift until tender splitting is implemented.


### Short document references and cash tender
After the base retail schema exists, apply `dibonke_short_references_and_cash_tender.sql`. It adds `sales.amount_tendered` for recording the physical amount handed over at a cash sale. New POS invoice numbers use `INV-YYMMDD-#####`; payment receipts use `RCP-YYMMDD-#####`, where the final digits are the sequential database record ID. Existing invoice/payment references are not rewritten.
