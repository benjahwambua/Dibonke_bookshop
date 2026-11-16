<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

/**
 * Authentication + temporary open module access.
 *
 * Authentication remains mandatory, but module-level role restrictions are
 * temporarily disabled while the bookshop roles and permissions are being
 * finalized. Keep the role map below as the reference for restoring RBAC.
 */
if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

function currentUserRole(): string
{
    return trim((string)($_SESSION['role'] ?? 'Staff'));
}

function isSuperUser(): bool
{
    return (int)($_SESSION['is_super'] ?? 0) === 1;
}

function roleModules(): array
{
    return [
        'Admin' => [
            'products', 'categories', 'brands',
            'customers', 'suppliers', 'purchases', 'sales',
            'customer_payments', 'supplier_payments', 'deliveries', 'stock_movements', 'expenses',
            'reports', 'analytics', 'supply_chain', 'mpesa', 'price_lists'
        ],
        'Manager' => [
            'products', 'categories', 'brands',
            'customers', 'suppliers', 'purchases', 'sales',
            'customer_payments', 'supplier_payments', 'deliveries', 'stock_movements', 'expenses',
            'reports', 'analytics', 'supply_chain', 'mpesa'
        ],
        'Sales' => [
            'customers', 'sales', 'customer_payments', 'deliveries', 'reports'
        ],
        'Cashier' => [
            'customers', 'sales', 'customer_payments'
        ],
        'Storekeeper' => [
            'products', 'categories', 'brands', 'suppliers',
            'purchases', 'stock_movements', 'deliveries', 'supply_chain'
        ],
        'Driver' => [
            'deliveries'
        ],
        'Staff' => [],
    ];
}

/**
 * TEMPORARY: allow every authenticated user through module-level checks.
 * Restore the roleModules() lookup here when the unified permissions module
 * is ready. Login authentication is still enforced above.
 */
function canAccessModule(string $module): bool
{
    return true;
}

function requireModuleAccess(string $module): void
{
    if (canAccessModule($module)) {
        return;
    }

    http_response_code(403);
    exit('Access denied. Your user role does not have permission to access this module.');
}

/**
 * Automatically enforce the permission for any page under /modules/{module}/.
 */
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$modulesMarker = '/modules/';
$markerPosition = strpos($requestPath, $modulesMarker);

if ($markerPosition !== false) {
    $afterMarker = substr($requestPath, $markerPosition + strlen($modulesMarker));
    $module = strtolower((string)strtok($afterMarker, '/'));

    if ($module !== '') {
        requireModuleAccess($module);
    }
}
