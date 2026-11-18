<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('etims');
requireModulePermission('etims','edit');
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: index.php');exit;}
verify_csrf();
try{
 $pin=strtoupper(trim((string)($_POST['etims_taxpayer_pin']??'')));
 if($pin!==''&&!preg_match('/^[A-Z][0-9]{9}[A-Z]$/',$pin))throw new RuntimeException('Enter a valid KRA PIN in the format A123456789X, or leave it blank until issued.');
 $type=(string)($_POST['etims_integration_type']??'OSCU');if(!in_array($type,['OSCU','VSCU','Third Party'],true))throw new RuntimeException('Select a valid integration route.');
 $status=(string)($_POST['etims_registration_status']??'Not Started');if(!in_array($status,['Not Started','Registered','Onboarding','Sandbox Testing','Approved'],true))throw new RuntimeException('Select a valid onboarding status.');
 $environment=(string)($_POST['etims_environment']??'sandbox');if(!in_array($environment,['sandbox','production'],true))throw new RuntimeException('Select a valid environment.');
 $data=['etims_taxpayer_pin'=>$pin,'etims_integration_type'=>$type,'etims_branch_identifier'=>trim((string)($_POST['etims_branch_identifier']??'')),'etims_registration_status'=>$status,'etims_onboarding_reference'=>trim((string)($_POST['etims_onboarding_reference']??'')),'etims_approval_reference'=>trim((string)($_POST['etims_approval_reference']??'')),'etims_environment'=>$environment,'etims_enabled'=>'0'];
 $q=$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
 foreach($data as $k=>$v)$q->execute([$k,$v]);
 $_SESSION['etims_setup_flash']='eTIMS profile saved. Live KRA transmission remains disabled.';
}catch(Throwable $e){$_SESSION['etims_setup_flash']='Could not save eTIMS profile: '.$e->getMessage();}
header('Location: index.php');exit;
