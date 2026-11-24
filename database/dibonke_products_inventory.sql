-- Dibonke Bookshop product catalogue extension
-- Safe additive migration: adds optional book metadata without deleting or rewriting existing products.
-- Run against dibonke_bookshop_db after the base products table exists.

SET @has_product_type := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='product_type'
);
SET @sql_product_type := IF(
  @has_product_type=0,
  "ALTER TABLE products ADD COLUMN product_type VARCHAR(40) NOT NULL DEFAULT 'General' AFTER product_name",
  "SELECT 1"
);
PREPARE stmt_product_type FROM @sql_product_type;
EXECUTE stmt_product_type;
DEALLOCATE PREPARE stmt_product_type;

SET @has_isbn := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='isbn'
);
SET @sql_isbn := IF(
  @has_isbn=0,
  "ALTER TABLE products ADD COLUMN isbn VARCHAR(20) NULL AFTER product_type",
  "SELECT 1"
);
PREPARE stmt_isbn FROM @sql_isbn;
EXECUTE stmt_isbn;
DEALLOCATE PREPARE stmt_isbn;

SET @has_isbn_index := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND INDEX_NAME='uq_products_isbn'
);
SET @sql_isbn_index := IF(
  @has_isbn_index=0,
  "ALTER TABLE products ADD UNIQUE INDEX uq_products_isbn (isbn)",
  "SELECT 1"
);
PREPARE stmt_isbn_index FROM @sql_isbn_index;
EXECUTE stmt_isbn_index;
DEALLOCATE PREPARE stmt_isbn_index;
