-- Dibonke Fleet Management (additive migration)
CREATE TABLE IF NOT EXISTS fleet_vehicles (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 registration_number VARCHAR(30) NOT NULL,
 vehicle_name VARCHAR(100) NULL,
 vehicle_type VARCHAR(60) NOT NULL DEFAULT 'Vehicle',
 make_model VARCHAR(120) NULL,
 capacity_description VARCHAR(120) NULL,
 assigned_driver_id INT UNSIGNED NULL,
 insurance_expiry DATE NULL,
 inspection_expiry DATE NULL,
 current_odometer INT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('Active','Inactive','Maintenance') NOT NULL DEFAULT 'Active',
 notes TEXT NULL,
 created_by INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_fleet_registration(registration_number),
 KEY idx_fleet_status(status), KEY idx_fleet_driver(assigned_driver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fleet_maintenance (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 vehicle_id INT UNSIGNED NOT NULL,
 service_date DATE NOT NULL,
 service_type VARCHAR(100) NOT NULL,
 odometer INT UNSIGNED NULL,
 cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
 supplier VARCHAR(160) NULL,
 next_service_date DATE NULL,
 notes TEXT NULL,
 recorded_by INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), KEY idx_fleet_maintenance_vehicle(vehicle_id),
 KEY idx_fleet_maintenance_date(service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_delivery_vehicle := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='deliveries' AND COLUMN_NAME='vehicle_id');
SET @sql_delivery_vehicle := IF(@has_delivery_vehicle=0,'ALTER TABLE deliveries ADD COLUMN vehicle_id INT UNSIGNED NULL AFTER driver_id','SELECT 1');
PREPARE stmt_delivery_vehicle FROM @sql_delivery_vehicle; EXECUTE stmt_delivery_vehicle; DEALLOCATE PREPARE stmt_delivery_vehicle;

INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'fleet',1,1,1,1 FROM roles WHERE role_code='ADMIN';
INSERT IGNORE INTO role_permissions(role_id,module_key,can_view,can_create,can_edit,can_delete)
SELECT id,'fleet',1,1,1,0 FROM roles WHERE role_code='MANAGER';
