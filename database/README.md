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
| `dibonke_product_images.sql` | Adds the optional `products.image_path` column used by product image uploads and POS/catalogue thumbnails | Requires an existing `products` table; idempotent column check |
| `price_lists.sql` | Standard/wholesale price lists and product price tiers | Requires `products`, `customers` and `sales` |
| `supply_chain_mpesa.sql` | Purchase orders, goods receipts, supplier payments and M-PESA transaction storage | Requires base `users`, `suppliers` and `settings` tables |\n| `dibonke_goods_receiving_scanner.sql` | Adds supplier invoice date/total fields and grants Manager/Storekeeper receiving permissions | Requires `goods_receipts`, `roles` and `role_permissions` |
| `dibonke_expense_categories_seed.sql` | Creates and seeds the expense category master used by expense entry and category-level accounting mappings | No additional dependency beyond the selected Dibonke database |
| `dibonke_accounting_hr.sql` | Creates the Chart of Accounts and HR foundation | Requires existing `roles` and `role_permissions` |
| `dibonke_double_entry_accounting.sql` | Creates journal headers and lines, with journal, ledger and trial-balance permissions | Requires `accounts` from `dibonke_accounting_hr.sql` |
| `dibonke_staff_roles.sql` | Staff profiles and unified role permissions | Requires the existing `users` table |
| `dibonke_launch_operations.sql` | Sales returns, cash shifts, stock counts, audit events and eTIMS queue | Requires the existing retail tables plus `roles`, `role_permissions` and `settings`; uses indexed references without foreign-key constraints to tolerate inherited ID definitions |
| `dibonke_finance_billing_permissions.sql` | Adds Finance and Billing module permissions to existing roles | Requires `roles` and `role_permissions` from the staff/roles migration |
| `dibonke_automatic_accounting_posting.sql` | Adds source-linked, duplicate-protected journal posting, core account mappings and expense-category mappings for automatic postings | Requires `accounts`, `journal_entries`, `journal_lines`, `roles`, `role_permissions`, `settings` and `expense_categories`; apply after the accounting and expense-category migrations |
| `dibonke_payment_allocations.sql` | Customer invoice and supplier purchase/GRN payment allocation, explicit database role visibility, and return/refund account mappings | Requires `customers`, `sales`, `purchases`, `goods_receipts`, `customer_payments`, `supplier_payments`, `roles`, `role_permissions`, `users`, `accounts` and `accounting_mappings`; apply after the accounting, staff/roles and supply-chain migrations |
| `dibonke_book_covering.sql` | Exercise-book covering rules, per-sale material usage and covering charge fields | Requires `products` and `sale_items`; apply once after the base product/sales schema and before deploying the covering POS changes |
| `dibonke_book_covering_service.sql` | Current per-book covering service charges; the POS no longer consumes roll stock when a book is covered | Requires `products`, `sale_items` and the covering columns from `dibonke_book_covering.sql`; apply after that migration |
| `dibonke_internal_inventory_use_accounting.sql` | Dedicated expense account mapping for rolls issued for internal use | Requires `accounts` and `accounting_mappings`; apply after the double-entry accounting migrations |
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

## Book covering and cover-roll sales

Apply `dibonke_book_covering.sql` after the base product and sales tables exist, then apply `dibonke_book_covering_service.sql`. The first migration adds the historical covering fields to `sale_items`; the second creates the current per-book service-charge rules. If the older covering migration was already applied, apply only the service migration.

Create the cover roll as an ordinary product with stock unit `Roll`, purchase unit `Roll`, and the current retail selling price (initially KES 200 per roll). A customer buying a roll adds that product to the POS basket; completing the sale deducts one whole roll per roll sold. In Catalogue → Book Covering Setup, configure the extra covering charge for each exercise-book product. The POS checkbox adds that charge per book and stores the quantity and charge on the sale item. It does not consume cover-roll stock or add centimetre deductions.

When the shop uses one or more complete rolls internally rather than selling them, record the quantity in the Book Covering Setup internal-use form. This writes a stock adjustment with reference type `book_covering_internal_use` and an audit event. The current internal-use workflow records whole rolls only; it does not estimate partial-roll usage or allocate material consumption to each covered book.

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
16. `dibonke_bulk_unit_conversion.sql` — adds bulk purchase/sale unit conversion fields. Apply before using the updated product, purchase and POS screens.\n17. `dibonke_store_operations.sql` — creates Main Store, Store 1 and Store 2, store-level stock balances, daily issue/return transfers and reconciliation records; adds store attribution to POS sales and grants the Store Operations permission to Administrator and Manager roles. Apply after the base products, sales, sale_items, stock_movements, users, roles and role_permissions tables exist.

The sequence above is not a substitute for checking the current database. A database that already has some or all of these extensions should not receive them again blindly. The local database is the source of truth for which migrations have already been applied.

\n\n## Store Operations\n\nApply `dibonke_store_operations.sql` after the retail base tables and role permissions are present. It creates Main Store, Store 1 and Store 2. Main Store inventory continues to use the existing `products.stock_quantity` balance; separate selling-store balances are held in `store_stock`. The owner issues stock to a selling store each day, POS sales are attributed to the selected store and deducted from that store's balance, and the end-of-day reconciliation transfers all returned quantities back to Main Store. The system compares POS quantities with issued/returned quantities and records unexplained stock differences separately. New stock ledger movements are attributed to their physical location using the nullable `stock_movements.store_id` column added by this migration. POS sales use the selected store; opening stock, purchases, goods receipts, customer-order invoices, Main Store adjustments, approved returns, stock counts and internal book-covering use are attributed to Main Store. The Stock Movements register can display, search and filter by store. Historical movement rows are not automatically assigned a store because their location may not be reliably known. Store issue/return transfers remain recorded in the dedicated transfer register and are included in Main Store stock-count roll-forward calculations. It compares money handed over (cash, M-Pesa, bank and other) with POS amounts actually paid for that store and business date. A store's previous open day must be reconciled before fresh stock can be issued. Sellers are recorded as the person in charge only; they do not need application accounts.\n\n## Final acceptance tests

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


## Bulk purchasing and bulk / individual sales

After pulling the application changes, back up the database and import `dibonke_bulk_unit_conversion.sql`. This additive, re-runnable migration adds product purchase-unit settings and optional bulk selling prices, plus transaction detail columns that preserve the entered quantity, unit, conversion factor and entered unit price. It does not rewrite existing stock balances or historical transaction rows.

In Product Setup, set the smallest stock-counting unit (for example Piece), the purchase unit (for example Carton), and how many stock units make one purchase unit (for example 50). The optional bulk selling price is the price for one full purchase unit; if left blank, the POS calculates it from the selected price list's per-stock-unit price.

Direct purchases and purchase orders can then be recorded either in the stock unit or in the configured purchase unit. Purchase orders store their quantities in stock units internally; the goods-receiving screen asks for received quantities in the stock unit shown. POS sales can be entered as individual stock units or as full purchase units. The products table, stock movements, sale-item quantity and purchase-item quantity continue to use the smallest stock unit; transaction detail columns retain the unit staff entered. Existing products default to a conversion factor of 1, so their stock balances and old transactions remain unchanged.

Product buying price is maintained per smallest stock unit for inventory valuation and cost of goods sold. Purchase-order and direct-purchase entry prices are converted to this per-stock-unit cost when saved. When receiving bulk purchases, staff enter the total price for the selected bulk unit; the handler converts it to a per-stock-unit cost before updating the product.

Test with a disposable product: configure Piece / Carton / 50, receive 5 Cartons, confirm stock increases by 250 Pieces, sell 2 Cartons and 7 Pieces in the same POS basket, and confirm stock decreases by 107 Pieces to 143. Also test a base-unit purchase and sale, insufficient stock, optional bulk price, and printed receipts. Do not use a live stock item for the first test.

## Product Images

Import `dibonke_product_images.sql` after the base products table exists and before using the product catalogue or POS version with image support. Product photos accept genuine PNG or JPEG files only, up to 2 MB and 2400 × 2400 pixels. Uploaded files are stored under `uploads/products/` using random filenames; the directory blocks script execution and directory listing. Ensure the web server can write to `uploads/products/` (the application creates it on first upload).


## Accounting migration order

For a database that does not yet have the double-entry tables, use this dependency order after the compatible Dibonke base tables exist:

1. `dibonke_staff_roles.sql` — establishes roles and role permissions.
2. `dibonke_expense_categories_seed.sql` — establishes expense categories.
3. `dibonke_accounting_hr.sql` — creates the Chart of Accounts and HR tables.
4. `dibonke_double_entry_accounting.sql` — creates journal headers and lines.
5. `dibonke_automatic_accounting_posting.sql` — adds source links, posting mappings and automatic-posting controls.

If some migrations are already present, inspect the schema first and apply only the missing migration(s). Do not re-import non-idempotent scripts blindly.

## Automatic double-entry posting

Apply `dibonke_automatic_accounting_posting.sql` after the Chart of Accounts and Journal Entries migrations and after expense categories exist. It adds source references to journal headers so each source transaction can be posted only once, creates core account mappings, and seeds expense categories to the default operating-expense account.

Open **Finance → Posting Mappings** to review the mappings. Automatic posting is initially disabled. Confirm the cash, M-Pesa, bank, clearing, receivables, inventory, payables, VAT, revenue and cost-of-sales accounts, then enable it. Map individual expense categories to more specific Expense accounts where required.

Once enabled, new POS sales post revenue, output VAT, the payment/receivable split and cost of goods sold/inventory relief. Direct purchases post inventory, input VAT, payment and supplier payable. Purchase-order goods receipts post inventory and supplier payable. Expenses post to their mapped expense account and payment account. Later customer collections, supplier settlements and confirmed M-Pesa STK/C2B receipts post their settlement journals.

Posting runs inside the originating transaction and uses a unique source reference to prevent duplicate journals. If journal posting fails, the source transaction is rolled back. Existing historical transactions are not automatically backfilled; opening balances and historic transaction journals must be reconciled separately before treating the Trial Balance as complete. M-Pesa receipts that cannot be matched to an invoice are held in the Unallocated M-Pesa Receipts liability account pending allocation.

## Customer sales orders and invoice approval

Apply `dibonke_customer_sales_orders.sql` after the existing base schema, role tables and Dibonke role migrations. It creates separate customer order headers and lines and grants the Customer Sales Orders module to Admin, Manager and Sales roles without overwriting existing permissions.

Workflow:
1. Create an order for an active registered customer. Product lines support stock units and configured bulk purchase units.
2. Review the saved Draft order and validate it. Validation checks current stock but does not deduct stock or create a sale.
3. Validated orders appear in Pending Invoices.
4. Confirming an order rechecks stock and the customer's available credit, creates one completed credit invoice, updates the customer balance, deducts stock in base units, records stock movements and posts accounting entries when automatic posting is enabled.
5. Print the confirmed customer invoice from the order.

POS supports walk-in and registered-customer sales, including split tenders across Cash, Bank and M-PESA. Cash and Bank are recorded as confirmed tenders; an STK amount remains pending until Daraja confirms it. Existing POS invoice history remains in the sales register.

## Product packaging units and retail/wholesale price matrix

Import `dibonke_packaging_price_matrix.sql` after `dibonke_bulk_unit_conversion.sql`. It adds the optional `bulk_wholesale_price` field and preserves existing product prices.

Product setup now uses dropdowns for the sale/stock unit and supplier purchase unit. The smallest unit (for example Piece) remains the stock unit. The purchase unit (for example Box or Carton) is converted using Units per Purchase Unit. Example: Sale/Stock Unit = Piece, Purchase Unit = Box, Units per Purchase Unit = 12. One box is 12 pieces; staff can sell either one box or individual pieces.

The Price Lists screen displays four prices per product: Retail per stock unit, Retail per purchase unit, Wholesale per stock unit and Wholesale per purchase unit. When a package-specific price is blank, the screen and sales workflow calculate it from the relevant per-piece price multiplied by the conversion factor. Set explicit package prices on the product form when a different package price is required. POS and Customer Sales Orders use the package price for the selected Retail or Wholesale list.


## Coordinated financial and operational controls update

After the existing accounting, staff/roles and Supply Chain migrations, apply `dibonke_payment_allocations.sql` once to the target development database. This migration creates customer invoice and supplier payment allocation tables, adds the two accounting mappings used for returns/refunds, assigns legacy users to matching configured roles, and seeds existing role-based module visibility as explicit database permissions.

The matching application changes:
- Allocate later customer receipts FIFO against completed invoices. POS receipts and confirmed M-PESA invoice payments are linked directly to their sale.
- Allocate supplier payments FIFO across received purchases and GRNs. Existing supplier payments linked directly to a GRN are included in the GRN's paid amount. Historical supplier payments with no document reference are not guessed; if the supplier summary balance does not reconcile to open purchase/GRN documents, further supplier payment posting is blocked until the account is reconciled.
- Provide Finance → Aged Payables for outstanding purchases and GRNs, alongside Aged Receivables for customer invoices.
- Use weighted-average product buying cost when stock is received through direct purchases or GRNs. Inventory quantities remain expressed in each product's own stock unit.
- Read module permissions from `role_permissions`; the legacy hard-coded role visibility fallback has been removed. Apply the migration before deploying this permission change, then review custom roles in Staff & Access → Roles & Permissions.
- Post approved sales returns and actual refund settlements to the configured return, receivable/refund-payable, inventory and cost-of-goods-sold accounts when automatic accounting is enabled. The migration creates the `sales_returns` and `refund_payable` mappings; confirm them before enabling automatic posting.
- M-PESA STK callbacks must match a stored checkout request and merchant request ID. Duplicate successful callbacks are acknowledged without reapplying the payment or downgrading a successful transaction.
- The M-PESA Developer Settings page blocks switching to Production until Daraja credentials are encrypted at rest and live callback verification is complete. Continue sandbox testing only.
- eTIMS remains onboarding/queue preparation only; no KRA transmission or production certification is claimed.

A manual database and uploads backup/restore procedure is documented in `docs/DIBONKE_BACKUP_RECOVERY.md`. It is not an automated scheduler. Run the acceptance tests against the actual XAMPP/MariaDB database before treating this coordinated update as production-ready.


### Customer return accounting mappings

The existing `dibonke_payment_allocations.sql` migration seeds the customer return mappings against account codes 4050 (Sales Returns and Allowances) and 2060 (Customer Refunds Payable). The follow-up `dibonke_sales_return_accounting.sql` migration is an idempotent compatibility check for installations where those mappings need to be ensured separately; apply it after `dibonke_automatic_accounting_posting.sql` and `dibonke_payment_allocations.sql`. It uses the same account codes and mapping keys, so it does not create duplicate return accounts.

The Posting Mappings screen now exposes both mappings. Confirm that Sales Returns maps to an active Income account and Refund Payable maps to an active Liability account before enabling automatic posting.


### Customer credit ledger migration

Apply `dibonke_customer_credit_ledger.sql` after `dibonke_payment_allocations.sql` and the automatic double-entry accounting migrations, before deploying the PHP changes that record M-PESA overpayments. It creates the customer credit ledger and seeds liability account 2070 plus the `customer_credit` posting mapping. Review that mapping in Finance → Posting Mappings before enabling automatic posting.

The ledger records excess receipts separately from invoice allocations. The Customer Account page now supports applying available credit to an outstanding invoice and refunding wallet credit through Cash, Bank or M-PESA settlement recording. Each application/refund creates debit ledger entries linked to the original receipt, an application/refund register row, an audit event and an accounting journal when automatic posting is enabled.

Customer credit receipt behavior: the Customer Payments screen lists all active customers, including customers with zero outstanding invoices, and accepts the full amount received. The shared allocator applies funds to open completed invoices FIFO and records any remainder in `customer_credit_ledger`. Confirmed STK payments against invoices also preserve excess amounts, including when the invoice is already settled by the time the callback arrives.


### Payment callback and store close safeguards

- STK success callbacks require a positive confirmed amount and an M-PESA receipt number, and must resolve to the original stored sale or customer-payment request. Missing/orphaned links and receipt-number reuse are rolled back instead of being marked as completed.
- C2B payments matched by invoice reference to a registered customer apply only the invoice's remaining balance. Any excess is stored in `customer_credit_ledger` and posted to the `customer_credit` liability mapping when automatic accounting is enabled. The full received amount remains in the customer receipt record.
- Store Operations refuses to reconcile a business day while a linked STK request is still pending. This check requires `mpesa_transactions`; without that optional table, the rest of Store Operations remains available.
- These controls do not replace sandbox callback testing. Confirm the customer-credit migration is applied before processing customer-linked STK/C2B overpayments.


Customer account statements use payment-allocation totals as the receivable credit, show wallet credit separately and include wallet credit applications as statement credits. Historical receipts with no allocation or credit-ledger row retain the legacy full-receipt treatment. This keeps overpayments from appearing as negative customer receivables.


### M-PESA applied / unallocated amount tracking

Apply `dibonke_mpesa_allocation_amounts.sql` after `supply_chain_mpesa.sql` and before deploying the callback code that writes `mpesa_transactions.amount_applied` and `amount_unallocated`. The migration is additive and rerunnable. New successful STK and C2B callbacks persist the amount applied to an invoice/customer account and any excess separately. Finance Overview, Financial Control and the Cashbook use those recorded amounts so a walk-in overpayment is not hidden and the original payment is not counted twice. The M-PESA transaction register can query pending STK status and retry failed/cancelled STK requests linked to a sale. A query response indicating success does not itself post the payment; Dibonke waits for the callback carrying the actual receipt number.

The migration safely backfills historical successful unmatched C2B receipts as wholly unallocated because those rows have no target reference. It does not guess historical STK or previously matched C2B invoice excess because the old rows do not reliably preserve how much of each receipt was applied. Those matched transactions must be reconciled from Daraja statements and source invoices rather than guessed. Test new callbacks after applying the migration.


### POS split tender and cashier/store reconciliation

Apply `dibonke_sale_payment_lines.sql` after the base sales schema, `supply_chain_mpesa.sql` and `dibonke_store_operations.sql`, before deploying the split-tender POS code. It creates a per-sale tender register, safely backfills existing completed sales using their recorded payment method and paid amount, and adds an expected-bank field to store daily sessions where that table exists.

The POS now accepts cash received, bank/transfer received and an M-PESA STK amount in the same sale. Cash change is calculated after the bank and M-PESA amounts. Cash and bank tenders are confirmed at sale completion; the M-PESA portion remains pending until the callback confirms the receipt. A failed STK leaves that amount outstanding. Receipts show the tender breakdown. Finance channel reports, cashbook, cashier shift and Store Operations use confirmed tender lines. For registered customers, confirmed cash/bank tender rows also create customer payment receipts and invoice allocations.

### Customer credit application and refund workflows

Apply `dibonke_customer_credit_workflows.sql` after `dibonke_customer_credit_ledger.sql`, `dibonke_payment_allocations.sql` and the automatic accounting migrations. It creates the customer credit application and refund registers. On a customer account, available wallet credit can be applied to an open invoice or refunded via Cash, Bank or M-PESA. Cash refunds require an open cashier shift when cash management is enabled. These actions are journalled and audited when automatic posting is enabled. Reconcile wallet balances and the payment account before issuing a real refund.

### Accounting period locks and financial statements

Apply `dibonke_accounting_period_locks.sql` after the double-entry accounting migration. Finance → Period Controls can lock a date range. Automatic and manual journals dated inside a locked period are rejected. Lock periods only after completing reconciliations and reviewing the trial balance.

Finance → Financial Statements provides posted-journal Profit and Loss and Balance Sheet reports, plus a cash/bank movement view based on mapped cash accounts. The cash movement view is a management summary, not a certified indirect cash-flow statement. Configure account mappings and opening balances, then reconcile the reports to the source registers before relying on them.

### Inventory valuation snapshots

Apply `dibonke_inventory_valuation_snapshots.sql` after the products inventory and store operations migrations. Inventory → Valuation Snapshots saves dated Main Store and selling-store quantities at the current weighted-average buying price. Snapshots preserve the valuation used on that date for review and export. This is an auditable point-in-time snapshot, not a FIFO/LIFO cost-layer ledger; historical unit cost is still based on the product's weighted-average buying price.
