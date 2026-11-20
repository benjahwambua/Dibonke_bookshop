CREATE TABLE IF NOT EXISTS accounts (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, account_code VARCHAR(20) NOT NULL,
 account_name VARCHAR(120) NOT NULL,
 account_type ENUM('Asset','Liability','Equity','Income','Expense') NOT NULL,
 parent_id INT UNSIGNED NULL, is_control_account TINYINT(1) NOT NULL DEFAULT 0,
 is_system TINYINT(1) NOT NULL DEFAULT 0,
 status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
 description VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_accounts_code(account_code), KEY idx_accounts_type(account_type), KEY idx_accounts_parent(parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO accounts(account_code,account_name,account_type,is_system,description) VALUES
('1000','Assets','Asset',1,'Main asset group'),('1010','Cash on Hand','Asset',1,'Physical cash and till float'),
('1020','M-Pesa Clearing','Asset',1,'M-Pesa collections pending settlement'),('1030','Bank Account','Asset',1,'Business bank balances'),
('1100','Accounts Receivable','Asset',1,'Customer balances due'),('1200','Inventory','Asset',1,'Retail stock'),
('2000','Liabilities','Liability',1,'Main liability group'),('2010','Accounts Payable','Liability',1,'Supplier balances due'),
('2020','VAT Payable','Liability',1,'Output VAT due to KRA'),('3000','Owner Capital','Equity',1,'Owner contributions'),
('3100','Retained Earnings','Equity',1,'Accumulated profits'),('4000','Sales Revenue','Income',1,'Retail sales'),
('4010','Sales Returns','Income',1,'Sales returns and refunds'),('5000','Cost of Goods Sold','Expense',1,'Cost of inventory sold'),
('5100','Operating Expenses','Expense',1,'Operating and administrative expenses');
CREATE TABLE IF NOT EXISTS hr_leave_types (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, leave_name VARCHAR(80) NOT NULL,
 annual_days DECIMAL(6,2) NOT NULL DEFAULT 0, is_paid TINYINT(1) NOT NULL DEFAULT 1,
 status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
 PRIMARY KEY(id), UNIQUE KEY uq_hr_leave_name(leave_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO hr_leave_types(leave_name,annual_days,is_paid) VALUES
('Annual Leave',21,1),('Sick Leave',14,1),('Compassionate Leave',3,1),('Unpaid Leave',0,0);
CREATE TABLE IF NOT EXISTS hr_leave_requests (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, staff_id INT UNSIGNED NOT NULL, leave_type_id INT UNSIGNED NOT NULL,
 start_date DATE NOT NULL, end_date DATE NOT NULL, days_requested DECIMAL(6,2) NOT NULL,
 reason TEXT NULL, status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
 requested_by INT UNSIGNED NULL, reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_hr_leave_staff(staff_id), KEY idx_hr_leave_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_attendance (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT, staff_id INT UNSIGNED NOT NULL, attendance_date DATE NOT NULL,
 check_in TIME NULL, check_out TIME NULL,
 status ENUM('Present','Absent','Late','Half Day','On Leave') NOT NULL DEFAULT 'Present',
 notes VARCHAR(500) NULL, recorded_by INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_hr_attendance_day(staff_id,attendance_date), KEY idx_hr_attendance_date(attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'hr',1,1,1,1 FROM roles WHERE role_code IN ('ADMIN','MANAGER')
ON DUPLICATE KEY UPDATE can_view=1,can_create=1,can_edit=1,can_delete=1;
INSERT INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'chart_of_accounts',1,1,1,1 FROM roles WHERE role_code IN ('ADMIN','MANAGER')
ON DUPLICATE KEY UPDATE can_view=1,can_create=1,can_edit=1,can_delete=1;
