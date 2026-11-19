<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/mpesa.php';
requireModuleAccess('mpesa');
$pageTitle='M-PESA Integration';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();requireModulePermission('mpesa','edit');
 $action=(string)($_POST['action']??'save');
 if($action==='register_c2b'){
  try{
   $shortcode=trim((string)($_POST['mpesa_shortcode']??s('mpesa_shortcode')));
   $confirmation=trim((string)($_POST['mpesa_c2b_confirmation_url']??s('mpesa_c2b_confirmation_url')));
   $validation=trim((string)($_POST['mpesa_c2b_validation_url']??s('mpesa_c2b_validation_url')));
   if($shortcode===''||$confirmation===''||$validation==='')throw new RuntimeException('Enter the business shortcode and both public callback URLs first.');
   foreach([$confirmation,$validation] as $url){if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true))throw new RuntimeException('Callback URLs must be valid absolute HTTP/HTTPS URLs.');if(stripos($url,'mpesa')!==false)throw new RuntimeException('Safaricom callback URLs should not contain the word M-PESA. Use the supplied /callbacks/c2b_ paths.');if(mpesaSetting('mpesa_environment','sandbox')==='live'&&parse_url($url,PHP_URL_SCHEME)!=='https')throw new RuntimeException('Production callback URLs must use HTTPS.');}
   $token=mpesaAccessToken();$payload=['ShortCode'=>$shortcode,'ResponseType'=>'Completed','ConfirmationURL'=>$confirmation,'ValidationURL'=>$validation];
   $ch=curl_init(mpesaBaseUrl().'/mpesa/c2b/v2/registerurl');curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);$body=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
   if($body===false||$code>=400)throw new RuntimeException('C2B URL registration failed: '.($err?:$body));
   $response=json_decode($body,true);$save=$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
   foreach(['mpesa_c2b_confirmation_url'=>$confirmation,'mpesa_c2b_validation_url'=>$validation,'mpesa_c2b_registration_response'=>json_encode($response)] as $key=>$value)$save->execute([$key,$value]);
   $_SESSION['mpesa_flash']='C2B confirmation and validation URLs registered. Verify the shortcode and test a sandbox notification before relying on automatic till reconciliation.';
  }catch(Throwable $e){$_SESSION['mpesa_flash']='C2B registration failed: '.$e->getMessage();}
  header('Location:index.php');exit;
 }
 $keys=['mpesa_environment','mpesa_shortcode','mpesa_callback_url','mpesa_account_reference','mpesa_c2b_confirmation_url','mpesa_c2b_validation_url'];
 foreach($keys as $k){$v=trim((string)($_POST[$k]??''));$st=$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$st->execute([$k,$v]);}
 foreach(['mpesa_passkey','mpesa_consumer_key','mpesa_consumer_secret'] as $k){$v=trim((string)($_POST[$k]??''));if($v!==''){$st=$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");$st->execute([$k,$v]);}}
 $_SESSION['mpesa_flash']='M-PESA settings saved.';header('Location:index.php');exit;
}
function s(string $k):string{global $conn;$q=$conn->prepare("SELECT setting_value FROM settings WHERE setting_key=?");$q->execute([$k]);return (string)($q->fetchColumn()??'');}
$flash=$_SESSION['mpesa_flash']??null;unset($_SESSION['mpesa_flash']);
$tx=$conn->query("SELECT * FROM mpesa_transactions ORDER BY id DESC LIMIT 30")->fetchAll();
$unallocatedC2B=(int)$conn->query("SELECT COUNT(*) FROM mpesa_transactions WHERE transaction_type='C2B' AND result_status='Success' AND reference_type IS NULL")->fetchColumn();

require_once __DIR__ . '/../../includes/header.php';require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content"><div style="max-width:1200px;margin:auto"><div class="head"><div><h1>M-PESA Integration</h1><p>Central M-PESA configuration and transaction monitoring for sales, customer payments and future supplier/expense payments.</p></div></div>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<div class="card"><h2>Daraja Configuration</h2><p class="muted">Use sandbox while testing. Keep production credentials private and use an HTTPS callback URL.</p><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><div class="grid">
<label>Environment<select name="mpesa_environment"><option value="sandbox" <?=s('mpesa_environment')==='sandbox'?'selected':''?>>Sandbox</option><option value="live" <?=s('mpesa_environment')==='live'?'selected':''?>>Live</option></select></label>
<label>Shortcode<input name="mpesa_shortcode" value="<?=e(s('mpesa_shortcode'))?>"></label>
<label>Passkey<input type="password" name="mpesa_passkey" value="" autocomplete="new-password" placeholder="Saved — leave blank to keep current value"></label>
<label>Consumer Key<input type="password" name="mpesa_consumer_key" value="" autocomplete="new-password" placeholder="Saved — leave blank to keep current value"></label>
<label>Consumer Secret<input type="password" name="mpesa_consumer_secret" value="" autocomplete="new-password" placeholder="Saved — leave blank to keep current value"></label>
<label>Account Reference<input name="mpesa_account_reference" value="<?=e(s('mpesa_account_reference')?:'STALKSTABLE')?>"></label>
<label class="full">STK callback URL<input name="mpesa_callback_url" value="<?=e(s('mpesa_callback_url'))?>" placeholder="https://your-domain.com/dibonke_bookshop/modules/mpesa/callback.php"></label>
<label class="full">C2B confirmation URL<input name="mpesa_c2b_confirmation_url" value="<?=e(s('mpesa_c2b_confirmation_url'))?>" placeholder="https://your-domain.com/dibonke_bookshop/callbacks/c2b_confirmation.php"></label>
<label class="full">C2B validation URL<input name="mpesa_c2b_validation_url" value="<?=e(s('mpesa_c2b_validation_url'))?>" placeholder="https://your-domain.com/dibonke_bookshop/callbacks/c2b_validation.php"></label>
</div><button class="btn primary" name="action" value="save">Save M-PESA Settings</button> <button class="btn" name="action" value="register_c2b">Register Till / Paybill Callbacks</button></form><div class="mpesa-note"><span class="note-icon">i</span><span><strong>Callback requirement:</strong> Use a public URL. HTTPS is required for live payments; localhost is not supported.</span></div></div>
<div class="card c2b-summary"><div><h2>Direct Till / Paybill Payments</h2><p class="muted">Successful payments received directly to your shortcode.</p></div><div class="c2b-count"><strong><?=number_format($unallocatedC2B)?></strong><span>Awaiting allocation</span></div><p class="c2b-help">Matched invoice references are allocated automatically. Unmatched payments remain available for reconciliation.</p></div>
<div class="card"><h2>Recent M-PESA Transactions</h2><div class="table-wrap"><table><thead><tr><th>Date</th><th>Type</th><th>Phone</th><th>Amount</th><th>Receipt / Tx ID</th><th>Invoice reference</th><th>Allocation</th><th>Status</th></tr></thead><tbody><?php foreach($tx as $t):?><tr><td><?=e($t['transaction_date']?:$t['created_at'])?></td><td><?=e($t['transaction_type'])?></td><td><?=e($t['phone_number']?:'—')?></td><td>KES <?=number_format((float)$t['amount'],2)?></td><td><?=e($t['transaction_reference']?:$t['checkout_request_id']?:'—')?></td><td><?=e($t['account_reference']?:'—')?></td><td><?=!empty($t['reference_id'])?'Allocated to sale #'.(int)$t['reference_id']:($t['transaction_type']==='C2B'?'Unallocated':'—')?></td><td><?=e($t['result_status'])?></td></tr><?php endforeach;?></tbody></table></div></div></div></main>
<style>.head{margin-bottom:20px}.muted{color:#64748b}.card{background:#fff;padding:22px;border-radius:14px;box-shadow:0 1px 5px #0001;margin-bottom:18px}.mpesa-note{display:flex;align-items:center;gap:9px;margin-top:14px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;color:#475569;font-size:12px}.note-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 19px;width:19px;height:19px;border-radius:50%;background:#e2e8f0;color:#334155;font-weight:800}.c2b-summary{display:grid;grid-template-columns:1fr auto;align-items:center;gap:8px 18px;padding:16px 20px}.c2b-summary h2{margin:0 0 4px;font-size:17px}.c2b-summary p{margin:0;font-size:12px}.c2b-count{display:flex;align-items:center;gap:9px;padding:8px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px}.c2b-count strong{font-size:22px;line-height:1;color:#0f172a}.c2b-count span{font-size:11px;color:#64748b}.c2b-help{grid-column:1/-1;padding-top:8px;border-top:1px solid #eef2f7;color:#64748b}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin:18px 0}.grid label{font-size:12px;font-weight:700}.grid input,.grid select{display:block;width:100%;box-sizing:border-box;margin-top:6px;padding:11px;border:1px solid #dbe3ec;border-radius:8px}.full{grid-column:1/-1}.btn{padding:11px 16px;border:0;border-radius:8px;font-weight:700;cursor:pointer}.primary{background:#004a99;color:#fff}.flash{padding:12px;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:18px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{padding:11px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:13px}th{background:#f8fafc}@media(max-width:850px){.grid{grid-template-columns:1fr}.full{grid-column:auto}.c2b-summary{grid-template-columns:1fr}.c2b-count{justify-self:start}.c2b-help{grid-column:auto}}</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>