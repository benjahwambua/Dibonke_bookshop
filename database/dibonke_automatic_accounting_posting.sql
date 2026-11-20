-- Automatic double-entry posting for sales, purchases, goods receipts and expenses.
-- Apply after dibonke_accounting_hr.sql, dibonke_double_entry_accounting.sql,
-- and the expense category setup migrations.

INSERT IGNORE INTO accounts(account_code,account_name,account_type,is_system,description) VALUES
('1040','Other Payment Clearing','Asset',1,'Clearing account for cheque, mixed and other payment methods'),
('1300','Input VAT Recoverable','Asset',1,'Recoverable input VAT on supplier purchases'),
('2050','Unallocated M-Pesa Receipts','Liability',1,'Customer funds received but not yet allocated to an invoice');

SET @has_source_type := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='journal_entries' AND COLUMN_NAME='source_type');
SET @ddl_source_type := IF(@has_source_type=0,'ALTER TABLE journal_entries ADD COLUMN source_type VARCHAR(40) NULL AFTER reference','SELECT 1');
PREPARE stmt_source_type FROM @ddl_source_type; EXECUTE stmt_source_type; DEALLOCATE PREPARE stmt_source_type;

SET @has_source_id := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='journal_entries' AND COLUMN_NAME='source_id');
SET @ddl_source_id := IF(@has_source_id=0,'ALTER TABLE journal_entries ADD COLUMN source_id BIGINT UNSIGNED NULL AFTER source_type','SELECT 1');
PREPARE stmt_source_id FROM @ddl_source_id; EXECUTE stmt_source_id; DEALLOCATE PREPARE stmt_source_id;

SET @has_source_unique := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='journal_entries' AND INDEX_NAME='uq_journal_source');
SET @ddl_source_unique := IF(@has_source_unique=0,'ALTER TABLE journal_entries ADD UNIQUE KEY uq_journal_source(source_type,source_id)','SELECT 1');
PREPARE stmt_source_unique FROM @ddl_source_unique; EXECUTE stmt_source_unique; DEALLOCATE PREPARE stmt_source_unique;

CREATE TABLE IF NOT EXISTS accounting_mappings (
 mapping_key VARCHAR(60) NOT NULL,
 account_id INT UNSIGNED NOT NULL,
 updated_by INT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(mapping_key),
 KEY idx_accounting_mapping_account(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_category_accounting (
 category_id INT UNSIGNED NOT NULL,
 account_id INT UNSIGNED NOT NULL,
 updated_by INT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(category_id),
 KEY idx_expense_category_account(account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounting_mappings(mapping_key,account_id)
SELECT m.mapping_key,a.id
FROM (
 SELECT 'cash_on_hand' mapping_key,'1010' account_code UNION ALL
 SELECT 'mpesa_clearing','1020' UNION ALL
 SELECT 'bank_account','1030' UNION ALL
 SELECT 'other_clearing','1040' UNION ALL
 SELECT 'accounts_receivable','1100' UNION ALL
 SELECT 'inventory','1200' UNION ALL
 SELECT 'accounts_payable','2010' UNION ALL
 SELECT 'vat_payable','2020' UNION ALL
 SELECT 'sales_revenue','4000' UNION ALL
 SELECT 'cost_of_goods_sold','5000' UNION ALL
 SELECT 'input_vat','1300' UNION ALL
 SELECT 'unallocated_receipts','2050' UNION ALL
 SELECT 'default_expense','5100'
) m JOIN accounts a ON a.account_code=m.account_code;

INSERT IGNORE INTO expense_category_accounting(category_id,account_id)
SELECT ec.id,a.id FROM expense_categories ec
JOIN accounts a ON a.account_code='5100'
WHERE ec.status='Active';

INSERT INTO settings(setting_key,setting_value)
SELECT 'accounting_auto_posting_enabled','0'
WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key='accounting_auto_posting_enabled');

INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'accounting_mappings',1,1,1,0 FROM roles WHERE role_code IN ('ADMIN','MANAGER')
ON DUPLICATE KEY UPDATE can_view=1,can_create=1,can_edit=1;
