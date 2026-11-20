-- Dibonke Bookshop: invoice details for goods receiving.
-- Safe additive migration. Run against dibonke_bookshop_db after supply_chain_mpesa.sql.

SET @has_invoice_date := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='goods_receipts' AND COLUMN_NAME='invoice_date'
);
SET @sql_invoice_date := IF(
  @has_invoice_date=0,
  "ALTER TABLE goods_receipts ADD COLUMN invoice_date DATE NULL AFTER supplier_invoice",
  "SELECT 1"
);
PREPARE stmt_invoice_date FROM @sql_invoice_date;
EXECUTE stmt_invoice_date;
DEALLOCATE PREPARE stmt_invoice_date;

SET @has_invoice_total := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='goods_receipts' AND COLUMN_NAME='invoice_total'
);
SET @sql_invoice_total := IF(
  @has_invoice_total=0,
  "ALTER TABLE goods_receipts ADD COLUMN invoice_total DECIMAL(14,2) NULL AFTER invoice_date",
  "SELECT 1"
);
PREPARE stmt_invoice_total FROM @sql_invoice_total;
EXECUTE stmt_invoice_total;
DEALLOCATE PREPARE stmt_invoice_total;

-- Purchasing/receiving access using the existing unified role matrix.
UPDATE role_permissions rp
JOIN roles r ON r.id=rp.role_id
SET rp.can_view=1, rp.can_create=1, rp.can_edit=1
WHERE r.role_code='MANAGER' AND rp.module_key='supply_chain';

UPDATE role_permissions rp
JOIN roles r ON r.id=rp.role_id
SET rp.can_view=1, rp.can_create=1
WHERE r.role_code='STOREKEEPER' AND rp.module_key='supply_chain';
