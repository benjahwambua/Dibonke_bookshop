-- Dibonke POS: per-tender payment lines for split/mixed tender sales.
-- Apply after the base sales schema and supply_chain_mpesa.sql.
CREATE TABLE IF NOT EXISTS sale_payment_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('Confirmed','Pending','Failed') NOT NULL DEFAULT 'Confirmed',
    transaction_reference VARCHAR(120) NULL,
    phone_number VARCHAR(30) NULL,
    checkout_request_id VARCHAR(100) NULL,
    mpesa_transaction_id BIGINT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    KEY idx_sale_payment_sale (sale_id,status),
    KEY idx_sale_payment_method_date (payment_method,status,created_at),
    UNIQUE KEY uq_sale_payment_checkout (checkout_request_id),
    KEY idx_sale_payment_mpesa_tx (mpesa_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill legacy sales as a single tender line using the recorded method and
-- paid amount. Rows already represented by a payment line are left unchanged.
INSERT INTO sale_payment_lines(sale_id,payment_method,amount,status,created_at,confirmed_at)
SELECT s.id, s.payment_method, s.paid_amount, 'Confirmed', s.sale_date, s.sale_date
FROM sales s
WHERE s.sale_status='Completed'
  AND s.paid_amount>0.009
  AND NOT EXISTS (SELECT 1 FROM sale_payment_lines spl WHERE spl.sale_id=s.id);
