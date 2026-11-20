-- Dibonke payment allocation foundation
-- Apply once to the existing Dibonke database before deploying the matching PHP changes.
-- Existing customer/supplier payment rows remain intact; new allocations are recorded separately.

CREATE TABLE IF NOT EXISTS customer_payment_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    sale_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_payment_sale (payment_id, sale_id),
    KEY idx_customer_alloc_sale (sale_id),
    CONSTRAINT chk_customer_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_payment_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_payment_purchase (payment_id, purchase_id),
    KEY idx_supplier_alloc_purchase (purchase_id),
    CONSTRAINT chk_supplier_allocation_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link existing user accounts to configured roles where names/codes match.
UPDATE users u
JOIN roles r
  ON (UPPER(REPLACE(REPLACE(TRIM(r.role_name), ' ', ''), '-', '')) =
     UPPER(REPLACE(REPLACE(TRIM(u.role), ' ', ''), '-', '')) OR UPPER(r.role_code)=UPPER(REPLACE(TRIM(u.role),' ','_')))
SET u.role_id = r.id
WHERE u.role_id IS NULL;


-- Preserve the existing role-based view access as explicit database permissions.
INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT DISTINCT r.id, m.module_key, 1, 0, 0, 0
FROM roles r
JOIN (SELECT 'Admin' role_name, 'products' module_key
UNION ALL
SELECT 'Admin' role_name, 'categories' module_key
UNION ALL
SELECT 'Admin' role_name, 'brands' module_key
UNION ALL
SELECT 'Admin' role_name, 'customers' module_key
UNION ALL
SELECT 'Admin' role_name, 'suppliers' module_key
UNION ALL
SELECT 'Admin' role_name, 'purchases' module_key
UNION ALL
SELECT 'Admin' role_name, 'sales' module_key
UNION ALL
SELECT 'Admin' role_name, 'customer_orders' module_key
UNION ALL
SELECT 'Admin' role_name, 'customer_payments' module_key
UNION ALL
SELECT 'Admin' role_name, 'supplier_payments' module_key
UNION ALL
SELECT 'Admin' role_name, 'deliveries' module_key
UNION ALL
SELECT 'Admin' role_name, 'stock_movements' module_key
UNION ALL
SELECT 'Admin' role_name, 'expenses' module_key
UNION ALL
SELECT 'Admin' role_name, 'finance' module_key
UNION ALL
SELECT 'Admin' role_name, 'billing' module_key
UNION ALL
SELECT 'Admin' role_name, 'reports' module_key
UNION ALL
SELECT 'Admin' role_name, 'analytics' module_key
UNION ALL
SELECT 'Admin' role_name, 'supply_chain' module_key
UNION ALL
SELECT 'Admin' role_name, 'mpesa' module_key
UNION ALL
SELECT 'Admin' role_name, 'price_lists' module_key
UNION ALL
SELECT 'Admin' role_name, 'staff' module_key
UNION ALL
SELECT 'Manager' role_name, 'products' module_key
UNION ALL
SELECT 'Manager' role_name, 'categories' module_key
UNION ALL
SELECT 'Manager' role_name, 'brands' module_key
UNION ALL
SELECT 'Manager' role_name, 'customers' module_key
UNION ALL
SELECT 'Manager' role_name, 'suppliers' module_key
UNION ALL
SELECT 'Manager' role_name, 'purchases' module_key
UNION ALL
SELECT 'Manager' role_name, 'sales' module_key
UNION ALL
SELECT 'Manager' role_name, 'customer_orders' module_key
UNION ALL
SELECT 'Manager' role_name, 'customer_payments' module_key
UNION ALL
SELECT 'Manager' role_name, 'supplier_payments' module_key
UNION ALL
SELECT 'Manager' role_name, 'deliveries' module_key
UNION ALL
SELECT 'Manager' role_name, 'stock_movements' module_key
UNION ALL
SELECT 'Manager' role_name, 'expenses' module_key
UNION ALL
SELECT 'Manager' role_name, 'finance' module_key
UNION ALL
SELECT 'Manager' role_name, 'billing' module_key
UNION ALL
SELECT 'Manager' role_name, 'reports' module_key
UNION ALL
SELECT 'Manager' role_name, 'analytics' module_key
UNION ALL
SELECT 'Manager' role_name, 'supply_chain' module_key
UNION ALL
SELECT 'Manager' role_name, 'mpesa' module_key
UNION ALL
SELECT 'Manager' role_name, 'staff' module_key
UNION ALL
SELECT 'Sales' role_name, 'customers' module_key
UNION ALL
SELECT 'Sales' role_name, 'sales' module_key
UNION ALL
SELECT 'Sales' role_name, 'customer_orders' module_key
UNION ALL
SELECT 'Sales' role_name, 'customer_payments' module_key
UNION ALL
SELECT 'Sales' role_name, 'deliveries' module_key
UNION ALL
SELECT 'Sales' role_name, 'billing' module_key
UNION ALL
SELECT 'Sales' role_name, 'reports' module_key
UNION ALL
SELECT 'Cashier' role_name, 'customers' module_key
UNION ALL
SELECT 'Cashier' role_name, 'sales' module_key
UNION ALL
SELECT 'Cashier' role_name, 'customer_payments' module_key
UNION ALL
SELECT 'Cashier' role_name, 'billing' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'products' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'categories' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'brands' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'suppliers' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'purchases' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'stock_movements' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'deliveries' module_key
UNION ALL
SELECT 'Storekeeper' role_name, 'supply_chain' module_key
UNION ALL
SELECT 'Driver' role_name, 'deliveries' module_key) m ON (LOWER(m.role_name)=LOWER(r.role_name) OR UPPER(m.role_name)=UPPER(r.role_code))
WHERE NOT EXISTS (
    SELECT 1 FROM role_permissions rp
    WHERE rp.role_id=r.id AND rp.module_key=m.module_key
);
