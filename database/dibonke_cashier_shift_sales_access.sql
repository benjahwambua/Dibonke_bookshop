-- Grant Sales and Cashier roles access to their own cashier shift page.
-- Apply after dibonke_staff_roles.sql and dibonke_launch_operations.sql.
INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT r.id,'cash_management',1,1,1,0
FROM roles r
WHERE r.role_code IN ('SALES','CASHIER');
