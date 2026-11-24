-- Persist the amount of each successful M-PESA receipt applied to its target
-- and the excess held in customer credit or unallocated receipts.
-- Apply after supply_chain_mpesa.sql and before deploying the callback changes.

SET @has_mpesa_amount_applied := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mpesa_transactions' AND COLUMN_NAME='amount_applied'
);
SET @ddl_mpesa_amount_applied := IF(
  @has_mpesa_amount_applied=0,
  'ALTER TABLE mpesa_transactions ADD COLUMN amount_applied DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER amount',
  'SELECT 1'
);
PREPARE stmt_mpesa_amount_applied FROM @ddl_mpesa_amount_applied;
EXECUTE stmt_mpesa_amount_applied;
DEALLOCATE PREPARE stmt_mpesa_amount_applied;

SET @has_mpesa_amount_unallocated := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mpesa_transactions' AND COLUMN_NAME='amount_unallocated'
);
SET @ddl_mpesa_amount_unallocated := IF(
  @has_mpesa_amount_unallocated=0,
  'ALTER TABLE mpesa_transactions ADD COLUMN amount_unallocated DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER amount_applied',
  'SELECT 1'
);
PREPARE stmt_mpesa_amount_unallocated FROM @ddl_mpesa_amount_unallocated;
EXECUTE stmt_mpesa_amount_unallocated;
DEALLOCATE PREPARE stmt_mpesa_amount_unallocated;

-- A successful C2B receipt with no target was wholly unmatched in the old workflow.
-- This is a safe historical backfill because the old row contains no applied target.
UPDATE mpesa_transactions
SET amount_applied=0.00, amount_unallocated=amount
WHERE transaction_type='C2B'
  AND direction='Incoming'
  AND result_status='Success'
  AND reference_type IS NULL
  AND amount_unallocated=0.00;

SET @has_mpesa_unallocated_index := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mpesa_transactions' AND INDEX_NAME='idx_mpesa_unallocated_date'
);
SET @ddl_mpesa_unallocated_index := IF(
  @has_mpesa_unallocated_index=0,
  'ALTER TABLE mpesa_transactions ADD INDEX idx_mpesa_unallocated_date (result_status, direction, transaction_date)',
  'SELECT 1'
);
PREPARE stmt_mpesa_unallocated_index FROM @ddl_mpesa_unallocated_index;
EXECUTE stmt_mpesa_unallocated_index;
DEALLOCATE PREPARE stmt_mpesa_unallocated_index;
