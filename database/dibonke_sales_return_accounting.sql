-- Customer sales-return and refund accounting mappings.
-- Apply after dibonke_automatic_accounting_posting.sql.
-- Safe to re-run: account codes and mapping keys are unique.

INSERT IGNORE INTO accounts(account_code,account_name,account_type,is_system,description) VALUES
('4010','Sales Returns','Income',1,'Contra-revenue account for approved customer returns'),
('2030','Refunds Payable','Liability',1,'Approved customer refunds awaiting settlement');

INSERT IGNORE INTO accounting_mappings(mapping_key,account_id)
SELECT 'sales_returns',id FROM accounts WHERE account_code='4010' AND account_type='Income';

INSERT IGNORE INTO accounting_mappings(mapping_key,account_id)
SELECT 'refund_payable',id FROM accounts WHERE account_code='2030' AND account_type='Liability';
