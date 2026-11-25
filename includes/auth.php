<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

/**
 * Refresh authorization data from the database on every authenticated request.
 * This prevents stale session role data from hiding navigation or granting the
 * wrong access after an administrator changes a user's role.
 */
$userId = (int) $_SESSION['user_id'];
$userQuery = $GLOBALS['conn']->prepare(
    "SELECT u.id, u.full_name, u.username, u.email, u.role_id, u.is_super, u.status,
            COALESCE(r.role_name, u.role) AS role_name,
            COALESCE(r.role_code, '') AS role_code
     FROM users u
     LEFT JOIN roles r ON r.id = u.role_id
     WHERE u.id = ?
     LIMIT 1"
);
$userQuery->execute([$userId]);
$authenticatedUser = $userQuery->fetch(PDO::FETCH_ASSOC);

if (!$authenticatedUser || strcasecmp((string) $authenticatedUser['status'], 'Active') !== 0) {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$_SESSION['full_name'] = (string) $authenticatedUser['full_name'];
$_SESSION['username'] = (string) $authenticatedUser['username'];
$_SESSION['email'] = (string) ($authenticatedUser['email'] ?? '');
$_SESSION['role'] = (string) $authenticatedUser['role_name'];
$_SESSION['role_code'] = strtoupper(trim((string) $authenticatedUser['role_code']));
$_SESSION['role_id'] = (int) ($authenticatedUser['role_id'] ?? 0);
$_SESSION['is_super'] = (int) $authenticatedUser['is_super'];

function currentUserRole(): string
{
    return trim((string) ($_SESSION['role'] ?? 'Staff'));
}

function isSuperUser(): bool
{
    return (int) ($_SESSION['is_super'] ?? 0) === 1;
}

function isAdministrator(): bool
{
    return isSuperUser()
        || strtoupper(trim((string) ($_SESSION['role_code'] ?? ''))) === 'ADMIN'
        || in_array(strtolower(trim((string) ($_SESSION['role'] ?? ''))), ['admin', 'administrator'], true);
}

function hasModulePermission(string $module, string $action = 'view'): bool
{
    if (isAdministrator()) {
        return true;
    }

    $allowed = [
        'view' => 'can_view',
        'create' => 'can_create',
        'edit' => 'can_edit',
        'delete' => 'can_delete',
    ];

    if (!isset($allowed[$action])) {
        return false;
    }

    static $cache = [];
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $key = $userId . ':' . $module . ':' . $action;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $column = $allowed[$action];
    $query = $GLOBALS['conn']->prepare(
        "SELECT rp.{$column}
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id AND r.status = 'Active'
         INNER JOIN role_permissions rp ON rp.role_id = r.id
         WHERE u.id = ? AND u.status = 'Active' AND rp.module_key = ?
         LIMIT 1"
    );
    $query->execute([$userId, $module]);
    $cache[$key] = (int) $query->fetchColumn() === 1;

    return $cache[$key];
}

function canAccessModule(string $module): bool
{
    return hasModulePermission($module, 'view');
}

function requireModulePermission(string $module, string $action): void
{
    if (hasModulePermission($module, $action)) {
        return;
    }

    http_response_code(403);
    exit('Access denied. Your role does not have permission to ' . $action . ' in the ' . htmlspecialchars($module, ENT_QUOTES, 'UTF-8') . ' module.');
}

function requireModuleAccess(string $module): void
{
    // Help and the user manual are available to every authenticated staff member.
    if ($module === 'help') {
        return;
    }

    // Launch Operations is a container for separately permissioned workflows.
    // A user assigned to one of its tabs must be able to open that tab without
    // also receiving the broad overview permission.
    if ($module === 'launch_controls') {
        foreach (['launch_controls', 'sales_returns', 'cash_management', 'stocktaking', 'audit_logs', 'etims'] as $launchModule) {
            if (hasModulePermission($launchModule, 'view')) {
                return;
            }
        }
    }

    if (in_array($module, ['users', 'roles', 'settings'], true)) {
        if (isAdministrator()) {
            return;
        }

        http_response_code(403);
        exit('Access denied. Administrator access is required.');
    }

    if (canAccessModule($module)) {
        return;
    }

    http_response_code(403);
    exit('Access denied. Your user role does not have permission to access this module.');
}

/**
 * Resolve nested pages to the permission key shown in Roles & Permissions.
 * Without this map, every page inside /modules/finance/ was incorrectly
 * checked against the generic "finance" permission.
 */
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$markerPosition = strpos($requestPath, '/modules/');

if ($markerPosition !== false) {
    $afterMarker = strtolower(trim(substr($requestPath, $markerPosition + strlen('/modules/')), '/'));
    $routePermissions = [
        'finance/chart_of_accounts.php' => 'chart_of_accounts',
        'finance/journals.php' => 'journals',
        'finance/general_ledger.php' => 'general_ledger',
        'finance/trial_balance.php' => 'trial_balance',
        'finance/aged_receivables.php' => 'aged_receivables',
        'finance/aged_payables.php' => 'aged_payables',
        'deliveries/fleet.php' => 'fleet',
        'finance/posting_mappings.php' => 'accounting_mappings',
        'finance/periods.php' => 'finance',
        'finance/statements.php' => 'finance',
        'sales/my_shift.php' => 'cash_management',
        'hr/my.php' => 'hr_self_service',
        'inventory/index.php' => 'products',
        'inventory/valuation.php' => 'products',
    ];

    $module = $routePermissions[$afterMarker] ?? strtolower((string) strtok($afterMarker, '/'));

    if ($module !== '') {
        requireModuleAccess($module);
    }
}
?>