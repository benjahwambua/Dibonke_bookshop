# Database setup

Use a separate local development database named `dibonke_bookshop_db`. Do not reuse the Stalk & Stable production database.

## Fresh install (new, empty database)

Import the scripts **in this order** (phpMyAdmin → `dibonke_bookshop_db` → Import, or the `mysql` CLI):

| # | File | What it does |
|---|------|--------------|
| 1 | `base_schema.sql` | Core tables used by the app: users, categories, brands, suppliers, products, customers, sales, sale_items, purchases, purchase_items, stock_movements, customer_payments, expense_categories, expenses, deliveries, delivery_items, activity_logs, settings. Also seeds the document-number prefixes (`DB-INV`, `DB-RCP`, …), company settings, starter product categories and expense categories. |
| 2 | `price_lists.sql` | Price lists and per-product tier prices; adds `price_list_id` to `customers` and `sales`; seeds the *Normal* and *Wholesale* lists. |
| 3 | `supply_chain_mpesa.sql` | Purchase orders, goods receipts (GRN), supplier payments, M-PESA transactions and M-PESA settings. |

Then create the first login (a fresh database has no users):

```
php database/create_super_admin.php
```

It prompts for a name, username and password (minimum 8 characters), and creates a Super User. It only runs from the command line.

All three SQL files use `IF NOT EXISTS` / `INSERT IGNORE`, so re-running them on a database that already has the tables is safe, but **they do not migrate existing data**.

## Existing database

If you already have a working local database (for example one copied from Stalk & Stable), do **not** run `base_schema.sql` over it. Back it up first, then compare its columns against `base_schema.sql` and apply only the differences.

## Status

The base schema was derived from the application code (every `INSERT`, `UPDATE` and `SELECT`) and statically checked for foreign-key ordering and name clashes. It has **not yet been imported into a live MySQL/MariaDB server**. Before relying on it:

1. Import the three scripts into a throwaway database and confirm there are no errors.
2. Run the core flows once: create product → purchase → sale (cash, credit, walk-in, M-PESA sandbox) → customer payment → supplier payment → delivery.
3. Convert any remaining alcohol-distribution terminology and workflows still present in the legacy `admin/` and `public/` folders (see the README).
