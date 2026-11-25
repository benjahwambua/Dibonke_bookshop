-- Dibonke Bookshop: separate bibliographic metadata for books.
-- Safe additive migration. Back up the database before applying.
SET @has_author := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='author'
);
SET @sql_author := IF(@has_author=0,
  "ALTER TABLE products ADD COLUMN author VARCHAR(160) NULL AFTER isbn",
  "SELECT 1"
);
PREPARE stmt_author FROM @sql_author;
EXECUTE stmt_author;
DEALLOCATE PREPARE stmt_author;

SET @has_publisher := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='publisher'
);
SET @sql_publisher := IF(@has_publisher=0,
  "ALTER TABLE products ADD COLUMN publisher VARCHAR(160) NULL AFTER author",
  "SELECT 1"
);
PREPARE stmt_publisher FROM @sql_publisher;
EXECUTE stmt_publisher;
DEALLOCATE PREPARE stmt_publisher;
