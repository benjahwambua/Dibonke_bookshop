-- Dibonke Bookshop Store Operations
-- Additive migration. Back up the database before applying.
-- Requires the existing products, sales, sale_items, stock_movements,
-- users, roles and role_permissions tables.

CREATE TABLE IF NOT EXISTS stores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    store_code VARCHAR(30) NOT NULL,
    store_name VARCHAR(120) NOT NULL,
    store_type ENUM('Main','Selling') NOT NULL DEFAULT 'Selling',
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stores_code (store_code),
    UNIQUE KEY uq_stores_name (store_name),
    KEY idx_stores_type_status (store_type,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_stock (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_store_stock_product (store_id,product_id),
    KEY idx_store_stock_product (product_id),
    CONSTRAINT fk_store_stock_store FOREIGN KEY (store_id) REFERENCES stores(id),
    CONSTRAINT chk_store_stock_nonnegative CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_transfers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transfer_number VARCHAR(60) NOT NULL,
    transfer_type ENUM('Issue','Return') NOT NULL,
    from_store_id INT UNSIGNED NOT NULL,
    to_store_id INT UNSIGNED NOT NULL,
    transfer_date DATETIME NOT NULL,
    status ENUM('Posted','Cancelled') NOT NULL DEFAULT 'Posted',
    responsible_person VARCHAR(160) NULL,
    notes VARCHAR(500) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_store_transfer_number (transfer_number),
    KEY idx_store_transfer_date (transfer_date),
    KEY idx_store_transfer_route (from_store_id,to_store_id),
    CONSTRAINT fk_store_transfer_from FOREIGN KEY (from_store_id) REFERENCES stores(id),
    CONSTRAINT fk_store_transfer_to FOREIGN KEY (to_store_id) REFERENCES stores(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_transfer_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(14,3) NOT NULL,
    unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_store_transfer_product (transfer_id,product_id),
    KEY idx_store_transfer_item_product (product_id),
    CONSTRAINT fk_store_transfer_item_header FOREIGN KEY (transfer_id) REFERENCES store_transfers(id) ON DELETE CASCADE,
    CONSTRAINT chk_store_transfer_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_daily_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    business_date DATE NOT NULL,
    responsible_person VARCHAR(160) NOT NULL,
    status ENUM('Open','Reconciled') NOT NULL DEFAULT 'Open',
    issue_transfer_id BIGINT UNSIGNED NULL,
    return_transfer_id BIGINT UNSIGNED NULL,
    expected_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
    cash_received DECIMAL(14,2) NOT NULL DEFAULT 0,
    mpesa_received DECIMAL(14,2) NOT NULL DEFAULT 0,
    bank_received DECIMAL(14,2) NOT NULL DEFAULT 0,
    other_received DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_received DECIMAL(14,2) NOT NULL DEFAULT 0,
    money_variance DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    reconciled_by INT UNSIGNED NULL,
    reconciled_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_store_daily_session (store_id,business_date),
    KEY idx_store_daily_status_date (status,business_date),
    CONSTRAINT fk_store_daily_store FOREIGN KEY (store_id) REFERENCES stores(id),
    CONSTRAINT fk_store_daily_issue FOREIGN KEY (issue_transfer_id) REFERENCES store_transfers(id),
    CONSTRAINT fk_store_daily_return FOREIGN KEY (return_transfer_id) REFERENCES store_transfers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS store_daily_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id BIGINT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity_issued DECIMAL(14,3) NOT NULL DEFAULT 0,
    quantity_returned DECIMAL(14,3) NOT NULL DEFAULT 0,
    quantity_sold DECIMAL(14,3) NOT NULL DEFAULT 0,
    quantity_variance DECIMAL(14,3) NOT NULL DEFAULT 0,
    expected_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_store_daily_product (session_id,product_id),
    KEY idx_store_daily_item_product (product_id),
    CONSTRAINT fk_store_daily_item_session FOREIGN KEY (session_id) REFERENCES store_daily_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Support re-running the migration against a database created before stock variance was added.
SET @has_store_daily_variance := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='store_daily_items' AND COLUMN_NAME='quantity_variance'
);
SET @sql_store_daily_variance := IF(
    @has_store_daily_variance=0,
    'ALTER TABLE store_daily_items ADD COLUMN quantity_variance DECIMAL(14,3) NOT NULL DEFAULT 0 AFTER quantity_sold',
    'SELECT 1'
);
PREPARE stmt_store_daily_variance FROM @sql_store_daily_variance;
EXECUTE stmt_store_daily_variance;
DEALLOCATE PREPARE stmt_store_daily_variance;

-- Store attribution on each sale. Existing historical sales remain assigned to Main Store.
SET @has_sales_store_id := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND COLUMN_NAME='store_id'
);
SET @sql_sales_store_id := IF(
    @has_sales_store_id=0,
    'ALTER TABLE sales ADD COLUMN store_id INT UNSIGNED NULL AFTER user_id',
    'SELECT 1'
);
PREPARE stmt_sales_store_id FROM @sql_sales_store_id;
EXECUTE stmt_sales_store_id;
DEALLOCATE PREPARE stmt_sales_store_id;

SET @has_sales_store_date_index := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sales' AND INDEX_NAME='idx_sales_store_date'
);
SET @sql_sales_store_date_index := IF(
    @has_sales_store_date_index=0,
    'ALTER TABLE sales ADD INDEX idx_sales_store_date (store_id,sale_date)',
    'SELECT 1'
);
PREPARE stmt_sales_store_date_index FROM @sql_sales_store_date_index;
EXECUTE stmt_sales_store_date_index;
DEALLOCATE PREPARE stmt_sales_store_date_index;

INSERT IGNORE INTO stores (store_code,store_name,store_type,status)
VALUES
    ('MAIN','Main Store','Main','Active'),
    ('STORE1','Store 1','Selling','Active'),
    ('STORE2','Store 2','Selling','Active');

UPDATE sales
SET store_id=(SELECT id FROM stores WHERE store_code='MAIN' LIMIT 1)
WHERE store_id IS NULL;

-- Grant Store Operations to existing Administrator and Manager roles.
-- Match both role codes and display names because installations may use
-- ADMINISTRATOR / Administrator rather than the shorter ADMIN code.
INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'store_operations',1,1,1,0
FROM roles
WHERE UPPER(TRIM(role_code)) IN ('ADMIN','ADMINISTRATOR','MANAGER')
   OR UPPER(TRIM(role_name)) IN ('ADMIN','ADMINISTRATOR','MANAGER')
ON DUPLICATE KEY UPDATE
    can_view=1,
    can_create=1,
    can_edit=1;
