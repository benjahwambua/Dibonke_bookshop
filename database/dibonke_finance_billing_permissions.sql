-- Dibonke Bookshop: Finance and Billing module permissions
-- Additive and safe to re-run. Run against dibonke_bookshop_db after the roles migration.
INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'finance',0,0,0,0 FROM roles;
INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'billing',0,0,0,0 FROM roles;
UPDATE role_permissions rp JOIN roles r ON r.id=rp.role_id
SET rp.can_view=1,rp.can_create=1,rp.can_edit=1,rp.can_delete=1
WHERE r.role_code IN ('ADMIN','MANAGER') AND rp.module_key IN ('finance','billing');
UPDATE role_permissions rp JOIN roles r ON r.id=rp.role_id
SET rp.can_view=1,rp.can_create=0,rp.can_edit=0,rp.can_delete=0
WHERE r.role_code IN ('SALES','CASHIER') AND rp.module_key='billing';
