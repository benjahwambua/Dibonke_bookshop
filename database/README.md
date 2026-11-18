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
After the base retail schema exists, apply `dibonke_short_references_and_cash_tender.sql`. It adds `sales.amount_tendered` for recording the physical amount handed over at a cash sale. New document references use short date-and-record formats: invoices `INV-YYMMDD-#####`, purchases `PUR-YYMMDD-#####`, purchase orders `PO-YYMMDD-#####`, goods receipts `GRN-YYMMDD-#####`, deliveries `DEL-YYMMDD-#####`, returns `RET-YYMMDD-#####`, cashier shifts `CS-YYMMDD-#####`, stock counts `SC-YYMMDD-#####`, expenses `EXP-YYMMDD-#####`, customer collection receipts `CR-YYMMDD-#####`, supplier payment references `SP-YYMMDD-#####` and M-PESA request references `MP-YYMMDD-#####`. The final digits are the sequential record ID, making references short and collision-resistant. Existing historical references are not rewritten. Cash receipts show the actual amount received and change due; other payment methods continue to show the amount paid.


## Cashier self-service and eTIMS onboarding

- Sales and Cashier users can use **Sales → My Cashier Shift** to open their own shift, view their own expected-cash summary, record cash-in/cash-out movements and close/reconcile the shift. The page does not expose other cashiers' shifts or manager review controls; those remain under Launch Operations.
- The POS provides a My Shift shortcut that opens in a separate browser tab, so an in-progress basket stays on the POS screen. When a cashier has Cash Management permission and the shift guard is installed, the POS warns when no shift is open and continues to enforce the server-side cash-sale guard.
- Apply `dibonke_cashier_shift_sales_access.sql` after the staff/roles and Launch Operations migrations to grant Sales and Cashier roles view/open/close access to their own shift. Existing Administrator and Manager oversight remains in Launch Operations.
- The eTIMS Setup page records the taxpayer PIN, selected integration route, branch identifier and KRA onboarding/certification references. It is a registration checklist/profile only: live transmission is forced off, no API credentials are stored in these fields, and no KRA request is sent.
- Register the taxpayer with KRA first, confirm whether OSCU, VSCU or a certified third-party route applies, then obtain the current KRA technical specifications and sandbox/onboarding details. Implement the live sender only after the official technical and security requirements are available and sandbox/certification testing has been completed.


## Daily sales dashboard and direct M-PESA till collections

- The dashboard separates today's completed Cash and M-PESA sales. Sales/Cashier roles see only their own totals; users with the general dashboard view (including Administrator) see the business totals and a per-user breakdown.
- Direct PayBill / Buy Goods receipts use Safaricom Daraja C2B confirmation callbacks. The callback records the M-PESA transaction idempotently in the existing `mpesa_transactions` table.
- When the callback contains an exact Dibonke invoice reference (`BillRefNumber`) and the received amount does not exceed the outstanding invoice balance, the sale is automatically updated and a customer payment receipt is created where the sale belongs to a registered customer.
- A C2B receipt without a reliable invoice match remains recorded as **Unallocated** in M-PESA Integration. The system deliberately does not guess which customer's invoice to settle. Till callbacks may not provide a usable invoice/account reference, and Safaricom may mask the payer MSISDN in the C2B v2 callback.
- The M-PESA Integration page can register the C2B validation and confirmation URLs with Daraja. Use the public absolute URLs ending in `/callbacks/c2b_validation.php` and `/callbacks/c2b_confirmation.php`. These callback URLs intentionally do not contain the word “mpesa”. Production callbacks must be publicly reachable over HTTPS; localhost cannot receive Safaricom callbacks.
- Safaricom must enable external validation on the shortcode if validation is required. The validation endpoint currently accepts requests; it does not reject payments based on invoice existence. Confirm with Safaricom whether the supplied shortcode is eligible for C2B URL registration (PayBill / Buy Goods setup may differ by shortcode).
- For fully automatic invoice allocation, prefer a payment flow that supplies the Dibonke invoice number as the account reference (for example, PayBill account reference or Dibonke STK Push). A plain Till payment with no invoice reference can be detected automatically but may need reconciliation before it is credited to a specific invoice.
- This is callback code prepared for testing; it has not been registered against a live Safaricom shortcode or verified with a real payment.


## Chart of Accounts and HR foundation

After pulling `feature/dibonke-product-catalog`, import `database/dibonke_accounting_hr.sql` in phpMyAdmin. This migration creates the Chart of Accounts register and HR leave-type, leave-request and attendance tables, then grants the Admin and Manager roles access to Chart of Accounts and HR. Import the staff/roles migration first because this migration seeds `role_permissions`.

The Chart of Accounts currently establishes account codes, names and account types with a starter bookshop account set. It does not yet post journals or produce a Trial Balance. Those require validated source-to-account mappings and balanced journal posting. HR currently includes the existing employee directory plus leave requests/approval and attendance recording; payroll, statutory deductions, payslips and advanced leave accruals are not included in this phase.

Cashier login now routes to My Cashier Shift. Cashiers open a shift there before proceeding to the POS. Admin user management supports deactivate/reactivate and guarded deletion; accounts with linked business/audit history must be deactivated rather than deleted.


## Double-entry accounting: journals, General Ledger and Trial Balance

After importing `database/dibonke_accounting_hr.sql`, import `database/dibonke_double_entry_accounting.sql`. This creates journal headers and journal lines and grants the Admin and Manager roles access to Journal Entries, General Ledger and Trial Balance. The journal screen supports balanced draft journals and posting; posted journals are immutable through the standard screen. The General Ledger shows posted activity and brought-forward/running balances by account. The Trial Balance reports debit and credit balances from posted journals as at a selected date.

Opening balances should be entered as a balanced opening journal after agreeing the balances to the business records. The current implementation is a manual double-entry accounting layer: existing POS, purchases, collections, supplier payments and expenses have not yet been automatically posted into journals. Do not treat the Trial Balance as complete for the whole business until opening balances and source transaction mappings have been configured and reconciled. Draft journals are excluded from both reports.


## Employee HR self-service and aged receivables

After the staff/roles, HR foundation and double-entry migrations, apply `dibonke_hr_self_service_receivables.sql`. It grants employees access to their own HR self-service page and grants Admin/Manager access to Aged Receivables.

- **My HR Self-Service:** each login is matched to its linked `staff.user_id` profile. Employees can submit leave requests, review their own request history, cancel their own pending requests, and clock in/out once per day. Requests cannot overlap another pending or approved request for that employee. Clock actions use the server/database date and time; staff profiles must be linked and Active.
- **HR workspace:** the existing HR directory, leave approval and workforce attendance register remain restricted to users with the HR module permission. Employee self-service does not expose other employees' records.
- **Aged Receivables:** lists completed sales with positive outstanding balances, invoice date, customer, total, amount paid, balance and invoice-age buckets. Customer totals and bucket totals are included.
- **Ageing basis:** the current sales schema does not provide contractual invoice due dates, so this report ages from the sale/invoice date. “Current (0–30)” means issued within the last 30 days; it must not be interpreted as a definitive overdue classification until invoice due dates/payment terms are introduced.
- **Leave-day calculation:** requested days currently count weekdays (Monday–Friday) and do not yet exclude Kenyan public holidays or configured company holidays. Leave balances/accruals are not yet automatically deducted.


## Expense category defaults

Import `dibonke_expense_categories_seed.sql` once (it is safe to re-run). It seeds common bookshop operating expense categories without duplicating existing category names. The Expenses form loads all active records from `expense_categories` automatically, so the Category dropdown is populated from the database. The expense handler still validates and stores the selected category ID; no existing expense records or posting behavior are changed.


## Expense categories, report exports and customer statements

- The Expenses page now ensures the `expense_categories` master table exists, creates missing standard categories and reactivates matching default categories if they were previously inactive. It preselects the first available active category when recording an expense. This removes the need to rely on a separate manual category setup step. The matching SQL migration is also available as `dibonke_expense_categories_seed.sql`.
- Report pages provide **Print / Save as PDF** through the browser print dialog and **Export to Excel** as an Excel 2003 XML Spreadsheet workbook (`.xls`), which opens in Microsoft Excel and LibreOffice without an extra PHP package.
- Stock Movements supports date range, movement type, product and text search filters before printing/exporting.
- Customer names link to the customer account page. The customer account page provides invoice history and a date-filtered statement showing invoices as debits and customer receipts as credits, with opening and running balances.
- Aged Receivables links each named customer to their account and all invoice references to their receipts.

## Consolidated migration order and release checks

The following is a dependency guide for a development database. **Do not re-run a migration just because it appears in this list.** First check whether its tables/columns/permissions already exist. Take a backup before any DDL, and apply only the missing migrations. Some scripts are additive/re-runnable; others contain ALTER statements or seed logic that should be reviewed before repeating.

1. **Existing canonical retail base schema** — must already provide the operational tables used by the application: users, products, categories, brands, customers, suppliers, sales, sale_items, purchases, purchase_items, expenses, customer_payments, supplier_payments, stock_movements, settings, activity_logs and the supporting tables used by the current handlers. The repository does not yet provide a verified complete fresh-install base schema.
2. `dibonke_products_inventory_preflight.sql` — read-only inspection of product/POS/purchase/stock dependencies. Run before extensions and review its output.
3. `dibonke_staff_roles.sql` — creates roles, role_permissions and staff profiles; requires the existing users table.
4. `dibonke_products_inventory.sql` and `price_lists.sql` — apply after the base products, customers and sales tables exist.
5. `supply_chain_mpesa.sql` — creates PO/GRN and M-Pesa support; requires users, suppliers and settings.
6. `dibonke_goods_receiving_scanner.sql` — apply after the Supply Chain goods_receipts table and roles/permissions exist.
7. `dibonke_finance_billing_permissions.sql` — apply after the staff/roles migration.
8. `dibonke_accounting_hr.sql` — creates the Chart of Accounts and HR leave/attendance tables; requires roles and role_permissions.
9. `dibonke_double_entry_accounting.sql` — creates journal headers/lines; requires accounts from the previous step and roles/role_permissions.
10. `dibonke_launch_operations.sql` — creates returns, shifts, stock counts, audit events and eTIMS queue; requires the retail base tables plus roles/role_permissions/settings.
11. `dibonke_launch_operations_completion.sql` — apply after Launch Operations; adds refund settlement and cash movement support.
12. `dibonke_cashier_shift_sales_access.sql` — apply after staff roles and Launch Operations.
13. `dibonke_hr_self_service_receivables.sql` — apply after staff roles, accounting/HR tables, and sales/customers.
14. `dibonke_short_references_and_cash_tender.sql` — adds cash tender tracking to sales; apply after the base sales table exists.
15. `dibonke_expense_categories_seed.sql` — creates/seeds standard expense categories. The Expenses page also ensures the standard active categories exist at runtime.

The sequence above is not a substitute for checking the current database. A database that already has some or all of these extensions should not receive them again blindly. The local database is the source of truth for which migrations have already been applied.

## Final acceptance tests

Before production use, test the following against the actual Dibonke development database and the target PHP/MariaDB versions:

- PHP syntax check for all application files and browser testing for each role.
- Cashier login → open shift → POS sale → receipt → shift close/reconciliation.
- Cash, credit, M-Pesa STK and customer collection workflows, including failed/pending callbacks.
- C2B Till/PayBill callback reception, duplicate callback protection, unmatched payment review and invoice allocation.
- PO approval → partial/full GRN → stock ledger and supplier payable checks.
- Product opening stock, barcode/ISBN scanning, manual adjustments and stock count approval.
- Returns/refunds, including the actual external payment settlement and customer balance.
- Expenses and supplier cash payments against cashier shifts.
- Customer statements and aged receivables against known test invoices/receipts.
- HR staff linking, clock-in/out, leave requests and manager approvals.
- Excel export content and browser Print / Save as PDF output for each report.
- Accounting journal balancing, opening balances and reconciliation. Operational source transactions are not yet automatically posted to journals.
- Safaricom sandbox callbacks and KRA eTIMS official onboarding/certification before any live integration.
- Docker build, database import/restore, persistent-volume backup and restore.

A migration import, a GitHub commit or a visible page is not evidence that the workflow passed these acceptance tests.


## Fleet Management

Import `dibonke_fleet_management.sql` after the base deliveries and staff/roles tables are present. It creates the fleet vehicle register and vehicle maintenance history, adds the optional `deliveries.vehicle_id` link, and grants Fleet Management access to Administrator and Manager roles. The Deliveries screen then allows dispatchers to assign an active vehicle to a delivery. Fleet Management is available under Deliveries and supports vehicle registration, driver assignment, insurance/inspection expiry tracking, odometer records, maintenance logs, and safe deletion (vehicles with delivery or service history must be marked Inactive instead).


## Product Images

Import `dibonke_product_images.sql` after the base products table exists and before using the product catalogue or POS version with image support. Product photos accept genuine PNG or JPEG files only, up to 2 MB and 2400 × 2400 pixels. Uploaded files are stored under `uploads/products/` using random filenames; the directory blocks script execution and directory listing. Ensure the web server can write to `uploads/products/` (the application creates it on first upload).
