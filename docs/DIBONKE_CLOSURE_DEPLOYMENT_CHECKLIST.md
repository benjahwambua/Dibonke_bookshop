# Dibonke Bookshop — Closure Deployment Checklist

Branch: `feature/dibonke-product-catalog`

This checklist covers the additive database migrations introduced by the coordinated closure pass. It is not a substitute for backing up the live database or testing on a restored copy first.

## 1. Before pulling

1. Take a full phpMyAdmin export of `dibonke_bookshop_db`.
2. Save the export outside the XAMPP project directory.
3. Confirm there are no active stock counts or unclosed selling-store days.
4. Pull the feature branch:

```powershell
cd C:\xampp\htdocs\dibonke_bookshop
git pull --ff-only origin feature/dibonke-product-catalog
```

Do not merge this branch into `main` as part of this deployment step.

## 2. Confirm existing prerequisites

The following existing migrations should already have been applied in the order documented in `database/README.md`:

- Base bookshop schema and product / stock migrations.
- `supply_chain_mpesa.sql`.
- `dibonke_store_operations.sql`, if using multiple stores.
- Double-entry accounting migrations and `dibonke_payment_allocations.sql`.
- `dibonke_customer_credit_ledger.sql`, for wallet credit balances.

Do not re-import old migrations blindly into a live database; check their status first.

## 3. Apply the closure migrations

Apply these additive scripts in this order, after their prerequisites:

1. `database/dibonke_mpesa_allocation_amounts.sql` — after `supply_chain_mpesa.sql`. Adds per-receipt applied/unallocated amounts and safely backfills historical unmatched C2B receipts.
2. `database/dibonke_sale_payment_lines.sql` — after the sales schema, `supply_chain_mpesa.sql` and `dibonke_store_operations.sql`. Adds the POS tender ledger, backfills legacy completed sales and adds expected bank collections to store sessions.
3. `database/dibonke_customer_credit_workflows.sql` — after `dibonke_customer_credit_ledger.sql` and the payment-allocation/accounting migrations. Adds wallet application and refund registers.
4. `database/dibonke_accounting_period_locks.sql` — after the double-entry accounting tables exist.
5. `database/dibonke_inventory_valuation_snapshots.sql` — after the products inventory schema; store support is included when the store migrations are installed.

The scripts use additive/idempotent table or column creation where designed, but still take and verify a backup before importing them.

## 4. Verify schema additions

Confirm these exist before using the related screens:

- `mpesa_transactions.amount_applied` and `mpesa_transactions.amount_unallocated`.
- `sale_payment_lines`.
- `store_daily_sessions.expected_bank` when Store Operations is installed.
- `customer_credit_applications` and `customer_credit_refunds`.
- `accounting_period_locks`.
- `inventory_valuation_snapshots` and `inventory_valuation_snapshot_items`.

## 5. Run the acceptance tests

Use a restored test database first. The expanded scenarios are recorded in `docs/DIBONKE_FUNCTIONAL_GAP_ASSESSMENT.md`. At minimum, test:

- Cash-only, bank-only, cash+bank, cash+M-PESA and cash+bank+M-PESA POS sales.
- Successful, failed, queried and retried STK requests, including duplicate callbacks.
- C2B matched, unmatched, exact, partial and overpaid receipts.
- Customer wallet application and refund, including statement and journal effects.
- Cashier shift close and each selling-store day reconciliation.
- Finance Overview, Financial Control, Cashbook, Trial Balance, Profit & Loss and Balance Sheet.
- Period locking and rejection of backdated posting.
- Main Store and selling-store valuation snapshots.

Compare totals across source receipts, sales, customer statements, stock movements, cashbook, journals and Daraja statements.

## 6. Items that still require external or deeper verification

- Daraja reversal callback ingestion and automated settlement statement import are not implemented.
- eTIMS is not a live certified KRA integration.
- Inventory valuation snapshots use weighted-average buying price; they are not a FIFO/LIFO cost-layer ledger.
- The cash/bank movement report is a management view, not a certified indirect cash-flow statement.
- Backup restoration and a fresh-install deployment have not been exercised.
- The local XAMPP/MariaDB database, browser, physical printer/scanner and Daraja sandbox were not available to this source review.

GitHub Actions has passed PHP syntax lint and the existing product helper tests on PHP 8.0 and 8.2 for the latest code commit in this closure pass. Database and end-to-end acceptance testing must still be completed locally.
