-- Customer wallet credit application and refund register.
-- Apply after dibonke_customer_credit_ledger.sql and the automatic accounting migrations.
CREATE TABLE IF NOT EXISTS customer_credit_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    credit_entry_id BIGINT UNSIGNED NOT NULL,
    debit_entry_id BIGINT UNSIGNED NOT NULL,
    sale_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_credit_application_customer (customer_id,created_at),
    KEY idx_credit_application_sale (sale_id),
    KEY idx_credit_application_source (credit_entry_id),
    KEY idx_credit_application_debit (debit_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_credit_refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    credit_entry_id BIGINT UNSIGNED NOT NULL,
    debit_entry_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    transaction_reference VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_credit_refund_customer (customer_id,created_at),
    KEY idx_credit_refund_source (credit_entry_id),
    KEY idx_credit_refund_debit (debit_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
