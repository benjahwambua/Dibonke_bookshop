-- Accounting period locks. Apply after the double-entry accounting migration.
CREATE TABLE IF NOT EXISTS accounting_period_locks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    reason VARCHAR(255) NOT NULL,
    locked_by INT UNSIGNED NULL,
    locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_period_range (period_start,period_end),
    KEY idx_accounting_period_dates (period_start,period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
