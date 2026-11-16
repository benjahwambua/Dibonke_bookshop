# Database setup status

The initial Dibonke repository contains the SQL scripts carried over from the Stalk & Stable codebase:

- `price_lists.sql`
- `supply_chain_mpesa.sql`

These are supporting/extension scripts, not a complete standalone database installation. The source repository did not include a full base schema dump in its tracked files.

**Do not assume the application is ready for a fresh database import yet.** Before first local installation, we will:
1. Design a clean Dibonke Bookshop base schema for general retail products, books, stationery and other stock items.
2. Reconcile every module's table and column expectations against that schema.
3. Convert the remaining alcohol-distribution terminology and workflows.
4. Test installation and transactions on a fresh local database.

Use a separate local development database named `dibonke_bookshop_db`. Do not reuse the Stalk & Stable production database.
