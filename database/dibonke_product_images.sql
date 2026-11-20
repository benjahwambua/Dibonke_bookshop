-- Product image support: safe additive schema change.
SET @has_image_path := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='image_path'
);
SET @sql_image_path := IF(
  @has_image_path=0,
  "ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL AFTER icon",
  "SELECT 1"
);
PREPARE stmt_image_path FROM @sql_image_path;
EXECUTE stmt_image_path;
DEALLOCATE PREPARE stmt_image_path;
