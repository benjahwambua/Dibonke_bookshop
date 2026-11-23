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

The POS writes stock movement records for sales made at selling stores, but the existing stock movement structure does not establish a dedicated store/location field in the store operations extension. Store identity is currently carried in free-text notes for those movements.

This makes a unified stock ledger by store harder to query and audit reliably.

**Recommended follow-up:** introduce an additive, nullable store reference on stock movement records, backfill only where the source transaction reliably identifies the store, and update movement reports to display/filter by location. This needs a schema-aware migration and compatibility test against the live database before implementation.

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

## Changes made during this review

- Corrected a stylesheet formatting issue in the new Store Operations product-search field. The intended CSS rules are now separated by a real newline rather than a literal escaped newline.
- No database schema or live business data was changed.
- No unverified changes were made to payment callbacks, accounting postings or stock ledger structure because those require the live database schema and end-to-end testing to avoid corrupting existing records.

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

- This review is based on repository source and documentation, not execution against the user's XAMPP installation.
- The assistant cannot see the local database, browser, receipt printer or hosting account in this review.
- No claim is made that the full workflows above have passed.
- The application should not be declared production-ready until the database restore and acceptance tests have been completed.
