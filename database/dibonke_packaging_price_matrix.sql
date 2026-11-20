-- Dibonke packaging price matrix: retail and wholesale prices for base and purchase units.
-- Safe additive migration. Existing product prices and stock are preserved.
SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='products' AND COLUMN_NAME='bulk_wholesale_price')=0,
"ALTER TABLE products ADD COLUMN bulk_wholesale_price DECIMAL(12,2) NULL AFTER bulk_selling_price","SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
