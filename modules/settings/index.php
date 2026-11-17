<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
if(empty($_SESSION['is_super'])){http_response_code(403);exit('Access denied.');}
$allowed=['company_name','company_type','currency','country','low_stock_threshold','invoice_prefix','receipt_prefix','purchase_prefix','delivery_prefix','expense_prefix','timezone','vat_rate','vat_mode'];
$placeholders=implode(',',array_fill(0,count($allowed),'?'));
$q=$conn->prepare("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ($placeholders) ORDER BY setting_key");
$q->execute($allowed);$settings=[];foreach($q->fetchAll() as $s)$settings[$s['setting_key']]=$s['setting_value'];
$flash=$_SESSION['settings_flash']??null;unset($_SESSION['settings_flash']);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><style>
.settings-panel{background:#fff;padding:22px;border-radius:12px;border:1px solid #e5eaf0;max-width:900px}
.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.field label{display:block;font-size:12px;font-weight:800;margin-bottom:6px;color:#34465a}
.field input,.field select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #dbe3ec;border-radius:8px;background:#fff}
.btn{background:#174a7e;color:#fff;border:0;padding:11px 16px;border-radius:7px;font-weight:800;cursor:pointer}
.flash{padding:12px;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:12px}
.note{margin:12px 0 18px;color:#64748b;font-size:12px}
@media(max-width:650px){.settings-grid{grid-template-columns:1fr}}
</style>
<h1>System Settings</h1><p>Configure company information, currency and document numbering.</p>
<p class="note">Payment gateway credentials are managed separately under M-PESA. VAT defaults to 16%. Choose whether selling prices include VAT or VAT is added at checkout.</p>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<div class="settings-panel"><form method="post" action="store.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="settings-grid">
<?php foreach($allowed as $key):$value=$settings[$key]??'';?>
<div class="field"><label><?=e(ucwords(str_replace('_',' ',$key)))?></label>
<?php if($key==='vat_mode'):?>
<select name="settings[vat_mode]"><option value="exclusive" <?=($value?:'inclusive')==='exclusive'?'selected':''?>>Add VAT to selling prices</option><option value="inclusive" <?=($value?:'inclusive')==='inclusive'?'selected':''?>>Selling prices already include VAT</option></select>
<?php else:?>
<input name="settings[<?=e($key)?>]" value="<?=e($value!==''?$value:($key==='vat_rate'?'16':''))?>" <?=in_array($key,['company_name','currency'],true)?'required':''?> <?= $key==='vat_rate'?'type="number" min="0" max="100" step="0.01"':''?>>
<?php endif;?></div>
<?php endforeach;?>
</div><p><button class="btn">Save Settings</button></p></form></div></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
