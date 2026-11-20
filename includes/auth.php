<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
if (empty($_SESSION['user_id'])) { header('Location: ' . BASE_URL . '/auth/login.php'); exit; }
function currentUserRole(): string { return trim((string)($_SESSION['role'] ?? 'Staff')); }
function isSuperUser(): bool { return (int)($_SESSION['is_super'] ?? 0) === 1; }
function isAdministrator(): bool { return isSuperUser() || strtoupper(trim((string)($_SESSION['role_code'] ?? ''))) === 'ADMIN' || in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['admin', 'administrator'], true); }
function hasModulePermission(string $module,string $action='view'): bool {
 if(isSuperUser() || isAdministrator()) return true;
 $allowed=['view'=>'can_view','create'=>'can_create','edit'=>'can_edit','delete'=>'can_delete'];
 if(!isset($allowed[$action])) return false;
 static $cache=[];
 $key=(int)$_SESSION['user_id'].':'.$module.':'.$action;
 if(array_key_exists($key,$cache)) return $cache[$key];
 $q=$GLOBALS['conn']->prepare("SELECT rp.{$allowed[$action]} FROM users u JOIN role_permissions rp ON rp.role_id=u.role_id JOIN roles r ON r.id=u.role_id AND r.status='Active' WHERE u.id=? AND u.status='Active' AND rp.module_key=? LIMIT 1");
 $q->execute([(int)$_SESSION['user_id'],$module]);$value=$q->fetchColumn();
  $cache[$key]=(int)$value===1;return $cache[$key];
}
function canAccessModule(string $module): bool { return hasModulePermission($module,'view'); }
function requireModulePermission(string $module,string $action): void { if(hasModulePermission($module,$action))return; http_response_code(403); exit('Access denied. Your role does not have permission to '.$action.' in this module.'); }
function requireModuleAccess(string $module): void {
 if(in_array($module,['users','roles','settings'],true)){if(isAdministrator())return;http_response_code(403);exit('Access denied.');}
 if(canAccessModule($module))return;
 http_response_code(403);exit('Access denied. Your user role does not have permission to access this module.');
}
$requestPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)?:'';
$markerPosition=strpos($requestPath,'/modules/');
if($markerPosition!==false){$afterMarker=substr($requestPath,$markerPosition+strlen('/modules/'));$module=strtolower((string)strtok($afterMarker,'/'));if($module==='hr' && preg_match('~^hr/my\\.php$~i',$afterMarker))$module='hr_self_service';if($module==='inventory')$module='products';if($module!=='')requireModuleAccess($module);}
?>