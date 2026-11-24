-- Employee self-service and aged receivables access.
-- Requires dibonke_staff_roles.sql, dibonke_accounting_hr.sql and existing sales/customers tables.

INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'hr_self_service',1,1,0,0 FROM roles
WHERE role_code IN ('ADMIN','MANAGER','SALES','CASHIER','STOREKEEPER','DRIVER','STAFF')
ON DUPLICATE KEY UPDATE can_view=1,can_create=1;

INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'aged_receivables',1,0,0,0 FROM roles
WHERE role_code IN ('ADMIN','MANAGER')
ON DUPLICATE KEY UPDATE can_view=1;
