# Dibonke Bookshop Backup and Recovery Runbook

This runbook is for the local XAMPP deployment. Backups must be stored outside the web root and copied to a second device or secure cloud location. A backup kept only on the same computer is not sufficient protection.

## What must be backed up

1. The complete MariaDB database: `dibonke_bookshop_db`.
2. The application uploads directory: `C:\xampp\htdocs\dibonke_bookshop\uploads` (product images and any other uploaded business documents).
3. Local configuration that is not committed to Git, including any private environment or secret-key file introduced for production integrations.
4. The current application branch and commit reference.

Do not place database dumps, credentials or secret files in a publicly accessible folder or commit them to Git.

## Create a database backup with XAMPP

1. Start MySQL in the XAMPP Control Panel.
2. Open Command Prompt.
3. Run the following command, changing the date in the filename as needed:

```bat
C:\xampp\mysql\bin\mysqldump.exe -u root dibonke_bookshop_db > D:\DibonkeBackups\dibonke_bookshop_YYYY-MM-DD.sql
```

If the MariaDB root account has a password, add `-p` after `-u root`; enter the password when prompted. Create the `D:\DibonkeBackups` folder first, or choose another backup location outside `htdocs`.

4. Confirm the SQL file exists and has a non-zero size.
5. Copy the `uploads` folder to the same dated backup location.
6. Copy the application commit reference into a small text file alongside the backup.

## Restore test

Do not test restoration over the live database.

1. Create a separate empty database, for example `dibonke_restore_test`.
2. Import the backup into that test database using phpMyAdmin or:

```bat
C:\xampp\mysql\bin\mysql.exe -u root dibonke_restore_test < D:\DibonkeBackups\dibonke_bookshop_YYYY-MM-DD.sql
```

3. Check that core tables exist: `users`, `products`, `sales`, `sale_items`, `customers`, `purchases`, `supplier_payments`, `journal_entries`, `journal_lines`, and the allocation tables.
4. Restore uploads to a separate test copy of the application and confirm that product images load.
5. Sign in to the test copy and verify product search, POS, customer orders, payments, inventory and reports.
6. Record the date, backup filename, database size and restore-test result.

## Minimum operating routine

- Daily: database dump and uploads copy at close of business.
- Weekly: copy the latest backup to a separate device or secure offsite location.
- Monthly: restore the latest backup into a separate test database and verify that the application can read it.
- Before any migration or major update: take a fresh database and uploads backup.

## Important limitations

This is a documented manual procedure, not an automated backup scheduler. No backup is considered verified until a restore test has succeeded. Do not rely on GitHub as a backup for customer, sales, payment, stock or accounting data.
