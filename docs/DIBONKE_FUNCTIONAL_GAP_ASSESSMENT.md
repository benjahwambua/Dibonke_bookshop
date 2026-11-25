# Dibonke Bookshop — Functional Gap Assessment

**Assessment date:** 20 November 2026  
**Repository:** `benjahwambua/Dibonke_bookshop`  
**Branch reviewed:** `feature/dibonke-product-catalog`  
**Assessment type:** Source-code review of selected current-branch files. This is not a live application, database, or end-to-end workflow test.

## Executive summary

Dibonke already has a meaningful operational foundation: product catalogue, retail POS, customer sales orders, purchase orders and receiving, direct purchases, supplier/customer records, stock movements, expenses, cashier shifts, payment records, basic finance screens, double-entry journal infrastructure, reports, M-Pesa integration work and role-based access.

The largest risks are not simply missing menu items. They are gaps between related records and workflows. In particular:

1. Customer receipts are recorded against a customer balance, not allocated to individual invoices. The receipt code inserts customer payments with `sale_id = NULL` and reduces `customers.balance`, while Aged Receivables reads each invoice's `sales.balance`. This can leave invoice-level ageing out of step with the customer's account balance.
2. Supplier payments reduce the supplier's aggregate balance, not a selected supplier invoice. The payment record has no purchase allocation in the reviewed workflow; purchase-level balances can therefore be difficult to reconcile to payments.
3. Inventory value is estimated using current `products.buying_price`, rather than a demonstrated cost-layer / weighted-average valuation. The inventory screen also sums quantities across products with different units, so the headline “total units” is not a meaningful common unit.
4. The finance overview is a cash movement summary, not a full Profit & Loss, Balance Sheet, or cash-flow statement. Double-entry accounting infrastructure exists, but automatic posting is configurable and its completeness across every transaction type still needs verification.
5. Permissions currently combine database role permissions with a hard-coded legacy role/module fallback for viewing. This should be rationalised and tested consistently across every module and action.

These are the first areas to address before expanding the feature set.

## Status definitions

- **Implemented — source confirmed:** Relevant code is present in the files reviewed. Runtime behaviour is not implied.
- **Partial / risk:** A basic workflow exists, but a material business control or connected workflow is not demonstrated.
- **Not verified:** The relevant code, migration, or runtime behaviour was not available in this review. Do not treat this as proof that the feature is absent.
- **Not required:** Defer unless the bookshop's operating model needs it.

## Findings and gap register

| Area | Current evidence | Assessment | Priority |
|---|---|---|---|
| Product catalogue, barcode, ISBN, SKU | Product listing searches name, SKU, barcode and ISBN; product setup and scanner-first flow are documented. | Implemented — source confirmed. Need duplicate checks, product lifecycle and barcode hardware test. | Medium |
| Book-specific catalogue | ISBN and brand/category exist. Authors, editions, series, language, binding, publication date and book-specific metadata were not confirmed in reviewed files. | Partial / not verified. Decide which book attributes Dibonke actually needs. | Medium |
| Units and pack conversion | Product and purchase screens support stock unit, purchase unit and conversion factor. | Implemented for one purchase unit per product. Multiple packaging levels and fractional units not demonstrated. | Medium |
| Price lists and quantity tiers | Retail/wholesale price lists and product quantity prices are used in POS/order pricing. | Implemented — source confirmed. Verify price precedence and edge cases in live tests. | High |
| Supplier master | Supplier records include contact, tax PIN, credit limit and balance. | Implemented — source confirmed. Supplier statement and document completeness need verification. | Medium |
| Purchase orders and receiving | Purchase orders have Draft, Approved, Partially Received, Fully Received and Cancelled states; receiving is separate from the direct purchase register. | Implemented — source confirmed. Verify partial receipt, over-receipt, supplier invoice matching and duplicate prevention. | High |
| Direct purchases | Direct Purchase Register immediately increases stock and records supplier balances. | Implemented, but it creates a second purchasing route alongside PO/receiving. Define when each route is allowed to prevent duplicate recording. | High |
| Supplier invoice register and allocation | Supplier payments reduce `suppliers.balance`; reviewed payment form selects a supplier, not a supplier invoice. | Partial / material gap. Allocate payments to one or more supplier bills and support part-payments, credit notes and supplier ageing. | Critical |
| Customer credit and orders | Customer sales orders support draft, validation, pending invoice and confirmation; confirmation creates invoice and stock movement. | Implemented — source confirmed. Verify cancellation, amendment and partial fulfilment rules. | High |
| Customer receipts and invoice allocation | Customer payment store inserts a payment with `sale_id = NULL` and reduces `customers.balance`; it does not update a selected invoice balance. Aged Receivables reads `sales.balance`. | Partial / material gap. Build invoice allocation, unallocated credits, overpayment handling and customer statement reconciliation. | Critical |
| POS | POS validates stock and price list, creates sale and sale items, deducts stock and creates stock movements. | Implemented — source confirmed. Verify cash change, failed M-Pesa, receipt reprint, voids and concurrent cashier tests. | High |
| Returns and refunds | Financial control and cashier-shift code reference `sales_return_payments`; the specific return workflow was not inspected in this review. | Not verified. Confirm return authorisation, item condition, restocking, refund methods, credit notes and accounting reversal. | Critical |
| Inventory balances | Inventory overview shows stock, reorder level and movement links; stock adjustments lock the product row and record before/after quantities and reason. | Implemented — source confirmed. Need cycle counts, approvals and discrepancy workflow review. | High |
| Inventory valuation | Overview calculates stock cost as `stock_quantity * buying_price`. | Partial / risk. Confirm costing policy (weighted average or FIFO), landed costs, purchase price changes and valuation journal integration. | Critical |
| Stock transfers / locations | No multi-location transfer workflow was confirmed in reviewed files. | Not verified. Single-store operation may defer; required before multiple branches or separate stockrooms. | Low / Medium |
| Expenses | Expense recording and categories exist; finance screens summarise expense outflows. | Implemented — source confirmed. Verify approvals, attachments, recurring expenses and accounting category mapping. | High |
| Cashier shifts | Open/close shift, opening float, cash-in/out, expected cash, counted cash and variance are present in reviewed code. | Implemented — source confirmed. Need manager review/approval of variances and robust reconciliation tests. | High |
| Double-entry accounting | Chart of accounts, journals, general ledger and trial balance exist. Automatic posting requires configuration and account mappings. | Partial / risk. Verify opening balances, period controls, posting coverage, reversals, tax accounts and financial statements. | Critical |
| Finance overview | Overview totals sales, collections, supplier payments, expenses and net cash movement. | Partial. This is a management cash summary, not a formal Profit & Loss, Balance Sheet or Cash Flow Statement. | High |
| Aged receivables | Ageing is calculated from invoice date and each sale's balance. | Partial due to customer payment allocation gap. | Critical |
| Aged payables | No supplier invoice ageing workflow was confirmed. | Not verified / likely gap. Needed with supplier bill allocation. | High |
| M-Pesa and reconciliation | M-Pesa STK and transaction records are referenced by payment workflows. | Partial / verify callback idempotency, unmatched payments, reversals, timeouts, duplicate callbacks and settlement reconciliation. | Critical |
| eTIMS / tax compliance | Not established by the files reviewed in this assessment. | Not verified. Confirm actual KRA integration status, tax invoice requirements and certification before representing as live compliant. | Critical |
| Roles and permissions | Permission checks query role_permissions; view access can fall back to hard-coded roleModules(). Administrator/superuser bypasses checks. | Partial / design risk. Establish one permission source and test view/create/edit/delete on every route and POST endpoint. | Critical |
| Audit trail | Shared audit writer records before/after data where called; failures are logged but do not stop the transaction. | Partial. Verify coverage of edits, deletes, approvals, price changes, stock changes, refunds and permission changes. | High |
| Reports and exports | Report tools and multiple finance/customer/payment reports exist. | Partial. Need reconciled reports, consistent filters, CSV/Excel/PDF validation and margin/stock reports. | Medium |
| Backups and recovery | Deployment documentation mentions backup/restore, but operational recovery was not tested. | Not verified. Require scheduled backups and a tested restore procedure. | Critical |
| Multi-branch | Not established in reviewed files. | Not required for first release unless the business already operates multiple locations. Design for later rather than adding complexity now. | Low |
| HR, deliveries and fleet | Sidebar and user manual reference HR self-service, deliveries and fleet. | Outside core bookshop flow; validate only if Dibonke will actively use these modules. | Low |

## Priority remediation plan

### Phase 1 — Financial integrity (must resolve before relying on balances)
1. Introduce supplier bills / purchase invoice records and allocate supplier payments to one or multiple bills.
2. Introduce customer payment allocation to invoices, including partial payments and unapplied customer credits.
3. Reconcile customer statements, invoice balances, aged receivables and customer control-account journals from the same allocation ledger.
4. Reconcile supplier statements, bill balances, aged payables and supplier control-account journals.
5. Review all transaction-to-journal mappings and produce formal Profit & Loss, Balance Sheet and Cash Flow reports.
6. Verify sales returns, refunds, M-Pesa callback handling and eTIMS status.

### Phase 2 — Inventory integrity
1. Agree the stock costing method and implement a defensible valuation ledger.
2. Separate stock quantities by their units; remove or relabel the cross-unit “total units” metric.
3. Add controlled cycle counts and discrepancy approvals.
4. Confirm purchase receipt, sale, return, damage and adjustment movements reconcile to the product balance and valuation.

### Phase 3 — Operational controls
1. Consolidate permissions into one role/permission model.
2. Add manager review for cashier shift variances and sensitive corrections.
3. Complete audit-event coverage for all material business actions.
4. Confirm supplier and customer document/statement workflows.
5. Validate reporting and backup restoration.

### Phase 4 — Bookshop-specific enhancements
1. Decide whether authors, editions, series and other bibliographic fields are needed.
2. Add stock transfers only if separate locations or branches are part of the operating model.
3. Consider promotions, purchase planning, reorder suggestions and supplier lead times after core integrity is stable.

## End-to-end acceptance tests

These should be run on a safe test database with sample records before production use.

1. **Purchase to payment:** Create PO → approve → receive part → receive balance → enter supplier bill → pay part → verify bill balance, supplier statement, stock quantity, stock valuation and journals.
2. **Walk-in sale:** Open cashier shift → scan multiple products → complete cash sale with change → print receipt → close shift → reconcile expected and counted cash.
3. **Customer credit:** Create customer order → validate → invoice → allocate a partial payment → allocate final payment → verify invoice status, customer statement, aged receivables and journals.
4. **Return:** Return one item from a completed sale → approve → restock or quarantine → issue refund/store credit → verify customer balance, stock movement and reversal journal.
5. **M-Pesa:** Initiate STK → confirm callback → handle duplicate callback → reconcile transaction to sale/invoice → verify receipt and journals.
6. **Stock count:** Count selected products → record variances with reasons → approve → verify stock ledger and valuation.
7. **Permissions:** Test each role against each module and action, including direct URL access and POST requests.
8. **Period close:** Reconcile cash, bank and M-Pesa → review receivables/payables → verify trial balance → generate formal financial statements → lock the period.

## Important limitations of this assessment

- The review inspected selected files on the feature branch, not every file in the repository.
- The repository README states that a verified complete fresh-install base schema is not currently included. The live database schema and imported migrations therefore need to be inspected before making final claims about table-level completeness.
- No local XAMPP session, live database queries, PHP runtime tests, physical scanner, receipt printer, M-Pesa callback or eTIMS transmission was exercised.
- “Not verified” means evidence was not established in this review; it does not mean the feature definitely does not exist.

**Recommendation:** Do not add more modules yet. Resolve invoice allocation and financial integrity first, then inventory valuation and permission consistency. Those improvements will make the existing modules behave like one coherent business system rather than a collection of separate screens.

---

## Closure reassessment — 25 November 2026

This reassessment supersedes the original status statements above where later code changes have closed the cited gap. It remains a source review, not a production acceptance test.

### Gaps closed in source since the original assessment

| Area | Current source status | Remaining verification |
|---|---|---|
| Customer receipt allocation | FIFO allocation across completed invoices, partial allocation and excess customer-credit ledger entries are implemented for manual receipts and supported M-PESA callbacks. | Test all cases against the live MariaDB schema and reconcile balances. |
| Supplier payment allocation | Shared FIFO allocator distributes payments across direct purchases and goods receipts; supplier summary is checked against document balances before posting. | Test partial/multiple GRNs, mixed historical payment records and supplier statements. |
| Aged receivables | Reads invoice-level balances after allocation. | Verify statements, credit notes and allocation ageing. |
| Aged payables | Reports outstanding direct purchases and goods receipts using payment allocations. | Historical unallocated payments and supplier due dates require reconciliation/policy. |
| Store locations | Main Store and selling-store stock issue/return, POS attribution and store-level stock ledger support are implemented. | Physical issue/return and daily cash/M-PESA reconciliation tests. |
| Customer statements | Uses actual payment allocations for receivable credits and displays wallet credit separately. | Verify payment spanning invoices and legacy receipt history. |
| M-PESA receipt tracking | STK/C2B callbacks store invoice-applied and excess amounts explicitly; unmatched C2B allocation and customer credit are supported. | Apply the new migration, then test real Daraja sandbox callbacks and statement reconciliation. |
| Finance summaries / cashbook | Separates customer receipts, walk-in POS collections, unmatched C2B and walk-in M-PESA excess to reduce omission and double counting. | Compare each report to Daraja statements, receipt rows and posted journals. |
| User deletion safety | Correct ownership columns are checked; linked business/audit records prevent deletion and favour deactivation. | Verify with test accounts linked to each record family. |

### Remaining functional / control gaps

1. **Mixed tender at POS is not implemented.** A sale still accepts one payment method; cash + M-PESA/bank combinations are not stored as individual tender lines. Cashier shift and store-day reconciliation therefore cannot calculate mixed tender channels directly.
2. **Customer wallet credit application / refund is not a general workflow.** Overpayments are recorded as customer credit and shown on the customer account. They are not yet applied to a later invoice through a dedicated credit-application register, and they cannot be refunded directly from the wallet. Approved sales returns can offset an existing receivable and settle a refund payable, but this is not the same as a general wallet workflow.
3. **M-PESA operational recovery is incomplete.** Callback idempotency, duplicate receipt protection, pending-payment close protection and unmatched C2B allocation exist. Automated status polling, a controlled retry/cancel flow, reversal ingestion and full settlement statement import/reconciliation are not demonstrated.
4. **Accounting configuration and coverage are not yet fully certified.** Automatic posting is configurable and depends on correct account mappings. A full transaction-by-transaction journal coverage audit, opening balance migration, period lock, and independent trial-balance reconciliation against source records remain outstanding.
5. **Formal financial statements are incomplete.** The Finance Overview and Financial Control are management/cash summaries, not a fully reconciled statutory Profit & Loss, Balance Sheet and Cash Flow pack.
6. **Inventory valuation is a moving weighted-average estimate held in the product buying price, not a separately auditable cost-layer ledger.** Purchase receipts update the weighted average; valuation should be reconciled against historical purchase, return and stock adjustment data before it is relied upon for formal accounts.
7. **Return/refund controls need runtime verification.** Return request, approval, restocking, receivable offset, refund payable and payment settlement exist in source. Store credit, refund location attribution and the full cashier/store settlement effect still need explicit handling and testing.
8. **eTIMS is not a live KRA integration.** The current module prepares internal invoice snapshots only; it does not submit certified KRA invoices or process KRA responses.
9. **Backup restoration and deployment recovery have not been exercised.** Documentation exists, but a restore drill on a separate database/server is required.
10. **The repository does not contain a verified complete fresh-install base schema.** The deployment database and the full ordered migration set must be validated against the actual installed schema.

### Automated source checks

GitHub Actions run 45499542517 completed successfully for the latest reassessment commit 6feb208060f64fc8880bc530db09d93ec266a61a: all PHP files passed syntax lint on PHP 8.0 and PHP 8.2, and the existing product helper tests passed. This does not exercise database transactions, page rendering, printer hardware, M-PESA callbacks or eTIMS.

**Release position:** source-level integration is materially improved, but Dibonke should not be labelled gap-free or production-certified until the remaining functional items are either implemented or explicitly accepted as out of scope, all required migrations are applied, and the end-to-end acceptance suite is passed against a backed-up copy of the actual database.

---

## Coordinated closure implementation update — 25 November 2026

This update supersedes the mixed-tender, customer-wallet, financial-statement and period-control gaps listed in the earlier reassessment. The changes are committed to `feature/dibonke-product-catalog`; they are not yet a production acceptance certificate.

### Implemented in source

| Area | New implementation | Required migration / verification |
|---|---|---|
| Mixed POS tender | POS accepts cash received, bank/transfer and an M-PESA STK amount in one sale. Cash change is calculated after other tender channels. Confirmed tender lines are stored separately; STK remains pending until callback. | Apply `dibonke_sale_payment_lines.sql`; test cash-only, bank-only, mixed cash/bank, cash/STK and failed STK. |
| Receipt and cash controls | Receipt shows tender breakdown and pending/failed STK state. Cash drawer logic uses confirmed cash tender. Cashier shift, Finance, Cashbook and Store Operations read confirmed tender lines. | Verify receipt print and cash drawer on the actual printer; reconcile a full cashier shift. |
| Customer wallet | Customer Account supports applying credit to an outstanding invoice and recording refunds through Cash, Bank or M-PESA. Debit entries consume the original receipt credit; application/refund registers, audit events and accounting journals are created. | Apply `dibonke_customer_credit_workflows.sql`; test partial/full application, split credit sources, cash refund and bank/M-PESA refund. |
| M-PESA status recovery | Added Daraja STK status query action for pending requests. Failed query results mark the pending tender failed; a success response remains pending until the callback provides the actual receipt number. | Test only with Daraja sandbox. Status query cannot itself replace the receipt callback or reconcile a bank statement. |
| Financial statements | Added journal-based Profit & Loss and Balance Sheet reports, plus a cash/bank movement view using mapped cash accounts. | Review account mappings and opening balances; reconcile the Balance Sheet difference and report totals against source registers. |
| Accounting period control | Added period lock register. Automatic and manual journal posting now reject dates inside a locked period. | Apply `dibonke_accounting_period_locks.sql`; test backdated and current-period posting before/after locking. |
| Inventory valuation | Added dated valuation snapshots by Main Store and selling store, using current weighted-average buying price. Snapshot history and detail can be reviewed/exported. | Apply `dibonke_inventory_valuation_snapshots.sql`; reconcile snapshot quantities and value to a physical count. |

### Remaining gaps and external verification

1. **Inventory costing is not yet a full cost-layer ledger.** Snapshots preserve the weighted-average cost at a point in time, but do not reconstruct FIFO/LIFO layers or historical unit costs for every movement. A cost-ledger project would still be needed for a fully auditable inventory cost history.
2. **M-PESA recovery is partial.** STK query is available, but controlled re-initiation, reversal callback ingestion, automated settlement statement import and full reconciliation against Daraja statements are not implemented.
3. **Cash flow is a management cash movement view.** Profit & Loss and Balance Sheet are journal-based, but the cash movement view is not a fully classified indirect cash-flow statement. Account classifications and opening balances still need accountant review.
4. **Opening balances are entered through balanced journals, not a dedicated migration wizard.** Validate the opening trial balance before locking the first period.
5. **Sales return/refund store attribution and the complete impact on a selling-store day remain a runtime control test.** Wallet refunds now feed cashbook and cashier cash-out summaries.
6. **eTIMS remains non-live.** No certified KRA transmission, response processing or production certification is implemented.
7. **Backup restoration and fresh-install deployment remain unverified.** The repository still lacks a verified complete fresh-install base schema; use the actual installed database and ordered migration set.
8. **Runtime acceptance is still required.** Local MariaDB, browser, scanner, receipt printer, QZ Tray and Daraja sandbox tests have not been run by this source-only pass.

### Updated release position

The previous mixed-tender and customer-wallet gaps are now implemented in source, and basic journal-based financial statements, period locks and valuation snapshots have been added. Dibonke is closer to operational completion, but should not be called gap-free or production-certified until the new migrations are applied and the end-to-end acceptance suite passes against a backed-up copy of the actual database.
