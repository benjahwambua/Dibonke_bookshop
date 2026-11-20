-- Dibonke payment allocation foundation
-- Apply once to the existing Dibonke database before deploying the matching PHP changes.
-- Existing customer/supplier payment rows remain intact; new allocations are recorded separately.

CREATE TABLE IF NOT EXISTS customer_payment_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    sale_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_payment_sale (payment_id, sale_id),
    KEY idx_customer_alloc_sale (sale_id),
    CONSTRAINT chk_customer_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_payment_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_payment_purchase (payment_id, purchase_id),
    KEY idx_supplier_alloc_purchase (purchase_id),
    CONSTRAINT chk_supplier_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link existing user accounts to configured roles where names/codes match.
UPDATE users u
JOIN roles r
  ON UPPER(REPLACE(REPLACE(TRIM(r.role_name), ' ', ''), '-', '')) =
     UPPER(REPLACE(REPLACE(TRIM(u.role), ' ', ''), '-', ''))
SET u.role_id = r.id
WHERE u.role_id IS NULL;
