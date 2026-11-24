-- Customer sales orders and invoice approval workflow.
-- Apply after the existing Dibonke base schema and role migrations.
CREATE TABLE IF NOT EXISTS customer_sales_orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_number VARCHAR(60) NOT NULL UNIQUE,
 customer_id INT UNSIGNED NOT NULL,
 price_list_id INT UNSIGNED NULL,
 created_by INT UNSIGNED NOT NULL,
 validated_by INT UNSIGNED NULL,
 confirmed_by INT UNSIGNED NULL,
 order_date DATETIME NOT NULL,
 validated_at DATETIME NULL,
 confirmed_at DATETIME NULL,
 subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
 discount DECIMAL(14,2) NOT NULL DEFAULT 0,
 tax DECIMAL(14,2) NOT NULL DEFAULT 0,
 total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
 status ENUM('Draft','Validated','Invoiced','Cancelled') NOT NULL DEFAULT 'Draft',
 notes VARCHAR(1000) NULL,
 sale_id INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_customer_order_customer(customer_id),
 KEY idx_customer_order_status(status),
 KEY idx_customer_order_date(order_date),
 KEY idx_customer_order_sale(sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_sales_order_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
 entered_quantity INT UNSIGNED NOT NULL,
 sale_unit VARCHAR(40) NOT NULL,
 conversion_factor INT UNSIGNED NOT NULL DEFAULT 1,
 entered_unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
 KEY idx_customer_order_items_order(order_id),
 KEY idx_customer_order_items_product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'customer_orders',1,1,1,0 FROM roles WHERE role_code IN ('ADMIN','MANAGER','SALES');
