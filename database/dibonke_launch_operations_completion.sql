-- Dibonke Bookshop launch operations completion migration.
-- Additive only. Apply after dibonke_launch_operations.sql.
-- No foreign keys are declared because the inherited base schema has inconsistent ID definitions.

ALTER TABLE sales_returns
  ADD COLUMN IF NOT EXISTS credit_applied DECIMAL(14,2) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS settled_amount DECIMAL(14,2) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS sales_return_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id BIGINT UNSIGNED NOT NULL,
  payment_method ENUM('Cash','M-Pesa','Bank','Other') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  transaction_reference VARCHAR(120) NULL,
  notes VARCHAR(500) NULL,
  paid_by INT UNSIGNED NOT NULL,
  paid_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_return_payment_return(return_id),
  KEY idx_return_payment_date(paid_at),
  KEY idx_return_payment_method(payment_method),
  UNIQUE KEY uq_return_payment_reference(payment_method,transaction_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_shift_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shift_id BIGINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  movement_type ENUM('Cash In','Cash Out') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reference VARCHAR(120) NULL,
  description VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_cash_movement_shift(shift_id),
  KEY idx_cash_movement_user(user_id),
  KEY idx_cash_movement_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link cash expenses to the cashier shift that was open when they were recorded.
ALTER TABLE expenses
  ADD COLUMN IF NOT EXISTS cash_shift_id BIGINT UNSIGNED NULL,
  ADD KEY IF NOT EXISTS idx_expenses_cash_shift(cash_shift_id);

-- Record migration version for the setup checklist.
INSERT INTO settings(setting_key,setting_value)
SELECT 'launch_operations_completion_migration','1'
WHERE NOT EXISTS (
  SELECT 1 FROM settings WHERE setting_key='launch_operations_completion_migration'
);


-- Serialize cashier shift opening to prevent duplicate concurrent open shifts.
CREATE TABLE IF NOT EXISTS cash_shift_guard (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO cash_shift_guard(id) VALUES (1);

-- Serialize stock-count creation so two users cannot start competing sessions.
CREATE TABLE IF NOT EXISTS stock_count_guard (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO stock_count_guard(id) VALUES (1);
