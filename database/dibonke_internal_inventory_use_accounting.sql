-- Accounting support for whole cover rolls issued for internal use.
-- Safe to run more than once. Requires the double-entry accounting schema.
INSERT IGNORE INTO accounts(account_code,account_name,account_type,is_system,description)
VALUES('5150','Internal Inventory Use Expense','Expense',1,'Cost of inventory issued for internal business use');

INSERT IGNORE INTO accounting_mappings(mapping_key,account_id)
SELECT 'internal_inventory_use',id FROM accounts WHERE account_code='5150' LIMIT 1;
