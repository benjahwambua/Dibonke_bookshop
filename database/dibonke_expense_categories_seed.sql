CREATE TABLE IF NOT EXISTS expense_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expense_category_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the standard Dibonke operating expense categories.
-- Safe to re-run: existing category names are preserved and not duplicated.
-- Requires the existing expense_categories(category_name, status) table.

INSERT INTO expense_categories (category_name, status)
SELECT 'Rent', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Rent'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Utilities', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Utilities'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Salaries & Wages', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Salaries & Wages'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Transport & Travel', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Transport & Travel'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Stationery & Office Supplies', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Stationery & Office Supplies'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Repairs & Maintenance', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Repairs & Maintenance'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Internet & Communication', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Internet & Communication'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Marketing & Advertising', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Marketing & Advertising'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Licences & Permits', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Licences & Permits'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Bank Charges', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Bank Charges'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Cleaning & Sanitation', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Cleaning & Sanitation'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Security', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Security'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Packaging & Shopping Bags', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Packaging & Shopping Bags'));

INSERT INTO expense_categories (category_name, status)
SELECT 'Delivery & Courier', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('Delivery & Courier'));

INSERT INTO expense_categories (category_name, status)
SELECT 'General & Miscellaneous', 'Active'
WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE LOWER(category_name)=LOWER('General & Miscellaneous'));
