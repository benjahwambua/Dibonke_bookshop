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
| `supply_chain_mpesa.sql` | Purchase orders, goods receipts, supplier payments and M-PESA transaction storage | Requires base `users`, `suppliers` and `settings` tables |
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
- Completed purchases increase product stock and write `Purchase` movements.
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
