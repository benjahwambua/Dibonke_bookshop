<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('finance');
$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-d');
$valid='/^\d{4}-\d{2}-\d{2}$/';$from=preg_match($valid,$from)?$from:date('Y-m-01');$to=preg_match($valid,$to)?$to:date('Y-m-d');if($from>$to){[$from,$to]=[$to,$from];}
$sql="SELECT transaction_date,transaction_type,reference,party,payment_method,direction,amount FROM (
 SELECT s.sale_date transaction_date,CONVERT('POS Receipt' USING utf8mb4) COLLATE utf8mb4_unicode_ci transaction_type,CONVERT(s.invoice_number USING utf8mb4) COLLATE utf8mb4_unicode_ci reference,CONVERT(COALESCE(c.customer_name,'Walk-in Customer') USING utf8mb4) COLLATE utf8mb4_unicode_ci party,CONVERT(s.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci payment_method,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci direction,s.paid_amount amount
 FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.sale_status='Completed' AND s.paid_amount>0 AND DATE(s.sale_date) BETWEEN ? AND ?
 UNION ALL
 SELECT cp.payment_date,CONVERT('Customer Collection' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(cp.receipt_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(c.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(cp.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci,cp.amount
 FROM customer_payments cp JOIN customers c ON c.id=cp.customer_id WHERE cp.sale_id IS NULL AND DATE(cp.payment_date) BETWEEN ? AND ?
 UNION ALL
 SELECT sp.payment_date,CONVERT('Supplier Payment' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(sp.receipt_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(s.supplier_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(sp.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('OUT' USING utf8mb4) COLLATE utf8mb4_unicode_ci,sp.amount
 FROM supplier_payments sp JOIN suppliers s ON s.id=sp.supplier_id WHERE DATE(sp.payment_date) BETWEEN ? AND ?
 UNION ALL
 SELECT e.expense_date,CONVERT('Operating Expense' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.expense_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.expense_title USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('OUT' USING utf8mb4) COLLATE utf8mb4_unicode_ci,e.amount
 FROM expenses e WHERE e.expense_date BETWEEN ? AND ?
) ledger_rows ORDER BY transaction_date ASC,reference ASC";
$q=$conn->prepare($sql);$q->execute([$from,$to,$from,$to,$from,$to,$from,$to]);$rows=$q->fetchAll();
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
$in=0.0;$out=0.0;$balance=0.0;foreach($rows as &$row){$amount=(float)$row['amount'];if($row['direction']==='IN'){$in+=$amount;$balance+=$amount;}else{$out+=$amount;$balance-=$amount;}$row['running']=$balance;}unset($row);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.finance-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 20px;padding:8px;background:#eef2f7;border-radius:11px}.finance-tabs a{padding:10px 14px;border-radius:8px;text-decoration:none;color:#40546a;font-size:12px;font-weight:800}.finance-tabs a.active{background:#17324d;color:#fff}
.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:15px 0}.metric,.panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px}.metric span{display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase}.metric strong{display:block;margin-top:7px;font-size:21px;color:#17324d}.filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:14px 0;padding:14px;background:#fff;border:1px solid #e5eaf0;border-radius:10px}.filter label{display:block;font-size:11px;font-weight:800;margin-bottom:5px}.filter input{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.btn{background:#174a7e;color:#fff;border:0;padding:10px 14px;border-radius:7px;font-weight:800}.table{width:100%;border-collapse:collapse;min-width:850px}.table th,.table td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.table th{background:#f3f6fa;color:#475569}.num{text-align:right!important;white-space:nowrap}.in{color:#047857;font-weight:800}.out{color:#b42318;font-weight:800}.muted{color:#64748b}@media(max-width:700px){.metrics{grid-template-columns:1fr}}
</style>
<h1>Finance · Cashbook Ledger</h1><p class="muted">Chronological record of recorded customer receipts, supplier payments and operating expenses.</p>
<nav class="finance-tabs"><a href="<?=BASE_URL?>/modules/finance/index.php">Overview</a><a class="active" href="<?=BASE_URL?>/modules/finance/ledger.php">Ledger</a><a href="<?=BASE_URL?>/modules/finance/control.php">Financial Control</a><a href="<?=BASE_URL?>/modules/expenses/index.php">Expenses</a></nav>
<form class="filter"><div><label>From</label><input type="date" name="from" value="<?=e($from)?>"></div><div><label>To</label><input type="date" name="to" value="<?=e($to)?>"></div><button class="btn">Apply Period</button></form>
<section class="metrics"><div class="metric"><span>Money In</span><strong class="in"><?=e($currency)?> <?=number_format($in,2)?></strong></div><div class="metric"><span>Money Out</span><strong class="out"><?=e($currency)?> <?=number_format($out,2)?></strong></div><div class="metric"><span>Net Movement</span><strong><?=e($currency)?> <?=number_format($balance,2)?></strong></div></section>
<section class="panel"><div style="overflow:auto"><table class="table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Customer / Payee</th><th>Method</th><th class="num">Money In</th><th class="num">Money Out</th><th class="num">Running Balance</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="8" class="muted">No posted cash movements in this period.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td><?=e($row['transaction_date'])?></td><td><?=e($row['transaction_type'])?></td><td><?=e($row['reference'])?></td><td><?=e($row['party'])?></td><td><?=e($row['payment_method'])?></td><td class="num in"><?=$row['direction']==='IN'?e($currency).' '.number_format((float)$row['amount'],2):'—'?></td><td class="num out"><?=$row['direction']==='OUT'?e($currency).' '.number_format((float)$row['amount'],2):'—'?></td><td class="num"><?=e($currency)?> <?=number_format((float)$row['running'],2)?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<p class="muted" style="font-size:11px;margin-top:12px">This is a cash movement ledger, not yet a double-entry general ledger. It excludes unconfirmed M-PESA requests and avoids counting sale-linked customer payment records twice.</p>
</main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
