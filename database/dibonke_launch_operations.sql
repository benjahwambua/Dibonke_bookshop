-- Dibonke Bookshop launch operations extensions.
-- Additive migration. Requires the existing Dibonke base tables:
-- users, products, sales, sale_items, stock_movements, customers, settings,
-- roles and role_permissions. Back up before applying.

CREATE TABLE IF NOT EXISTS sales_returns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_number VARCHAR(60) NOT NULL UNIQUE,
  sale_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  return_date DATETIME NOT NULL,
  return_type ENUM('Refund','Exchange','Store Credit') NOT NULL DEFAULT 'Refund',
  refund_method ENUM('Cash','M-Pesa','Bank','Store Credit','Other') NOT NULL DEFAULT 'Cash',
  reason VARCHAR(500) NOT NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('Pending','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_returns_sale(sale_id), KEY idx_returns_date(return_date), KEY idx_returns_status(status),
  CONSTRAINT fk_returns_sale FOREIGN KEY(sale_id) REFERENCES sales(id),
  CONSTRAINT fk_returns_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_returns_user FOREIGN KEY(user_id) REFERENCES users(id),
  CONSTRAINT fk_returns_approver FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sales_return_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id BIGINT UNSIGNED NOT NULL,
  sale_item_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  restock TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_return_items_return(return_id), KEY idx_return_items_sale_item(sale_item_id),
  CONSTRAINT fk_return_items_return FOREIGN KEY(return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_return_items_sale_item FOREIGN KEY(sale_item_id) REFERENCES sale_items(id),
  CONSTRAINT fk_return_items_product FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_shifts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shift_number VARCHAR(60) NOT NULL UNIQUE,
  user_id INT UNSIGNED NOT NULL,
  opened_at DATETIME NOT NULL,
  opening_float DECIMAL(14,2) NOT NULL DEFAULT 0,
  closed_at DATETIME NULL,
  expected_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  counted_cash DECIMAL(14,2) NULL,
  variance DECIMAL(14,2) NULL,
  status ENUM('Open','Closed','Reviewed') NOT NULL DEFAULT 'Open',
  closing_notes TEXT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cash_shift_user_status(user_id,status), KEY idx_cash_shift_opened(opened_at),
  CONSTRAINT fk_cash_shift_user FOREIGN KEY(user_id) REFERENCES users(id),
  CONSTRAINT fk_cash_shift_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_counts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  count_number VARCHAR(60) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL,
  count_date DATETIME NOT NULL,
  status ENUM('Draft','Counting','Submitted','Approved','Cancelled') NOT NULL DEFAULT 'Draft',
  created_by INT UNSIGNED NOT NULL,
  submitted_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  submitted_at DATETIME NULL,
  approved_at DATETIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stock_count_status(status),
  CONSTRAINT fk_stock_count_creator FOREIGN KEY(created_by) REFERENCES users(id),
  CONSTRAINT fk_stock_count_submitter FOREIGN KEY(submitted_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_stock_count_approver FOREIGN KEY(approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_count_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_count_id BIGINT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  system_quantity INT UNSIGNED NOT NULL DEFAULT 0,
  counted_quantity INT UNSIGNED NULL,
  variance INT NULL,
  counted_by INT UNSIGNED NULL,
  counted_at DATETIME NULL,
  UNIQUE KEY uq_stock_count_product(stock_count_id,product_id),
  CONSTRAINT fk_stock_count_item_count FOREIGN KEY(stock_count_id) REFERENCES stock_counts(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_count_item_product FOREIGN KEY(product_id) REFERENCES products(id),
  CONSTRAINT fk_stock_count_item_user FOREIGN KEY(counted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  module_key VARCHAR(80) NOT NULL,
  record_type VARCHAR(80) NULL,
  record_id BIGINT NULL,
  description VARCHAR(500) NOT NULL,
  before_data LONGTEXT NULL,
  after_data LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created(created_at), KEY idx_audit_module(module_key), KEY idx_audit_user(user_id),
  CONSTRAINT fk_audit_event_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS etims_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id INT UNSIGNED NOT NULL,
  invoice_number VARCHAR(80) NOT NULL,
  status ENUM('Queued','Submitted','Accepted','Rejected','Failed') NOT NULL DEFAULT 'Queued',
  kra_invoice_number VARCHAR(100) NULL,
  cu_invoice_number VARCHAR(100) NULL,
  qr_code_url TEXT NULL,
  request_payload LONGTEXT NULL,
  response_payload LONGTEXT NULL,
  error_message VARCHAR(1000) NULL,
  submitted_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_etims_sale(sale_id), KEY idx_etims_status(status),
  CONSTRAINT fk_etims_sale FOREIGN KEY(sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permissions: seed missing module keys without overwriting custom role settings.
INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT r.id,p.module_key,p.v,p.c,p.e,p.d
FROM roles r
JOIN (
 SELECT 'sales_returns' module_key,1 v,1 c,1 e,0 d UNION ALL
 SELECT 'cash_management',1,1,1,0 UNION ALL
 SELECT 'stocktaking',1,1,1,0 UNION ALL
 SELECT 'audit_logs',1,0,0,0 UNION ALL
 SELECT 'etims',1,1,1,0
) p
WHERE r.role_code='ADMIN';

INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT r.id,p.module_key,p.v,p.c,p.e,p.d
FROM roles r
JOIN (
 SELECT 'sales_returns' module_key,1 v,1 c,1 e,0 d UNION ALL
 SELECT 'cash_management',1,1,1,0 UNION ALL
 SELECT 'stocktaking',1,1,1,0 UNION ALL
 SELECT 'audit_logs',1,0,0,0 UNION ALL
 SELECT 'etims',1,0,0,0
) p
WHERE r.role_code='MANAGER';

INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT r.id,'sales_returns',1,1,0,0 FROM roles r WHERE r.role_code IN ('SALES','CASHIER');

INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT r.id,'cash_management',1,1,1,0 FROM roles r WHERE r.role_code='CASHIER';

INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT r.id,'stocktaking',1,1,0,0 FROM roles r WHERE r.role_code='STOREKEEPER';

INSERT INTO settings(setting_key,setting_value)
SELECT 'etims_environment','sandbox' WHERE NOT EXISTS(SELECT 1 FROM settings WHERE setting_key='etims_environment');
INSERT INTO settings(setting_key,setting_value)
SELECT 'etims_enabled','0' WHERE NOT EXISTS(SELECT 1 FROM settings WHERE setting_key='etims_enabled');

-- Unified launch controls landing page permission. Detailed actions remain
-- checked against each individual module permission in the application.
INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT r.id,'launch_controls',1,0,0,0 FROM roles r
WHERE r.role_code IN ('ADMIN','MANAGER','SALES','CASHIER','STOREKEEPER');
