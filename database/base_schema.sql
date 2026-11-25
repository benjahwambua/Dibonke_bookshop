-- ============================================================================
-- Dibonke Bookshop: BASE SCHEMA
-- ----------------------------------------------------------------------------
-- Creates the core tables the application code expects. Derived from every
-- INSERT / UPDATE / SELECT in includes/, modules/ and auth/.
--
-- Use on a NEW, EMPTY database named `dibonke_bookshop_db` only.
-- Do NOT run it against the Stalk & Stable database or any database holding
-- data you care about without taking a backup first.
--
-- Import order (see database/README.md):
--   1. base_schema.sql          (this file)
--   2. price_lists.sql          (price lists, product_prices, and the
--                                customers.price_list_id / sales.price_list_id
--                                columns + foreign keys)
--   3. supply_chain_mpesa.sql   (purchase orders, goods receipts, supplier
--                                payments, M-PESA transactions)
--   4. php database/create_super_admin.php   (first login)
--
-- price_list_id is intentionally NOT created here: price_lists.sql adds it
-- (after creating price_lists) so the two scripts stay compatible.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- users ----
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(30) NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('Admin','Manager','Sales','Cashier','Storekeeper','Driver','Staff') NOT NULL DEFAULT 'Staff',
    is_super TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------- categories ----
CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_categories_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------- brands ----
-- Used for brands, publishers or manufacturers.
CREATE TABLE IF NOT EXISTS brands (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    brand_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brands_name (brand_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------ suppliers ----
CREATE TABLE IF NOT EXISTS suppliers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    tax_pin VARCHAR(50) NULL,
    credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_suppliers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- products ----
-- `category` keeps a denormalised copy of the category name (the code writes
-- it alongside category_id). stock_quantity is signed so a stock correction can
-- never fail on an unsigned underflow.
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(160) NOT NULL,
    category VARCHAR(100) NULL,
    category_id INT UNSIGNED NULL,
    brand_id INT UNSIGNED NULL,
    supplier_id INT UNSIGNED NULL,
    sku VARCHAR(80) NULL,
    barcode VARCHAR(80) NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'Piece',
    buying_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    wholesale_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    stock_quantity INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 10,
    icon VARCHAR(20) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_sku (sku),
    UNIQUE KEY uq_products_barcode (barcode),
    INDEX idx_products_name (product_name),
    INDEX idx_products_status (status),
    INDEX idx_products_category (category_id),
    INDEX idx_products_brand (brand_id),
    INDEX idx_products_supplier (supplier_id),
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------ customers ----
-- price_list_id is added by price_lists.sql (AFTER credit_limit).
CREATE TABLE IF NOT EXISTS customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    address TEXT NULL,
    credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customers_name (customer_name),
    INDEX idx_customers_phone (phone),
    INDEX idx_customers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------- sales ----
-- price_list_id is added by price_lists.sql (AFTER customer_id).
-- customer_id is NULL for walk-in sales.
CREATE TABLE IF NOT EXISTS sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(60) NOT NULL,
    customer_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    sale_date DATETIME NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method ENUM('Cash','M-Pesa','Bank','Credit','Mixed') NOT NULL DEFAULT 'Cash',
    sale_status VARCHAR(20) NOT NULL DEFAULT 'Completed',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sales_invoice (invoice_number),
    INDEX idx_sales_date (sale_date),
    INDEX idx_sales_customer (customer_id),
    INDEX idx_sales_status (sale_status),
    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    price DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    buying_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    INDEX idx_sale_items_sale (sale_id),
    INDEX idx_sale_items_product (product_id),
    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------ purchases ----
-- Direct (non-PO) supplier purchases. The PO / GRN workflow lives in
-- supply_chain_mpesa.sql.
CREATE TABLE IF NOT EXISTS purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_number VARCHAR(60) NOT NULL,
    supplier_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    purchase_date DATETIME NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method ENUM('Credit','Cash','M-Pesa','Bank','Cheque','Other') NOT NULL DEFAULT 'Credit',
    status ENUM('Draft','Completed','Cancelled') NOT NULL DEFAULT 'Completed',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchases_number (purchase_number),
    INDEX idx_purchases_supplier (supplier_id),
    INDEX idx_purchases_date (purchase_date),
    CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    CONSTRAINT fk_purchases_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    buying_price DECIMAL(14,2) NOT NULL DEFAULT 0,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    INDEX idx_purchase_items_purchase (purchase_id),
    INDEX idx_purchase_items_product (product_id),
    CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------ stock_movements ----
-- movement_type is free text ('Sale','Purchase','Adjustment','Damage', ...) so
-- new movement kinds never need a schema change.
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    movement_type VARCHAR(30) NOT NULL,
    quantity INT NOT NULL,
    stock_before INT NOT NULL DEFAULT 0,
    stock_after INT NOT NULL DEFAULT 0,
    reference_type VARCHAR(50) NULL,
    reference_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    movement_date DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sm_product (product_id),
    INDEX idx_sm_date (movement_date),
    INDEX idx_sm_reference (reference_type, reference_id),
    CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT fk_sm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------- customer_payments ----
-- customer_id is NULL for payments recorded against walk-in sales.
CREATE TABLE IF NOT EXISTS customer_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(60) NOT NULL,
    customer_id INT UNSIGNED NULL,
    sale_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method ENUM('Cash','M-Pesa','Bank','Cheque','Other') NOT NULL DEFAULT 'Cash',
    transaction_reference VARCHAR(100) NULL,
    payment_date DATETIME NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cp_receipt (receipt_number),
    INDEX idx_cp_customer (customer_id),
    INDEX idx_cp_sale (sale_id),
    INDEX idx_cp_date (payment_date),
    INDEX idx_cp_reference (transaction_reference),
    CONSTRAINT fk_cp_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_cp_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------- expense_categories ----
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expense_categories_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    expense_number VARCHAR(60) NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    expense_title VARCHAR(200) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_method ENUM('Cash','M-Pesa','Bank','Cheque','Other') NOT NULL DEFAULT 'Cash',
    expense_date DATE NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expenses_number (expense_number),
    INDEX idx_expenses_date (expense_date),
    INDEX idx_expenses_category (category_id),
    CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id),
    CONSTRAINT fk_expenses_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------- deliveries ----
CREATE TABLE IF NOT EXISTS deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_number VARCHAR(60) NOT NULL,
    sale_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NULL,
    driver_id INT UNSIGNED NULL,
    delivery_address TEXT NOT NULL,
    delivery_date DATE NOT NULL,
    status ENUM('Pending','Dispatched','Out for Delivery','Delivered','Failed','Cancelled') NOT NULL DEFAULT 'Pending',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_deliveries_number (delivery_number),
    UNIQUE KEY uq_deliveries_sale (sale_id),
    INDEX idx_deliveries_status (status),
    INDEX idx_deliveries_driver (driver_id),
    CONSTRAINT fk_deliveries_sale FOREIGN KEY (sale_id) REFERENCES sales(id),
    CONSTRAINT fk_deliveries_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_driver FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS delivery_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    INDEX idx_delivery_items_delivery (delivery_id),
    CONSTRAINT fk_delivery_items_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------- activity_logs ----
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    module VARCHAR(80) NOT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_created (created_at),
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------- settings ----
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Starter data (safe to re-run: INSERT IGNORE against the unique keys)
-- ============================================================================

-- Every *_prefix key the code reads (invoice, receipt, purchase, PO, GRN,
-- delivery, expense) plus company details. Editable in Settings.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('company_name', 'Dibonke Bookshop'),
    ('company_type', 'Bookshop & General Retail'),
    ('currency', 'KES'),
    ('country', 'Kenya'),
    ('timezone', 'Africa/Nairobi'),
    ('low_stock_threshold', '10'),
    ('invoice_prefix', 'DB-INV'),
    ('receipt_prefix', 'DB-RCP'),
    ('purchase_prefix', 'DB-PUR'),
    ('po_prefix', 'DB-PO'),
    ('grn_prefix', 'DB-GRN'),
    ('delivery_prefix', 'DB-DEL'),
    ('expense_prefix', 'DB-EXP');

INSERT IGNORE INTO categories (category_name, description) VALUES
    ('Books', 'Novels, textbooks, reference and children''s books'),
    ('Stationery', 'Pens, pencils, notebooks, paper and writing supplies'),
    ('School Supplies', 'Exercise books, geometry sets, bags and school essentials'),
    ('Office Supplies', 'Files, folders, printer paper and office consumables'),
    ('Art & Craft', 'Art materials and craft supplies'),
    ('Gifts & Cards', 'Greeting cards, gift wrap and gift items'),
    ('Other', 'Other sellable items');

INSERT IGNORE INTO expense_categories (category_name) VALUES
    ('Rent'),
    ('Utilities'),
    ('Salaries & Wages'),
    ('Transport'),
    ('Marketing'),
    ('Repairs & Maintenance'),
    ('Licences & Taxes'),
    ('Bank & M-PESA Charges'),
    ('Miscellaneous');
