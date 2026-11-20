-- Book covering rules: one active roll/material recipe per exercise-book product.
-- Track roll inventory in whole centimetres because the inherited stock ledger is integer-only.
CREATE TABLE IF NOT EXISTS book_covering_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    book_product_id INT NOT NULL,
    cover_product_id INT NOT NULL,
    material_cm_per_book INT UNSIGNED NOT NULL,
    charge_per_book DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_book_covering_book (book_product_id),
    KEY idx_book_covering_material (cover_product_id),
    CONSTRAINT chk_book_covering_usage CHECK (material_cm_per_book > 0),
    CONSTRAINT chk_book_covering_charge CHECK (charge_per_book >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE sale_items
    ADD COLUMN covering_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN covering_material_product_id INT NULL,
    ADD COLUMN covering_cm_per_book INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN covering_charge_per_book DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN covering_total_material_cm INT UNSIGNED NOT NULL DEFAULT 0;
