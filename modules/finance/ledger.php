<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../includes/report_tools.php';
requireModuleAccess('finance');
$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-d');
$valid='/^\d{4}-\d{2}-\d{2}$/';$from=preg_match($valid,$from)?$from:date('Y-m-01');$to=preg_match($valid,$to)?$to:date('Y-m-d');if($from>$to){[$from,$to]=[$to,$from];}
$hasReturnPayments=(bool)$conn->query("SHOW TABLES LIKE 'sales_return_payments'")->fetchColumn();
$hasSalePaymentLines=(bool)$conn->query("SHOW TABLES LIKE 'sale_payment_lines'")->fetchColumn();
$hasCustomerCreditRefunds=(bool)$conn->query("SHOW TABLES LIKE 'customer_credit_refunds'")->fetchColumn();
$sql="SELECT transaction_date,transaction_type,reference,party,payment_method,direction,amount FROM (
 SELECT s.sale_date transaction_date,CONVERT('POS Receipt' USING utf8mb4) COLLATE utf8mb4_unicode_ci transaction_type,CONVERT(s.invoice_number USING utf8mb4) COLLATE utf8mb4_unicode_ci reference,CONVERT(COALESCE(c.customer_name,'Walk-in Customer') USING utf8mb4) COLLATE utf8mb4_unicode_ci party,CONVERT(s.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci payment_method,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci direction,s.paid_amount amount
 FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.sale_status='Completed' AND s.customer_id IS NULL AND s.paid_amount>0 AND DATE(s.sale_date) BETWEEN ? AND ? ".($hasSalePaymentLines?"AND NOT EXISTS (SELECT 1 FROM sale_payment_lines spl WHERE spl.sale_id=s.id) ":"")."
 ".($hasSalePaymentLines?"UNION ALL SELECT COALESCE(spl.confirmed_at,spl.created_at),CONVERT('POS Receipt' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(s.invoice_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(COALESCE(c.customer_name,'Walk-in Customer') USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(spl.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci,spl.amount FROM sale_payment_lines spl JOIN sales s ON s.id=spl.sale_id LEFT JOIN customers c ON c.id=s.customer_id WHERE s.sale_status='Completed' AND s.customer_id IS NULL AND spl.status='Confirmed' AND DATE(COALESCE(spl.confirmed_at,spl.created_at)) BETWEEN ? AND ? ":"")."
 UNION ALL
 SELECT cp.payment_date,CONVERT('Customer Collection' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(cp.receipt_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(c.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(cp.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci,cp.amount
 FROM customer_payments cp JOIN customers c ON c.id=cp.customer_id WHERE DATE(cp.payment_date) BETWEEN ? AND ?
 UNION ALL
 SELECT COALESCE(mt.transaction_date,mt.created_at),CONVERT('Unallocated M-Pesa Receipt' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(mt.transaction_reference USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(COALESCE(mt.phone_number,'Unmatched customer') USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci,mt.amount_unallocated
 FROM mpesa_transactions mt WHERE mt.transaction_type='C2B' AND mt.direction='Incoming' AND mt.result_status='Success' AND mt.reference_type IS NULL AND mt.amount_unallocated>0.009 AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?
 UNION ALL
 SELECT COALESCE(mt.transaction_date,mt.created_at),CONVERT('Unallocated STK Overpayment' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(s.invoice_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('Walk-in Customer' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci,mt.amount_unallocated
 FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id
 WHERE mt.transaction_type='STK Push' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL AND mt.amount_unallocated>0.009 ".($hasSalePaymentLines?"AND NOT EXISTS (SELECT 1 FROM sale_payment_lines spl WHERE spl.mpesa_transaction_id=mt.id) ":"")."AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?
 UNION ALL
 SELECT COALESCE(mt.transaction_date,mt.created_at),CONVERT('Unallocated C2B Invoice Overpayment' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(s.invoice_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('Walk-in Customer' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('IN' USING utf8mb4) COLLATE utf8mb4_unicode_ci,mt.amount_unallocated
 FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id
 WHERE mt.transaction_type='C2B' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL AND mt.amount_unallocated>0.009 AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?
 UNION ALL
 SELECT sp.payment_date,CONVERT('Supplier Payment' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(sp.receipt_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(s.supplier_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(sp.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('OUT' USING utf8mb4) COLLATE utf8mb4_unicode_ci,sp.amount
 FROM supplier_payments sp JOIN suppliers s ON s.id=sp.supplier_id WHERE DATE(sp.payment_date) BETWEEN ? AND ?
 UNION ALL
 SELECT e.expense_date,CONVERT('Operating Expense' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.expense_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.expense_title USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(e.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('OUT' USING utf8mb4) COLLATE utf8mb4_unicode_ci,e.amount
 FROM expenses e WHERE e.expense_date BETWEEN ? AND ?";
if($hasReturnPayments){$sql.=" UNION ALL
 SELECT rp.paid_at,CONVERT('Sales Return Refund' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(sr.return_number USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('Customer Refund' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(rp.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('OUT' USING utf8mb4) COLLATE utf8mb4_unicode_ci,rp.amount
 FROM sales_return_payments rp JOIN sales_returns sr ON sr.id=rp.return_id WHERE DATE(rp.paid_at) BETWEEN ? AND ?";}
if($hasCustomerCreditRefunds){$sql.=" UNION ALL
 SELECT ccr.created_at,CONVERT('Customer Credit Refund' USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(CONCAT('CCR-',ccr.id) USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(c.customer_name USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT(ccr.payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,CONVERT('OUT' USING utf8mb4) COLLATE utf8mb4_unicode_ci,ccr.amount
 FROM customer_credit_refunds ccr JOIN customers c ON c.id=ccr.customer_id WHERE DATE(ccr.created_at) BETWEEN ? AND ?";}
$sql.=") ledger_rows ORDER BY transaction_date ASC,reference ASC";
$params=[$from,$to];if($hasSalePaymentLines){$params[]=$from;$params[]=$to;}$params=array_merge($params,[$from,$to,$from,$to,$from,$to,$from,$to,$from,$to,$from,$to]);if($hasReturnPayments){$params[]=$from;$params[]=$to;}if($hasCustomerCreditRefunds){$params[]=$from;$params[]=$to;}
$q=$conn->prepare($sql);$q->execute($params);$rows=$q->fetchAll();
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
$in=0.0;$out=0.0;$balance=0.0;foreach($rows as &$row){$amount=(float)$row['amount'];if($row['direction']==='IN'){$in+=$amount;$balance+=$amount;}else{$out+=$amount;$balance-=$amount;}$row['running']=$balance;}unset($row);
$exportRows=[];foreach($rows as $r){$exportRows[]=[$r['transaction_date'],$r['transaction_type'],$r['reference'],$r['party'],$r['payment_method'],$r['direction']==='IN'?$r['amount']:'',$r['direction']==='OUT'?$r['amount']:'',$r['running']];}
dibonkeReportExport('cashbook_ledger_'.$from.'_to_'.$to,['Date','Type','Reference','Customer / Payee','Method','Money In','Money Out','Running Balance'],$exportRows);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><?php dibonkeReportPrintStyles(); ?>
<style>
.finance-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 20px;padding:8px;background:#eef2f7;border-radius:11px}.finance-tabs a{padding:10px 14px;border-radius:8px;text-decoration:none;color:#40546a;font-size:12px;font-weight:800}.finance-tabs a.active{background:#17324d;color:#fff}
.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:15px 0}.metric,.panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px}.metric span{display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase}.metric strong{display:block;margin-top:7px;font-size:21px;color:#17324d}.filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:14px 0;padding:14px;background:#fff;border:1px solid #e5eaf0;border-radius:10px}.filter label{display:block;font-size:11px;font-weight:800;margin-bottom:5px}.filter input{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.btn{background:#174a7e;color:#fff;border:0;padding:10px 14px;border-radius:7px;font-weight:800}.table{width:100%;border-collapse:collapse;min-width:850px}.table th,.table td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.table th{background:#f3f6fa;color:#475569}.num{text-align:right!important;white-space:nowrap}.in{color:#047857;font-weight:800}.out{color:#b42318;font-weight:800}.muted{color:#64748b}@media(max-width:700px){.metrics{grid-template-columns:1fr}}
</style>
<h1>Finance · Cashbook Ledger</h1><p class="muted">Chronological record of recorded customer receipts, supplier payments and operating expenses.</p>
<nav class="finance-tabs"><a href="<?=BASE_URL?>/modules/finance/index.php">Overview</a><a class="active" href="<?=BASE_URL?>/modules/finance/ledger.php">Ledger</a><a href="<?=BASE_URL?>/modules/finance/control.php">Financial Control</a><a href="<?=BASE_URL?>/modules/finance/periods.php">Period Controls</a><a href="<?=BASE_URL?>/modules/expenses/index.php">Expenses</a></nav>
<form class="filter"><div><label>From</label><input type="date" name="from" value="<?=e($from)?>"></div><div><label>To</label><input type="date" name="to" value="<?=e($to)?>"></div><button class="btn">Apply Period</button></form>
<?php dibonkeReportActions('cashbook_ledger'); ?>
<section class="metrics"><div class="metric"><span>Money In</span><strong class="in"><?=e($currency)?> <?=number_format($in,2)?></strong></div><div class="metric"><span>Money Out</span><strong class="out"><?=e($currency)?> <?=number_format($out,2)?></strong></div><div class="metric"><span>Net Movement</span><strong><?=e($currency)?> <?=number_format($balance,2)?></strong></div></section>
<section class="panel"><div style="overflow:auto"><table class="table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Customer / Payee</th><th>Method</th><th class="num">Money In</th><th class="num">Money Out</th><th class="num">Running Balance</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="8" class="muted">No posted cash movements in this period.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td><?=e($row['transaction_date'])?></td><td><?=e($row['transaction_type'])?></td><td><?=e($row['reference'])?></td><td><?=e($row['party'])?></td><td><?=e($row['payment_method'])?></td><td class="num in"><?=$row['direction']==='IN'?e($currency).' '.number_format((float)$row['amount'],2):'—'?></td><td class="num out"><?=$row['direction']==='OUT'?e($currency).' '.number_format((float)$row['amount'],2):'—'?></td><td class="num"><?=e($currency)?> <?=number_format((float)$row['running'],2)?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<p class="muted" style="font-size:11px;margin-top:12px">This is a cash movement ledger, not yet a double-entry general ledger. It excludes unconfirmed M-PESA requests and avoids counting sale-linked customer payment records twice.</p>
</main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
