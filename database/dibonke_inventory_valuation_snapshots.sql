-- Auditable point-in-time inventory valuation snapshots using the current weighted-average buying price.
CREATE TABLE IF NOT EXISTS inventory_valuation_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    total_quantity DECIMAL(16,3) NOT NULL DEFAULT 0,
    total_value DECIMAL(16,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inventory_valuation_date (snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_valuation_snapshot_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    store_id INT UNSIGNED NULL,
    quantity DECIMAL(16,3) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
    total_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_valuation_item_snapshot (snapshot_id),
    KEY idx_valuation_item_product (product_id),
    KEY idx_valuation_item_store (store_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
