<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../config/db.php';
if(empty($_SESSION['is_super'])){http_response_code(403);exit('Access denied.');}if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: index.php');exit;}verify_csrf();
try{$allowed=['company_name','company_type','currency','country','low_stock_threshold','timezone','vat_rate','vat_mode'];$conn->beginTransaction();$s=$conn->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$submitted=(array)($_POST['settings']??[]);
$rate=filter_var($submitted['vat_rate']??16,FILTER_VALIDATE_FLOAT);
if($rate===false||$rate<0||$rate>100)throw new RuntimeException('VAT rate must be between 0 and 100.');
$mode=(string)($submitted['vat_mode']??'inclusive');
if(!in_array($mode,['exclusive','inclusive'],true))throw new RuntimeException('Invalid VAT calculation mode.');
$submitted['vat_rate']=(string)$rate;$submitted['vat_mode']=$mode;
foreach($submitted as $k=>$v)if(in_array($k,$allowed,true))$s->execute([$k,trim((string)$v)]);$conn->commit();$_SESSION['settings_flash']='Settings saved successfully.';}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$_SESSION['settings_flash']='Settings failed: '.$e->getMessage();}header('Location: index.php');exit;