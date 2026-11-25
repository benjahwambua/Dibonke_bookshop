# Dibonke Bookshop Management System

A PHP and MySQL business management application for **Dibonke Bookshop**. This repository starts from the reusable application architecture of [Stalk & Stable](https://github.com/benjahwambua/stalk_stable), maintained as a separate project so the original system remains unchanged.

## Initial application structure

- `admin/` – administrative pages
- `assets/` – shared stylesheets and front-end assets
- `auth/` – authentication endpoints
- `config/` – application and database configuration
- `database/` – SQL migrations and schema scripts
- `includes/` – shared authentication, layout and navigation
- `modules/` – inventory, sales, purchasing, suppliers, customers, payments, reporting and administration
- `public/` – public-facing authentication pages

## Planned business scope

The application is being adapted for a general bookshop and retail operation. It should support books, stationery and other sellable items, with flexible product categories, stock control, supplier purchasing, point-of-sale sales, customer accounts, expenses, reporting and payment integrations.

## Local development

1. Install XAMPP (PHP 8+ and MySQL/MariaDB).
2. Place this repository in `C:\xampp\htdocs\dibonke_bookshop`.
3. Create a MySQL database named `dibonke_bookshop_db`.
4. Review `config/db.php` and update credentials if your local setup differs.
5. Import the SQL scripts in `database/` in this order: `base_schema.sql`, `price_lists.sql`, `supply_chain_mpesa.sql` (details in `database/README.md`).
6. Create the first Super User: `php database/create_super_admin.php`.
7. Open `http://localhost/dibonke_bookshop/`.

> The user-facing pages under `modules/` and `includes/` now use Dibonke Bookshop branding, but this is still an architectural starting point. The legacy `admin/pages/products/` and `public/auth/` folders are unreferenced copies from Stalk & Stable and still contain its branding and old paths; they are not part of the working application. The next development stage is to validate each module on a fresh local database for bookshop and general retail use.

## Security notes

- Never commit live payment credentials, production passwords, or customer data.
- Configure payment credentials locally and use sandbox mode during development.
- Review database migrations before running them against any existing database.
