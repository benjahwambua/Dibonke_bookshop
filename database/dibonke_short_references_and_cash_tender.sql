-- Dibonke Bookshop: short sequential document references and cash tender tracking
-- Apply after the base retail schema is installed.
-- Existing invoices and payment history are preserved.
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS amount_tendered DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER paid_amount;

-- Existing records predate tender tracking. Their recorded paid amount is the
-- only verified historical tender value, so retain that as the safe fallback.
UPDATE sales SET amount_tendered=paid_amount WHERE amount_tendered=0 AND paid_amount>0;
