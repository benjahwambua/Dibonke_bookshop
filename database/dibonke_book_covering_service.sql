-- Current book-covering service rules.
-- Apply after dibonke_book_covering.sql and the base products/sales schema.
CREATE TABLE IF NOT EXISTS book_covering_service_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_product_id INT NOT NULL,
    charge_per_book DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_covering_service_book (book_product_id),
    CONSTRAINT chk_book_covering_service_charge CHECK (charge_per_book >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing sale_items covering fields are provided by dibonke_book_covering.sql.
-- Legacy book_covering_rules may remain in the database but are no longer used by the POS.

