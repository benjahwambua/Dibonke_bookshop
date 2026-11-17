<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('billing');

$from=$_GET['from']??date('Y-m-01');
$to=$_GET['to']??date('Y-m-d');
$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)?$from:date('Y-m-01');
$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)?$to:date('Y-m-d');
if($from>$to){$swap=$from;$from=$to;$to=$swap;}
$status=(string)($_GET['status']??'all');
$search=trim((string)($_GET['q']??''));
$where=["s.sale_status='Completed'","DATE(s.sale_date) BETWEEN ? AND ?"];
$params=[$from,$to];
if($status==='paid')$where[]='s.balance<=0.001';
elseif($status==='partial')$where[]='s.paid_amount>0 AND s.balance>0.001';
elseif($status==='unpaid')$where[]='s.paid_amount<=0 AND s.balance>0.001';
if($search!==''){$where[]="(s.invoice_number LIKE ? OR c.customer_name LIKE ? OR c.phone LIKE ?)";$like='%'.$search.'%';array_push($params,$like,$like,$like);}
$sqlWhere=implode(' AND ',$where);
$q=$conn->prepare("SELECT COUNT(*) invoices,COALESCE(SUM(s.total_amount),0) total,COALESCE(SUM(s.paid_amount),0) paid,COALESCE(SUM(s.balance),0) outstanding FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE ".$sqlWhere);
$q->execute($params);$summary=$q->fetch();
$q=$conn->prepare("SELECT s.id,s.invoice_number,s.sale_date,s.total_amount,s.paid_amount,s.balance,s.payment_method,COALESCE(c.customer_name,'Walk-in Customer') customer_name,c.phone FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE ".$sqlWhere." ORDER BY s.sale_date DESC,s.id DESC LIMIT 200");
$q->execute($params);$invoices=$q->fetchAll();
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.billing-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:15px 0}.card,.panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px}.card span{display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase}.card strong{display:block;margin-top:7px;font-size:21px;color:#17324d}.filters{display:flex;gap:9px;align-items:end;flex-wrap:wrap;margin:15px 0;padding:14px;background:#fff;border:1px solid #e5eaf0;border-radius:12px}.filters label{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:5px}.filters input,.filters select{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.btn{border:0;border-radius:7px;background:#174a7e;color:#fff;padding:10px 14px;font-weight:800;cursor:pointer}.table{width:100%;border-collapse:collapse;min-width:850px}.table th,.table td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.table th{background:#f3f6fa;color:#475569}.num{text-align:right!important;white-space:nowrap}.pill{display:inline-block;padding:4px 8px;border-radius:20px;font-size:10px;font-weight:800}.paid{background:#dcfce7;color:#166534}.partial{background:#fef3c7;color:#92400e}.unpaid{background:#fee2e2;color:#991b1b}.action{color:#174a7e;font-weight:800;text-decoration:none;white-space:nowrap}.muted{color:#64748b}@media(max-width:700px){.cards{grid-template-columns:1fr}}
</style>
<div class="billing-head"><div><h1>Billing &amp; Invoices</h1><p class="muted">Track POS invoices, customer payments and outstanding balances.</p></div><a class="btn" href="<?=BASE_URL?>/modules/sales/index.php">Create invoice from POS</a></div>
<section class="cards"><div class="card"><span>Invoices</span><strong><?=number_format((int)$summary['invoices'])?></strong></div><div class="card"><span>Invoice Value</span><strong><?=e($currency)?> <?=number_format((float)$summary['total'],2)?></strong></div><div class="card"><span>Outstanding Balance</span><strong><?=e($currency)?> <?=number_format((float)$summary['outstanding'],2)?></strong></div></section>
<form class="filters"><div><label>From</label><input type="date" name="from" value="<?=e($from)?>"></div><div><label>To</label><input type="date" name="to" value="<?=e($to)?>"></div><div><label>Payment Status</label><select name="status"><option value="all" <?=$status==='all'?'selected':''?>>All invoices</option><option value="paid" <?=$status==='paid'?'selected':''?>>Paid</option><option value="partial" <?=$status==='partial'?'selected':''?>>Partially paid</option><option value="unpaid" <?=$status==='unpaid'?'selected':''?>>Unpaid</option></select></div><div><label>Search</label><input name="q" value="<?=e($search)?>" placeholder="Invoice, customer or phone"></div><button class="btn">Filter</button></form>
<section class="panel"><div style="overflow:auto"><table class="table"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Payment</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if(!$invoices):?><tr><td colspan="9" class="muted">No invoices match these filters.</td></tr><?php endif;?>
<?php foreach($invoices as $invoice):$balance=(float)$invoice['balance'];$paid=(float)$invoice['paid_amount'];$label=$balance<=.001?'Paid':($paid>0?'Partially paid':'Unpaid');$class=$balance<=.001?'paid':($paid>0?'partial':'unpaid');?>
<tr><td><strong><?=e($invoice['invoice_number'])?></strong></td><td><?=e($invoice['sale_date'])?></td><td><?=e($invoice['customer_name'])?><?php if($invoice['phone']):?><br><small><?=e($invoice['phone'])?></small><?php endif;?></td><td><?=e($invoice['payment_method'])?></td><td class="num"><?=e($currency)?> <?=number_format((float)$invoice['total_amount'],2)?></td><td class="num"><?=e($currency)?> <?=number_format($paid,2)?></td><td class="num"><?=e($currency)?> <?=number_format($balance,2)?></td><td><span class="pill <?=$class?>"><?=$label?></span></td><td><a class="action" href="<?=BASE_URL?>/modules/sales/receipt.php?id=<?=(int)$invoice['id']?>">Receipt</a><?php if($balance>.001&&!empty($invoice['phone'])):?> · <a class="action" href="<?=BASE_URL?>/modules/customer_payments/index.php">Collect</a><?php endif;?></td></tr>
<?php endforeach;?>
</tbody></table></div><p class="muted" style="font-size:11px">Showing up to 200 invoices. Invoices are generated by completed POS sales; this screen does not create a second invoice or duplicate sales records.</p></section>
</main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
