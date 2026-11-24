-- Customer credit ledger for M-PESA and other customer receipts exceeding open invoices.
-- Apply after dibonke_payment_allocations.sql and the automatic accounting migrations.
CREATE TABLE IF NOT EXISTS customer_credit_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    entry_type ENUM('Credit','Debit') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_customer_credit_customer (customer_id, created_at),
    KEY idx_customer_credit_payment (payment_id),
    CONSTRAINT chk_customer_credit_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO accounts(account_code,account_name,account_type,is_system,description)
VALUES ('2070','Customer Credit Balances','Liability',1,'Customer funds received in excess of outstanding invoices');

INSERT IGNORE INTO accounting_mappings(mapping_key,account_id)
SELECT 'customer_credit',id FROM accounts WHERE account_code='2070';
