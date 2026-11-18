<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('etims');
$canEdit=hasModulePermission('etims','edit');
function etimsSetting(PDO $db,string $key,string $default=''):string{$q=$db->prepare("SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1");$q->execute([$key]);$v=$q->fetchColumn();return $v===false?$default:(string)$v;}
$values=[
 'etims_taxpayer_pin'=>etimsSetting($conn,'etims_taxpayer_pin'),
 'etims_integration_type'=>etimsSetting($conn,'etims_integration_type','OSCU'),
 'etims_branch_identifier'=>etimsSetting($conn,'etims_branch_identifier'),
 'etims_registration_status'=>etimsSetting($conn,'etims_registration_status','Not Started'),
 'etims_onboarding_reference'=>etimsSetting($conn,'etims_onboarding_reference'),
 'etims_approval_reference'=>etimsSetting($conn,'etims_approval_reference'),
 'etims_environment'=>etimsSetting($conn,'etims_environment','sandbox')
];
$flash=$_SESSION['etims_setup_flash']??null;unset($_SESSION['etims_setup_flash']);
$hasQueueTable=(bool)$conn->query("SHOW TABLES LIKE 'etims_documents'")->fetchColumn();
$queueCount=$hasQueueTable?(int)$conn->query("SELECT COUNT(*) FROM etims_documents")->fetchColumn():0;
$queuedCount=$hasQueueTable?(int)$conn->query("SELECT COUNT(*) FROM etims_documents WHERE status='Queued'")->fetchColumn():0;
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.et-wrap{max-width:1100px;margin:auto}.et-panel{background:#fff;border:1px solid #e3eaf1;border-radius:13px;padding:20px;margin:16px 0}.et-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.et-field label{display:block;font-size:12px;font-weight:800;color:#475569;margin-bottom:6px}.et-field input,.et-field select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #cbd5e1;border-radius:8px}.et-note{color:#64748b;font-size:12px;line-height:1.6}.et-btn{display:inline-block;padding:11px 15px;border-radius:8px;background:#174a7e;color:#fff;text-decoration:none;border:0;font-weight:800;cursor:pointer}.et-status{display:inline-block;padding:5px 9px;border-radius:20px;background:#fff4d6;color:#805b0b;font-size:11px;font-weight:800}@media(max-width:650px){.et-grid{grid-template-columns:1fr}}
</style>
<div class="et-wrap">
<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap"><div><h1>eTIMS Registration &amp; Integration Setup</h1><p class="et-note">Record Dibonke's KRA onboarding details and prepare for the approved system-to-system integration.</p></div><a class="et-btn" href="<?=BASE_URL?>/modules/launch_controls/index.php?tab=etims">Open invoice queue</a></div>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<section class="et-panel"><span class="et-status">Live KRA transmission disabled</span><h2>Registration and integration profile</h2><p class="et-note">Register the Dibonke taxpayer on KRA eTIMS first. These fields are for recording onboarding information only. Do not paste passwords, API secrets, private keys or certificates here. Dibonke will not send invoices to KRA or mark itself production-connected by saving this form.</p>
<form method="post" action="store.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="et-grid">
<div class="et-field"><label>KRA PIN</label><input name="etims_taxpayer_pin" maxlength="11" value="<?=e($values['etims_taxpayer_pin'])?>" placeholder="e.g. A123456789X"></div>
<div class="et-field"><label>Integration route</label><select name="etims_integration_type"><option value="OSCU" <?=$values['etims_integration_type']==='OSCU'?'selected':''?>>OSCU — always-online sales system</option><option value="VSCU" <?=$values['etims_integration_type']==='VSCU'?'selected':''?>>VSCU — virtual/bulk invoicing route</option><option value="Third Party" <?=$values['etims_integration_type']==='Third Party'?'selected':''?>>KRA-certified third-party integrator</option></select></div>
<div class="et-field"><label>Branch / outlet identifier (if issued)</label><input name="etims_branch_identifier" maxlength="100" value="<?=e($values['etims_branch_identifier'])?>"></div>
<div class="et-field"><label>Registration / onboarding status</label><select name="etims_registration_status"><?php foreach(['Not Started','Registered','Onboarding','Sandbox Testing','Approved'] as $v):?><option <?=$values['etims_registration_status']===$v?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></div>
<div class="et-field"><label>KRA onboarding reference (if issued)</label><input name="etims_onboarding_reference" maxlength="150" value="<?=e($values['etims_onboarding_reference'])?>"></div>
<div class="et-field"><label>KRA approval / certification reference (if issued)</label><input name="etims_approval_reference" maxlength="150" value="<?=e($values['etims_approval_reference'])?>"></div>
<div class="et-field"><label>Development environment</label><select name="etims_environment"><option value="sandbox" <?=$values['etims_environment']==='sandbox'?'selected':''?>>Sandbox / testing</option><option value="production" <?=$values['etims_environment']==='production'?'selected':''?>>Production profile (transmission still disabled)</option></select></div>
</div>
<?php if($canEdit):?><p style="margin-top:18px"><button class="et-btn">Save eTIMS profile</button></p><?php else:?><p class="et-note">Your role has read-only access to this profile. Ask an Administrator to update it.</p><?php endif;?>
</form></section>
<section class="et-panel"><h2>Recommended onboarding sequence</h2><ol><li>Register the Dibonke taxpayer using the official <a href="https://etims.kra.go.ke/" target="_blank" rel="noopener">KRA eTIMS portal</a>.</li><li>Confirm the right integration route with KRA: OSCU, VSCU, or a KRA-certified third-party solution.</li><li>Obtain the current technical specifications, sandbox access and the required device / integrator onboarding details from KRA.</li><li>Complete sandbox development and KRA testing / certification before requesting production activation.</li><li>Only after credentials and approval are securely provisioned should the system-to-system sender be implemented and enabled.</li></ol><p class="et-note">A taxpayer registration PIN is not the same as API credentials or a production integration approval. The Dibonke invoice number remains its internal reference; KRA control-unit invoice identifiers and verification data will be stored separately when the official integration is built.</p></section>
<section class="et-panel"><h2>Current Dibonke queue</h2><p><strong><?=number_format($queueCount)?></strong> prepared invoices · <strong><?=number_format($queuedCount)?></strong> awaiting integration processing.</p><?php if(!$hasQueueTable):?><p class="et-note">The eTIMS queue table is not installed yet. Apply the Launch Operations database migration to enable invoice preparation.</p><?php endif;?><p class="et-note">Queue records are internal invoice snapshots only. They are not transmitted KRA invoices and do not create KRA acknowledgements, QR codes or control-unit numbers.</p></section>
</div></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
