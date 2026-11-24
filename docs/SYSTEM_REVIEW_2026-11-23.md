# Dibonke Bookshop — Whole-System Review

**Review date:** 23 November 2026  
**Reviewed branch:** `feature/dibonke-product-catalog`  
**Review basis:** Static review of repository source, SQL migration documentation, and the user manual. This is not a live XAMPP/database/browser test.

## Executive summary

The repository contains a broad operational system: catalogue, POS, purchasing and goods receiving, inventory, customer and supplier accounts, expenses, cashier shifts, returns, stocktaking, finance/accounting, HR, M-Pesa integration preparation, reporting, and multi-store operations.

The main risk before deployment is not a missing screen. It is ensuring that the database being deployed has the exact compatible schema and that the business workflows reconcile correctly under real use.

**Do not treat this review as production sign-off.** A complete fresh-install base schema is not currently documented as available in the repository. The existing local database is therefore an important dependency and must be backed up and migrated deliberately.

## Module review

| Area | Existing implementation found | Review outcome / remaining verification |
|---|---|---|
| Login and sessions | Authenticated sessions, active-user recheck, session regeneration on invalid account, role refresh from database | Test login, logout, expiry, disabled accounts and concurrent sessions on local XAMPP. |
| Users and roles | User administration, role assignment, role permissions, route-level permission mapping | Test every role against direct URLs and POST handlers, not only sidebar visibility. |
| Dashboard | Daily/monthly sales, payment-channel summaries, recent transactions and role-scoped views | Reconcile dashboard totals against sales and payment registers, including refunds and later M-Pesa confirmations. |
| Products and catalogue | Product records, categories, brands/publishers, SKU/barcode/ISBN fields, prices, price lists, package conversions and product images | Confirm all required migrations exist in the live DB; test duplicate SKU/barcode handling and unit conversion with real examples. |
| Inventory | Main stock balances, stock movement records, low-stock/reorder concepts, physical stocktaking and adjustments | Test opening stock, receipts, sales, returns, internal use and stock count posting against one known product ledger. |
| POS and receipts | Product search/scanning, cart, customer selection, price lists, cash, M-Pesa and bank methods, receipt printing, store selection | End-to-end test each payment method, receipt alignment, cash tender/change, stock deduction and failed/pending M-Pesa cases. |
| Purchasing | Supplier purchases and weighted-average buying-cost update on receipt | Confirm whether direct purchase receipt and PO/GRN workflows are both used operationally; prevent the same delivery being received twice. |
| Supply chain / GRN | Purchase orders, goods receipt posting, stock update and supplier payable creation | Test partial receipt, multiple receipts against one PO, over-receipt protection and supplier balance reconciliation. |
| Customers and collections | Customer accounts, credit balances, payment allocation, statements and aged receivables | Test partial payments, excess receipts, oldest-invoice allocation and customer balance against invoice balances. |
| Suppliers and payments | Supplier accounts, purchase history, supplier payments and payable tracking | Reconcile supplier statements against received purchases and payment allocations. |
| Expenses | Expense categories, expense entry and accounting-posting integration points | Test expense approval/permissions, payment method, cashier-shift linkage and account mapping. |
| Cash management | Cashier shifts, opening float, cash-in/out movements, close and review | Existing documentation identifies limitations around tender splits and certain cash movements. Verify every supported cash flow before relying on shift variance as final control. |
| Sales returns and refunds | Return request, approval, restocking and refund settlement records | Test partial/full returns, non-restockable goods, customer balance adjustment and actual cash/M-Pesa refund settlement. |
| Finance and accounting | Chart of Accounts, journals, General Ledger, Trial Balance and posting mappings | Confirm source modules post exactly once when auto-posting is enabled; reconcile opening balances and test journal reversal/correction procedures. |
| Reports and analytics | Sales, margin/profit, stock movement and other operational reporting with print/export options | Check report totals against source registers and confirm date/store filters do not mix locations or count transfers as sales. |
| HR | Staff directory, leave requests/approval, attendance and self-service | Payroll, statutory deductions and payslips are outside the documented current scope. |
| M-Pesa | STK and C2B callback preparation, transaction records, allocation and unallocated-payment handling | Live Daraja registration, real callback reception, duplicate callback handling and late-payment reconciliation require sandbox/production testing. |
| eTIMS | Setup/readiness records and normalized invoice queue/snapshot | Not a live KRA submission integration. Do not represent it as certified or transmitting invoices. |
| Store Operations | Main Store and selling stores, daily stock issue, printed issue checklist, POS store attribution, returned-stock transfer and EOD reconciliation | Test full daily cycle at each selling store. See the specific risks below. |
| Deployment and backups | Environment-based DB settings, Docker development notes and deployment checklist | Production secrets, HTTPS, database restore, uploaded-file backup and target-host testing remain deployment tasks. |

## Follow-up integration pass — 24 November 2026

A source-level follow-up traced the purchase, POS, customer receipt, supplier payment, expense and finance-summary paths. Two confirmed accounting/reporting defects were corrected on the feature branch:

1. **Purchase journal payment direction:** when a purchase had an amount paid immediately, the automatic journal incorrectly debited the cash/bank payment account. It now credits the payment account, balancing the entry against inventory/input VAT debits and the remaining supplier payable credit.
2. **Finance collection reporting:** the Finance overview previously added the paid amounts on sales to separate customer receipts. Later customer receipts are allocated by increasing invoice `sales.paid_amount`, while the receipt itself remains in `customer_payments`; this could double-count collections and attribute later collections to the original sale date. The overview now totals customer receipts by their actual payment date and adds only paid walk-in sales that do not have customer receipt rows. The payment-method summary uses the same source split and combines matching methods.

These are code-level corrections based on the current source paths. They have not yet been exercised against the user's local database. Automatic accounting remains opt-in; do not enable it until the account mappings and opening balances have been reviewed. A full transaction-by-transaction reconciliation still requires a restored test database and local acceptance testing.

## Financial Control follow-up — 24 November 2026

The Financial Control report had the same collection double-counting/date-attribution defect previously found in the Finance overview. It counted paid amounts from sales (which are increased when later receipts are allocated) and separately added only unallocated customer-payment rows. It now counts customer payment records by actual payment date, plus paid walk-in sales with no customer payment row. Its payment-channel breakdown uses the same source split, while supplier payments, expenses and recorded refunds remain separate outflows.

## Findings requiring attention

### 1. Critical — database installability and migration control

The repository's README and database migration guide explicitly state that there is no verified complete fresh-install base schema. The SQL files are extensions that depend on an existing retail database.

**Action before deployment:**
1. Take a complete database export from the working local system.
2. Keep the backup outside the public web root and outside Git.
3. Record which migrations have already been applied.
4. Apply only missing migrations in documented dependency order.
5. Restore the backup to a separate test database and prove that the application starts and the core workflows work from that restored copy.

Do not attempt to create a production database by importing extension migrations into an empty database.

### 2. High — Store Operations stock movement attribution

**Code change made during this review:** the Store Operations migration now adds a nullable `stock_movements.store_id` column and an index idempotently. New movement records are attributed to Main Store or the selected selling store for POS sales, opening stock, direct purchases, goods receipts, customer-order invoices, manual adjustments, approved customer returns, stock counts and internal book-covering use. The Stock Movements register can display, search and filter by store. Existing handlers retain a legacy insert fallback until the additive migration is applied.

Stock count approval now rolls forward only Main Store stock movements and also accounts for posted store transfers into and out of Main Store after an item was counted. This prevents selling-store POS movements from being incorrectly applied to the Main Store physical count. Issue/return transfers remain traceable in the dedicated Store Transfers register and are included in the stock-count roll-forward.

Historical movement rows have not been backfilled because their store cannot always be proven from old free-text notes.

**Remaining action:** re-import the updated `database/dibonke_store_operations.sql` after backing up the local database. Complete or cancel any active physical stock count before importing it, then test purchases, GRNs, Main Store sales, selling-store sales, transfers and stock-count approval.

### 3. High — payment confirmation timing during store reconciliation

M-Pesa STK sales are created before the asynchronous payment confirmation arrives. The sale can therefore exist while its paid amount is still zero. The store reconciliation currently calculates expected cash/M-Pesa from the paid amounts recorded at the time the owner closes the day.

If a confirmation arrives after the store has been reconciled, the saved expected-channel figures and variance will not automatically be recalculated.

**Recommended follow-up:** show pending M-Pesa transactions prominently in the daily close process and provide a controlled, audited post-close reconciliation adjustment/recalculation when a late confirmation is received. Do not silently rewrite a signed-off daily record.

### 4. High — mixed tender is not a fully supported reconciliation path

The POS currently stores one payment method and one paid amount per sale. It does not persist separate tender lines for a sale split across cash and M-Pesa/bank. The documentation also identifies mixed-tender limitations in cashier-shift reconciliation.

**Recommended follow-up:** if mixed payments are needed, add a dedicated sale-tender table and migrate POS, receipts, cashier shifts, store reconciliation, reports and accounting together. Until then, do not claim that split-tender reconciliation is supported.

### 5. Medium — audit coverage and correction controls

The shared audit writer is used in core and newer workflows, but the migration documentation notes that not all historical/legacy actions are represented in the structured audit register.

**Recommended follow-up:** standardize audit events for create, edit, deactivate, approve, post, void, refund and reconciliation actions. Prefer reversal/correction records over deleting posted financial or stock history.

### 6. Medium — store day-close controls

The current store workflow requires an earlier open day to be reconciled before issuing a fresh day. The owner should also verify:
- every issued line is physically counted at issue and return;
- damaged/lost stock is explained separately from normal sales;
- cash and M-Pesa are compared independently;
- a completed reconciliation is not edited without an authorised, logged correction;
- a late payment or refund is visible after the original close.

### 7. Medium — accounting completeness

The system includes manual journals and source-module posting integration points. A Trial Balance is only reliable when opening balances, mappings and all in-scope source postings have been reconciled. A successful journal screen or balanced journal alone does not prove the books are complete.

## Integration audit follow-up — 24 November 2026

A focused source-to-source integration pass was performed after the initial whole-system review.

### Confirmed operational connections

- POS sales update the selected store's stock, create sale items and stock movements, and update the customer account when a customer is attached.
- Customer receipts allocate against outstanding completed invoices; supplier payments allocate against outstanding received purchases and goods receipts.
- Direct purchases and goods receipts update inventory and supplier balances through separate documented workflows.
- Expenses, sales, purchases, goods receipts, customer receipts, supplier payments and approved returns contain automatic journal-posting paths. These paths only run when automatic posting is enabled and the required account mappings exist.
- Cashier shift summaries draw cash sales and standalone customer collections by cashier/time window, and supplier payments and expenses by their shift references.

### Integration gap fixed

The POS had a customer list available in its server-side data but did not expose a customer selector, and the sale handler explicitly set the customer ID to null. This prevented a normal POS sale from being linked to a customer, so POS credit sales and customer-level statements/collections could not use that path.

**Code change made:** added an optional customer selector to POS checkout, persisted the selected customer on the sale, and automatically selected that customer's assigned active price list when available. Existing server-side credit-limit, customer-balance, payment-allocation and sale validation logic is reused. Walk-in sales remain the default when no customer is selected.

### Important limits — not represented as complete integration

- Automatic accounting posting is disabled by default in the migration. An administrator must review the chart of accounts and mappings, enable posting deliberately, and reconcile opening balances. Enabling it does not backfill historical transactions.
- Operational reports and analytics query their operational source tables; they are not a substitute for a reconciled General Ledger or Trial Balance.
- Returns/refunds, store close, cashier shifts and late payment events still require the end-to-end acceptance tests listed below. Static code inspection cannot prove that balances reconcile on the live database.
- No live database, browser, printer or hosting tests were performed during this follow-up.

### Verification

- GitHub Actions workflow runs 45409746614 and 45409759347 passed PHP syntax checks on PHP 8.0 and PHP 8.2 and the product helper tests after the POS integration change.
- One intermediate run failed while the POS markup correction was in progress; the corrected branch head passed both matrix jobs.

## Changes made during this review

- Corrected a stylesheet formatting issue in the Store Operations product-search field.
- Added idempotent store-location attribution to the stock movement migration and updated the stock-writing paths for POS, opening stock, direct purchases, GRNs, customer-order invoices, adjustments, sales returns, stock counts and internal book-covering use.
- Updated stock-count roll-forward to use Main Store movements plus posted transfers, excluding selling-store sales from Main Store count calculations.
- Updated the Stock Movements register with store display/search/filter and fixed repeated named PDO search placeholders.
- No live database or business data was changed. The updated migration still needs to be imported locally after a backup.
- No unverified changes were made to payment callbacks or accounting postings because those require end-to-end testing against the live database.

## Required acceptance tests before release

1. Administrator, Manager and restricted user login; test direct URL and POST permission enforcement.
2. Create/edit/deactivate/reactivate products, categories, suppliers, customers and users.
3. Receive stock through the selected purchasing/GRN route and verify stock plus supplier balance.
4. Sell at Main Store and each selling store; test barcode, search, unit conversion, discounts, cash, bank, M-Pesa and credit.
5. Print receipts and the A4 daily issue checklist on the actual target printer.
6. Issue stock, record sales, count returns, record actual collections, reconcile and verify Main Store/store stock balances.
7. Test stock shortages, damaged stock, a failed payment, a pending M-Pesa payment and a callback received after close.
8. Process customer collections, supplier payments, expenses, returns and refunds; reconcile cash shifts.
9. Complete and approve a stock count; compare the resulting stock movement ledger and inventory valuation.
10. Reconcile customer and supplier statements, sales/profit reports and finance reports against source transactions.
11. Verify accounting mappings, opening balances, automatic posting and Trial Balance.
12. Test backup and restore of both database and uploaded product images on the intended hosting environment.

## Test evidence and limitations

- GitHub Actions PHP syntax checks passed on PHP 8.0 and PHP 8.2, and the product helper tests passed (workflow run 45182698506).
- This review is based on repository source and documentation, not execution against the user's XAMPP installation.
- The assistant cannot see the local database, browser, receipt printer or hosting account in this review.
- No claim is made that the full business workflows above have passed.
- The application should not be declared production-ready until the database restore and acceptance tests have been completed.
