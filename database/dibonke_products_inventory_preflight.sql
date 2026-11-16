-- Dibonke Bookshop: Products and Inventory schema preflight
-- READ ONLY: this script does not CREATE, ALTER, UPDATE, DELETE or DROP data.
-- Run it while the target Dibonke database is selected in phpMyAdmin.
-- It reports the current module contract; it is not a base-schema installer.

SELECT DATABASE() AS selected_database;

-- 1) Required tables used by the catalogue, POS, purchasing and stock ledger.
SELECT expected.table_name,
       CASE WHEN t.TABLE_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS table_status
FROM (
    SELECT 'products' AS table_name UNION ALL
    SELECT 'categories' UNION ALL
    SELECT 'brands' UNION ALL
    SELECT 'suppliers' UNION ALL
    SELECT 'users' UNION ALL
    SELECT 'price_lists' UNION ALL
    SELECT 'product_prices' UNION ALL
    SELECT 'stock_movements' UNION ALL
    SELECT 'customers' UNION ALL
    SELECT 'sales' UNION ALL
    SELECT 'sale_items' UNION ALL
    SELECT 'purchases' UNION ALL
    SELECT 'purchase_items' UNION ALL
    SELECT 'settings' UNION ALL
    SELECT 'activity_logs'
) AS expected
LEFT JOIN information_schema.TABLES t
       ON t.TABLE_SCHEMA = DATABASE()
      AND t.TABLE_NAME = expected.table_name
ORDER BY expected.table_name;

-- 2) Required columns. Missing columns must be reconciled before using the
--    affected module; do not apply ad-hoc ALTER statements to a live database.
SELECT expected.table_name,
       expected.column_name,
       CASE WHEN c.COLUMN_NAME IS NULL THEN 'MISSING' ELSE 'PRESENT' END AS column_status,
       c.COLUMN_TYPE,
       c.IS_NULLABLE,
       c.COLUMN_DEFAULT
FROM (
    SELECT 'products' AS table_name, 'id' AS column_name UNION ALL
    SELECT 'products', 'product_name' UNION ALL
    SELECT 'products', 'product_type' UNION ALL
    SELECT 'products', 'isbn' UNION ALL
    SELECT 'products', 'category' UNION ALL
    SELECT 'products', 'category_id' UNION ALL
    SELECT 'products', 'brand_id' UNION ALL
    SELECT 'products', 'supplier_id' UNION ALL
    SELECT 'products', 'sku' UNION ALL
    SELECT 'products', 'barcode' UNION ALL
    SELECT 'products', 'unit' UNION ALL
    SELECT 'products', 'buying_price' UNION ALL
    SELECT 'products', 'selling_price' UNION ALL
    SELECT 'products', 'wholesale_price' UNION ALL
    SELECT 'products', 'stock_quantity' UNION ALL
    SELECT 'products', 'reorder_level' UNION ALL
    SELECT 'products', 'status' UNION ALL
    SELECT 'categories', 'id' UNION ALL
    SELECT 'categories', 'category_name' UNION ALL
    SELECT 'categories', 'description' UNION ALL
    SELECT 'categories', 'status' UNION ALL
    SELECT 'brands', 'id' UNION ALL
    SELECT 'brands', 'brand_name' UNION ALL
    SELECT 'brands', 'status' UNION ALL
    SELECT 'suppliers', 'id' UNION ALL
    SELECT 'suppliers', 'supplier_name' UNION ALL
    SELECT 'suppliers', 'status' UNION ALL
    SELECT 'users', 'id' UNION ALL
    SELECT 'users', 'full_name' UNION ALL
    SELECT 'price_lists', 'id' UNION ALL
    SELECT 'price_lists', 'code' UNION ALL
    SELECT 'price_lists', 'status' UNION ALL
    SELECT 'product_prices', 'price_list_id' UNION ALL
    SELECT 'product_prices', 'product_id' UNION ALL
    SELECT 'product_prices', 'min_quantity' UNION ALL
    SELECT 'product_prices', 'price' UNION ALL
    SELECT 'stock_movements', 'product_id' UNION ALL
    SELECT 'stock_movements', 'user_id' UNION ALL
    SELECT 'stock_movements', 'movement_type' UNION ALL
    SELECT 'stock_movements', 'quantity' UNION ALL
    SELECT 'stock_movements', 'stock_before' UNION ALL
    SELECT 'stock_movements', 'stock_after' UNION ALL
    SELECT 'stock_movements', 'reference_type' UNION ALL
    SELECT 'stock_movements', 'reference_id' UNION ALL
    SELECT 'stock_movements', 'notes' UNION ALL
    SELECT 'stock_movements', 'movement_date' UNION ALL
    SELECT 'sales', 'price_list_id' UNION ALL
    SELECT 'sale_items', 'product_id' UNION ALL
    SELECT 'sale_items', 'quantity' UNION ALL
    SELECT 'purchases', 'supplier_id' UNION ALL
    SELECT 'purchase_items', 'product_id' UNION ALL
    SELECT 'purchase_items', 'quantity' UNION ALL
    SELECT 'purchase_items', 'buying_price' UNION ALL
    SELECT 'settings', 'setting_key' UNION ALL
    SELECT 'settings', 'setting_value' UNION ALL
    SELECT 'activity_logs', 'user_id'
) AS expected
LEFT JOIN information_schema.COLUMNS c
       ON c.TABLE_SCHEMA = DATABASE()
      AND c.TABLE_NAME = expected.table_name
      AND c.COLUMN_NAME = expected.column_name
ORDER BY expected.table_name, expected.column_name;

-- 3) Inspect quantity precision. Current PHP purchase/POS handlers cast
--    quantities to integers, so the current workflow is whole-unit only.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
       (TABLE_NAME = 'products' AND COLUMN_NAME IN ('stock_quantity', 'reorder_level'))
    OR (TABLE_NAME = 'stock_movements' AND COLUMN_NAME IN ('quantity', 'stock_before', 'stock_after'))
    OR (TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'quantity')
    OR (TABLE_NAME = 'purchase_items' AND COLUMN_NAME = 'quantity')
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 4) Inspect indexes relevant to ISBN uniqueness and quantity price tiers.
SELECT TABLE_NAME,
       INDEX_NAME,
       NON_UNIQUE,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('products', 'product_prices', 'price_lists')
GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE
ORDER BY TABLE_NAME, INDEX_NAME;

-- 5) Inspect catalogue and stock foreign keys without changing them.
SELECT TABLE_NAME,
       COLUMN_NAME,
       CONSTRAINT_NAME,
       REFERENCED_TABLE_NAME,
       REFERENCED_COLUMN_NAME,
       DELETE_RULE,
       UPDATE_RULE
FROM information_schema.KEY_COLUMN_USAGE k
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
       ON rc.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
      AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME
      AND rc.TABLE_NAME = k.TABLE_NAME
WHERE k.TABLE_SCHEMA = DATABASE()
  AND k.REFERENCED_TABLE_NAME IS NOT NULL
  AND k.TABLE_NAME IN (
      'products', 'product_prices', 'stock_movements',
      'sale_items', 'purchase_items'
  )
ORDER BY TABLE_NAME, CONSTRAINT_NAME;
