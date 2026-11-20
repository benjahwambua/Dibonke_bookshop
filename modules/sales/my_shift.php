<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('sales');
$uid=(int)($_SESSION['user_id']??0);
$canOpen=hasModulePermission('cash_management','create');
$canClose=hasModulePermission('cash_management','edit');
$required=['cash_shifts','cash_shift_guard','cash_shift_movements','audit_events','sales_return_payments'];
$tables=array_flip($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
$missing=array_values(array_filter($required,static fn($t)=>!isset($tables[$t])));
if(isset($tables['expenses'])){$expenseShiftColumn=$conn->query("SHOW COLUMNS FROM expenses LIKE 'cash_shift_id'")->fetch();if(!$expenseShiftColumn)$missing[]='expenses.cash_shift_id';}
if(isset($tables['supplier_payments'])){$supplierShiftColumn=$conn->query("SHOW COLUMNS FROM supplier_payments LIKE 'cash_shift_id'")->fetch();if(!$supplierShiftColumn)$missing[]='supplier_payments.cash_shift_id';}

$flash=$_SESSION['my_shift_flash']??null;unset($_SESSION['my_shift_flash']);
function myShiftRedirect(string $message):void{$_SESSION['my_shift_flash']=$message;header('Location: my_shift.php');exit;}
function myShiftAudit(PDO $db,string $action,string $description,?int $id=null):void{
 $q=$db->prepare("INSERT INTO audit_events(user_id,action,module_key,record_type,record_id,description,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)");
 $q->execute([(int)($_SESSION['user_id']??0),$action,'cash_management','cash_shift',$id,$description,$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
}
function myShiftSummary(PDO $db,array $shift):array{
 $uid=(int)$shift['user_id'];$id=(int)$shift['id'];$start=(string)$shift['opened_at'];$end=date('Y-m-d H:i:s');
 $sum=static function(string $sql,array $params)use($db):float{$q=$db->prepare($sql);$q->execute($params);return (float)$q->fetchColumn();};
 $sales=$sum("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE user_id=? AND payment_method='Cash' AND sale_status='Completed' AND sale_date BETWEEN ? AND ?",[$uid,$start,$end]);
 $collections=$sum("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE user_id=? AND sale_id IS NULL AND payment_method='Cash' AND payment_date BETWEEN ? AND ?",[$uid,$start,$end]);
 $supplier=$sum("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE cash_shift_id=? AND payment_method='Cash'",[$id]);
 $expenses=$sum("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE cash_shift_id=? AND payment_method='Cash'",[$id]);
 $refunds=$sum("SELECT COALESCE(SUM(amount),0) FROM sales_return_payments WHERE paid_by=? AND payment_method='Cash' AND paid_at BETWEEN ? AND ?",[$uid,$start,$end]);
 $manual=$sum("SELECT COALESCE(SUM(CASE WHEN movement_type='Cash In' THEN amount ELSE -amount END),0) FROM cash_shift_movements WHERE shift_id=?",[$id]);
 return ['cash_sales'=>$sales,'cash_collections'=>$collections,'supplier_payments'=>$supplier,'cash_expenses'=>$expenses,'cash_refunds'=>$refunds,'manual_net'=>$manual,'expected'=>round((float)$shift['opening_float']+$sales+$collections-$supplier-$expenses-$refunds+$manual,2)];
}
if(!$missing&&$_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'');
 try{
  if($action==='open'){
   requireModulePermission('cash_management','create');
   $float=filter_var($_POST['opening_float']??0,FILTER_VALIDATE_FLOAT);if($float===false||$float<0)throw new RuntimeException('Enter a valid opening float.');
   $conn->beginTransaction();$conn->query("SELECT id FROM cash_shift_guard WHERE id=1 FOR UPDATE")->fetchColumn();
   $q=$conn->prepare("SELECT id FROM cash_shifts WHERE user_id=? AND status='Open' LIMIT 1");$q->execute([$uid]);if($q->fetchColumn())throw new RuntimeException('You already have an open shift.');
   $q=$conn->prepare("INSERT INTO cash_shifts(shift_number,user_id,opened_at,opening_float) VALUES(?, ?, NOW(), ?)");$q->execute(['TMP-'.strtoupper(bin2hex(random_bytes(8))),$uid,$float]);
   $id=(int)$conn->lastInsertId();$number='CS-'.date('ymd').'-'.str_pad((string)$id,5,'0',STR_PAD_LEFT);
   $conn->prepare("UPDATE cash_shifts SET shift_number=? WHERE id=?")->execute([$number,$id]);myShiftAudit($conn,'Open Shift','Opened '.$number.' with float KES '.number_format((float)$float,2),$id);$conn->commit();myShiftRedirect('Your shift '.$number.' is now open.');
  }
  if($action==='close'){
   requireModulePermission('cash_management','edit');$counted=filter_var($_POST['counted_cash']??null,FILTER_VALIDATE_FLOAT);if($counted===false||$counted<0)throw new RuntimeException('Enter the physical cash counted.');
   $conn->beginTransaction();$q=$conn->prepare("SELECT * FROM cash_shifts WHERE id=? AND user_id=? AND status='Open' FOR UPDATE");$q->execute([(int)($_POST['shift_id']??0),$uid]);$shift=$q->fetch();if(!$shift)throw new RuntimeException('Your open shift could not be found.');
   $summary=myShiftSummary($conn,$shift);$variance=round((float)$counted-$summary['expected'],2);$notes=trim((string)($_POST['closing_notes']??''));
   $q=$conn->prepare("UPDATE cash_shifts SET closed_at=NOW(),expected_cash=?,counted_cash=?,variance=?,closing_notes=?,status='Closed' WHERE id=?");$q->execute([$summary['expected'],$counted,$variance,$notes?:null,(int)$shift['id']]);
   myShiftAudit($conn,'Close Shift','Closed '.$shift['shift_number'].'; expected '.number_format($summary['expected'],2).', counted '.number_format((float)$counted,2).', variance '.number_format($variance,2),(int)$shift['id']);$conn->commit();myShiftRedirect('Shift closed. Expected KES '.number_format($summary['expected'],2).'; variance KES '.number_format($variance,2).'.');
  }
  if($action==='movement'){
   requireModulePermission('cash_management','create');$type=(string)($_POST['movement_type']??'');$amount=filter_var($_POST['amount']??null,FILTER_VALIDATE_FLOAT);$description=trim((string)($_POST['description']??''));$reference=trim((string)($_POST['reference']??''));
   if(!in_array($type,['Cash In','Cash Out'],true)||$amount===false||$amount<=0||$description==='')throw new RuntimeException('Enter a valid movement, amount and description.');
   $conn->beginTransaction();$q=$conn->prepare("SELECT id FROM cash_shifts WHERE id=? AND user_id=? AND status='Open' FOR UPDATE");$q->execute([(int)($_POST['shift_id']??0),$uid]);$shiftId=(int)$q->fetchColumn();if(!$shiftId)throw new RuntimeException('Your open shift could not be found.');
   $q=$conn->prepare("INSERT INTO cash_shift_movements(shift_id,user_id,movement_type,amount,reference,description,created_at) VALUES(?,?,?,?,?,?,NOW())");$q->execute([$shiftId,$uid,$type,$amount,$reference?:null,$description]);myShiftAudit($conn,$type,$description.' KES '.number_format((float)$amount,2),$shiftId);$conn->commit();myShiftRedirect($type.' recorded.');
  }
  throw new RuntimeException('Unknown shift action.');
 }catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();myShiftRedirect('Action failed: '.$e->getMessage());}
}
$open=null;$summary=null;$history=[];
if(!$missing){$q=$conn->prepare("SELECT * FROM cash_shifts WHERE user_id=? AND status='Open' ORDER BY id DESC LIMIT 1");$q->execute([$uid]);$open=$q->fetch()?:null;if($open)$summary=myShiftSummary($conn,$open);$q=$conn->prepare("SELECT * FROM cash_shifts WHERE user_id=? ORDER BY id DESC LIMIT 12");$q->execute([$uid]);$history=$q->fetchAll();}
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.ms-wrap{max-width:1100px;margin:auto}.ms-head{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.ms-panel{background:#fff;border:1px solid #e3eaf1;border-radius:13px;padding:20px;margin:16px 0}.ms-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.ms-stat{padding:15px;background:#f5f8fc;border-radius:10px}.ms-stat span{display:block;color:#64748b;font-size:11px;text-transform:uppercase;font-weight:800}.ms-stat strong{display:block;color:#17324d;font-size:22px;margin-top:5px}.ms-form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.ms-form label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:800;color:#475569}.ms-form input,.ms-form select{padding:11px;border:1px solid #cbd5e1;border-radius:8px;min-width:180px}.ms-btn{border:0;border-radius:8px;padding:11px 16px;background:#174a7e;color:white;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block}.ms-btn.secondary{background:#64748b}.ms-note{color:#64748b;font-size:12px}.ms-table{width:100%;border-collapse:collapse}.ms-table th,.ms-table td{text-align:left;padding:10px;border-bottom:1px solid #edf1f5;font-size:12px}.ms-table th{background:#f4f7fa}@media(max-width:650px){.ms-grid{grid-template-columns:1fr}.ms-table{min-width:620px}.ms-panel{overflow:auto}}
</style>
<div class="ms-wrap">
<div class="ms-head"><div><h1>My Cashier Shift</h1><p class="ms-note">Open, monitor and close your own cash drawer shift.</p></div><a class="ms-btn secondary" href="<?=BASE_URL?>/modules/sales/index.php">← Return to POS</a></div>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<?php if($missing):?><section class="ms-panel"><h2>Cash shift setup required</h2><p>Apply the Launch Operations migration and its completion migration before using cashier shifts.</p><p class="ms-note">Missing: <?=e(implode(', ',$missing))?></p></section>
<?php elseif(!$canOpen&&!$canClose):?><section class="ms-panel"><h2>Access not configured</h2><p>Your role does not currently have permission to open or close a cashier shift. Ask an Administrator to grant Cash Management access.</p></section>
<?php elseif(!$open):?><section class="ms-panel"><h2>No open shift</h2><p>Open your shift before accepting cash sales. Enter the cash float placed in your drawer at the start of the shift. If you have no float, enter 0.</p><?php if($canOpen):?><form method="post" class="ms-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="open"><label>Opening float (KES)<input type="number" name="opening_float" min="0" step=".01" value="0" required></label><button class="ms-btn">Open my shift</button></form><?php endif;?></section>
<?php else:?><section class="ms-panel"><div class="ms-head"><div><span style="color:#047857;font-weight:800;font-size:12px">● SHIFT OPEN</span><h2><?=e($open['shift_number'])?></h2><p class="ms-note">Started <?=e($open['opened_at'])?> · Opening float KES <?=number_format((float)$open['opening_float'],2)?></p></div><a class="ms-btn secondary" href="<?=BASE_URL?>/modules/sales/index.php">Continue selling</a></div>
<div class="ms-grid">
<div class="ms-stat"><span>Expected cash now</span><strong>KES <?=number_format($summary['expected'],2)?></strong></div>
<div class="ms-stat"><span>Cash sales</span><strong>KES <?=number_format($summary['cash_sales'],2)?></strong></div>
<div class="ms-stat"><span>Customer collections</span><strong>KES <?=number_format($summary['cash_collections'],2)?></strong></div>
<div class="ms-stat"><span>Supplier payments</span><strong>KES <?=number_format($summary['supplier_payments'],2)?></strong></div>
<div class="ms-stat"><span>Cash expenses</span><strong>KES <?=number_format($summary['cash_expenses'],2)?></strong></div>
<div class="ms-stat"><span>Cash refunds</span><strong>KES <?=number_format($summary['cash_refunds'],2)?></strong></div>
</div><p class="ms-note">Manual cash movements net: KES <?=number_format($summary['manual_net'],2)?>. Mixed POS tender splits are not currently itemized by the POS and therefore cannot be included automatically.</p>
<?php if($canOpen):?><h3>Record cash movement</h3><form method="post" class="ms-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="movement"><input type="hidden" name="shift_id" value="<?=(int)$open['id']?>"><label>Movement<select name="movement_type"><option>Cash In</option><option>Cash Out</option></select></label><label>Amount (KES)<input type="number" name="amount" min=".01" step=".01" required></label><label>Reference (optional)<input name="reference" maxlength="120"></label><label>Description<input name="description" maxlength="500" required></label><button class="ms-btn">Record movement</button></form><?php endif;?>
<?php if($canClose):?><hr style="border:0;border-top:1px solid #e5eaf0;margin:20px 0"><h3>Close shift and reconcile</h3><p class="ms-note">Count the physical cash in your drawer, including the opening float. Expected cash is KES <?=number_format($summary['expected'],2)?>.</p><form method="post" class="ms-form" onsubmit="return confirm('Close this shift? You will not be able to record further cash sales against it.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="close"><input type="hidden" name="shift_id" value="<?=(int)$open['id']?>"><label>Physical cash counted (KES)<input type="number" name="counted_cash" min="0" step=".01" required></label><label>Closing notes (optional)<input name="closing_notes" maxlength="1000"></label><button class="ms-btn">Close shift</button></form><?php endif;?></section><?php endif;?>
<section class="ms-panel"><h2>My recent shifts</h2><table class="ms-table"><thead><tr><th>Shift</th><th>Opened</th><th>Closed</th><th>Expected</th><th>Counted</th><th>Variance</th><th>Status</th></tr></thead><tbody><?php foreach($history as $h):?><tr><td><?=e($h['shift_number'])?></td><td><?=e($h['opened_at'])?></td><td><?=e($h['closed_at']??'—')?></td><td>KES <?=number_format((float)$h['expected_cash'],2)?></td><td><?=$h['counted_cash']===null?'—':'KES '.number_format((float)$h['counted_cash'],2)?></td><td><?=$h['variance']===null?'—':'KES '.number_format((float)$h['variance'],2)?></td><td><?=e($h['status'])?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="7">No shifts recorded yet.</td></tr><?php endif;?></tbody></table></section>
</div></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
