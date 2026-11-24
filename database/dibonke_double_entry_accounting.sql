CREATE TABLE IF NOT EXISTS journal_entries (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 journal_number VARCHAR(32) NOT NULL,
 journal_date DATE NOT NULL,
 description VARCHAR(255) NOT NULL,
 reference VARCHAR(100) NULL,
 status ENUM('Draft','Posted','Reversed') NOT NULL DEFAULT 'Draft',
 total_debit DECIMAL(14,2) NOT NULL DEFAULT 0,
 total_credit DECIMAL(14,2) NOT NULL DEFAULT 0,
 created_by INT UNSIGNED NULL,
 posted_by INT UNSIGNED NULL,
 posted_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_journal_number(journal_number), KEY idx_journal_date_status(journal_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS journal_lines (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 journal_entry_id BIGINT UNSIGNED NOT NULL,
 account_id INT UNSIGNED NOT NULL,
 line_description VARCHAR(255) NULL,
 debit DECIMAL(14,2) NOT NULL DEFAULT 0,
 credit DECIMAL(14,2) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_journal_line_entry(journal_entry_id), KEY idx_journal_line_account(account_id),
 CONSTRAINT fk_journal_line_entry FOREIGN KEY(journal_entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
 CONSTRAINT fk_journal_line_account FOREIGN KEY(account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'journals',1,1,1,0 FROM roles WHERE role_code IN ('ADMIN','MANAGER')
ON DUPLICATE KEY UPDATE can_view=1,can_create=1,can_edit=1;
INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'general_ledger',1,0,0,0 FROM roles WHERE role_code IN ('ADMIN','MANAGER')
ON DUPLICATE KEY UPDATE can_view=1;
INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'trial_balance',1,0,0,0 FROM roles WHERE role_code IN ('ADMIN','MANAGER')
ON DUPLICATE KEY UPDATE can_view=1;
