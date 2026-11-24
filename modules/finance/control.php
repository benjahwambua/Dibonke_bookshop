<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../includes/report_tools.php';
requireModuleAccess('finance');
$from = isset($_GET['from']) ? (string) $_GET['from'] : date("Y-m-01");
$to = isset($_GET['to']) ? (string) $_GET['to'] : date("Y-m-d");
$datePattern = "~^\\d{4}-\\d{2}-\\d{2}$~";
if (!preg_match($datePattern, $from)) {
    $from = date("Y-m-01");
}
if (!preg_match($datePattern, $to)) {
    $to = date("Y-m-d");
}
if ($from > $to) {
    $temporaryDate = $from;
    $from = $to;
    $to = $temporaryDate;
}
$run=function($sql,$params=[])use($conn){$q=$conn->prepare($sql);$q->execute($params);return $q;};
$sales=$run("SELECT COUNT(*) invoices,COALESCE(SUM(total_amount),0) revenue,COALESCE(SUM(paid_amount),0) collected,COALESCE(SUM(balance),0) due FROM sales WHERE sale_status='Completed' AND DATE(sale_date) BETWEEN ? AND ?",[$from,$to])->fetch();
$customerReceipts=(float)$run("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$walkInCollections=(float)$run("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE customer_id IS NULL AND sale_status='Completed' AND DATE(sale_date) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$hasMpesaTransactions=(bool)$conn->query("SHOW TABLES LIKE 'mpesa_transactions'")->fetchColumn();
$unallocatedC2BReceipts=0.0;$unallocatedStkOverpayments=0.0;
if($hasMpesaTransactions){
    $unallocatedC2BReceipts=(float)$run("SELECT COALESCE(SUM(amount),0) FROM mpesa_transactions WHERE transaction_type='C2B' AND direction='Incoming' AND result_status='Success' AND reference_type IS NULL AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
    $unallocatedStkOverpayments=(float)$run("SELECT COALESCE(SUM(overpayment),0) FROM (SELECT GREATEST(0,SUM(mt.amount)-s.paid_amount) overpayment,MAX(COALESCE(mt.transaction_date,mt.created_at)) last_received FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id WHERE mt.transaction_type='STK Push' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL GROUP BY s.id,s.paid_amount HAVING SUM(mt.amount)>s.paid_amount+0.009) stk WHERE DATE(last_received) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
}
$receipts=$customerReceipts+$walkInCollections+$unallocatedC2BReceipts+$unallocatedStkOverpayments;
$supplierOut=(float)$run("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE DATE(payment_date) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$expenseOut=(float)$run("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$hasReturnPayments=(bool)$conn->query("SHOW TABLES LIKE 'sales_return_payments'")->fetchColumn();
$returnOut=$hasReturnPayments?(float)$run("SELECT COALESCE(SUM(amount),0) FROM sales_return_payments WHERE DATE(paid_at) BETWEEN ? AND ?",[$from,$to])->fetchColumn():0.0;
$customerDue=(float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM customers")->fetchColumn();$supplierDue=(float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM suppliers")->fetchColumn();
$methodSql="SELECT method,SUM(received) received,SUM(spent) spent FROM (
 SELECT CONVERT(payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci method,SUM(amount) received,0 spent FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ? GROUP BY payment_method
 UNION ALL SELECT CONVERT(payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,SUM(paid_amount),0 FROM sales WHERE customer_id IS NULL AND sale_status='Completed' AND DATE(sale_date) BETWEEN ? AND ? GROUP BY payment_method
 UNION ALL SELECT CONVERT(payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,0,SUM(amount) FROM supplier_payments WHERE DATE(payment_date) BETWEEN ? AND ? GROUP BY payment_method
 UNION ALL SELECT CONVERT(payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,0,SUM(amount) FROM expenses WHERE expense_date BETWEEN ? AND ? GROUP BY payment_method";
$methodParams=[$from,$to,$from,$to,$from,$to,$from,$to];
if($hasMpesaTransactions){
 $methodSql.=" UNION ALL SELECT CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,COALESCE(SUM(amount),0),0 FROM mpesa_transactions WHERE transaction_type='C2B' AND direction='Incoming' AND result_status='Success' AND reference_type IS NULL AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?";
 $methodParams[]=$from;$methodParams[]=$to;
 $methodSql.=" UNION ALL SELECT CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,COALESCE(SUM(overpayment),0),0 FROM (SELECT GREATEST(0,SUM(mt.amount)-s.paid_amount) overpayment,MAX(COALESCE(mt.transaction_date,mt.created_at)) last_received FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id WHERE mt.transaction_type='STK Push' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL GROUP BY s.id,s.paid_amount HAVING SUM(mt.amount)>s.paid_amount+0.009) stk WHERE DATE(last_received) BETWEEN ? AND ?";
 $methodParams[]=$from;$methodParams[]=$to;
}
if($hasReturnPayments){$methodSql.=" UNION ALL SELECT CONVERT(payment_method USING utf8mb4) COLLATE utf8mb4_unicode_ci,0,SUM(amount) FROM sales_return_payments WHERE DATE(paid_at) BETWEEN ? AND ? GROUP BY payment_method";$methodParams[]=$from;$methodParams[]=$to;}
$methodSql.=") x GROUP BY method ORDER BY method";
$methods=$run($methodSql,$methodParams)->fetchAll();
$exceptions=[];
$over=$conn->query("SELECT COUNT(*) FROM sales WHERE sale_status='Completed' AND (paid_amount>total_amount+0.01 OR balance<0)")->fetchColumn();if((int)$over>0)$exceptions[]=['High','Sales records with overpayment or negative balance', (int)$over];
$negCust=$conn->query("SELECT COUNT(*) FROM customers WHERE balance<-.01")->fetchColumn();if((int)$negCust>0)$exceptions[]=['Medium','Customers with negative outstanding balances',(int)$negCust];
$negSup=$conn->query("SELECT COUNT(*) FROM suppliers WHERE balance<-.01")->fetchColumn();if((int)$negSup>0)$exceptions[]=['Medium','Suppliers with negative outstanding balances',(int)$negSup];
$pending=(int)$conn->query("SELECT COUNT(*) FROM mpesa_transactions WHERE result_status='Pending'")->fetchColumn();if($pending>0)$exceptions[]=['Review','M-PESA transactions awaiting confirmation',$pending];
$exportRows=[['Sales Invoiced',(float)$sales['revenue']],['Customer Collections',$receipts],['Supplier Payments',$supplierOut],['Operating Expenses',$expenseOut],['Sales Refunds',$returnOut],['Customer Receivables',$customerDue],['Supplier Payables',$supplierDue]];foreach($methods as $m){$exportRows[]=['Payment Method: '.$m['method'].' received',(float)$m['received']];$exportRows[]=['Payment Method: '.$m['method'].' paid out',(float)$m['spent']];}dibonkeReportExport('financial_control_'.$from.'_to_'.$to,['Control Item','Amount'],$exportRows);
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><?php dibonkeReportPrintStyles(); ?>
<style>
.finance-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 20px;padding:8px;background:#eef2f7;border-radius:11px}.finance-tabs a{padding:10px 14px;border-radius:8px;text-decoration:none;color:#40546a;font-size:12px;font-weight:800}.finance-tabs a.active{background:#17324d;color:#fff}
.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:15px 0}.metric,.panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px}.metric span{display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase}.metric strong{display:block;margin-top:7px;font-size:21px;color:#17324d}.filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:14px 0;padding:14px;background:#fff;border:1px solid #e5eaf0;border-radius:10px}.filter label{display:block;font-size:11px;font-weight:800;margin-bottom:5px}.filter input{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.btn{background:#174a7e;color:#fff;border:0;padding:10px 14px;border-radius:7px;font-weight:800}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:11px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.table th{background:#f3f6fa;color:#475569}.num{text-align:right}.muted{color:#64748b}.status{font-size:10px;font-weight:800;padding:4px 8px;border-radius:20px;background:#fff7ed;color:#9a3412}@media(max-width:700px){.metrics{grid-template-columns:1fr}}
</style>
<h1>Finance · Financial Control</h1><p class="muted">Monitor cash movement, outstanding balances, payment channels and transaction exceptions.</p>
<nav class="finance-tabs"><a href="<?=BASE_URL?>/modules/finance/index.php">Overview</a><a href="<?=BASE_URL?>/modules/finance/ledger.php">Ledger</a><a class="active" href="<?=BASE_URL?>/modules/finance/control.php">Financial Control</a><a href="<?=BASE_URL?>/modules/expenses/index.php">Expenses</a></nav>
<form class="filter"><div><label>From</label><input type="date" name="from" value="<?=e($from)?>"></div><div><label>To</label><input type="date" name="to" value="<?=e($to)?>"></div><button class="btn">Apply Period</button></form>
<?php dibonkeReportActions('financial_control'); ?>
<section class="metrics">
<div class="metric"><span>Sales Invoiced</span><strong><?=e($currency)?> <?=number_format((float)$sales['revenue'],2)?></strong><small><?=number_format((int)$sales['invoices'])?> completed sales</small></div>
<div class="metric"><span>Customer Collections</span><strong><?=e($currency)?> <?=number_format($receipts,2)?></strong></div>
<div class="metric"><span>Supplier Payments</span><strong><?=e($currency)?> <?=number_format($supplierOut,2)?></strong></div>
<div class="metric"><span>Operating Expenses</span><strong><?=e($currency)?> <?=number_format($expenseOut,2)?></strong></div>
<div class="metric"><span>Sales Refunds</span><strong><?=e($currency)?> <?=number_format($returnOut,2)?></strong></div>
<div class="metric"><span>Customer Receivables</span><strong><?=e($currency)?> <?=number_format($customerDue,2)?></strong><small>Current customer balances</small></div>
<div class="metric"><span>Supplier Payables</span><strong><?=e($currency)?> <?=number_format($supplierDue,2)?></strong><small>Current supplier balances</small></div>
</section>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
<section class="panel"><h3>Payment Channel Control</h3><div style="overflow:auto"><table class="table"><thead><tr><th>Method</th><th class="num">Received</th><th class="num">Paid Out</th><th class="num">Net</th></tr></thead><tbody>
<?php if(!$methods):?><tr><td colspan="4" class="muted">No transactions in this period.</td></tr><?php endif;?>
<?php foreach($methods as $m):?><tr><td><?=e($m['method'])?></td><td class="num"><?=e($currency)?> <?=number_format((float)$m['received'],2)?></td><td class="num"><?=e($currency)?> <?=number_format((float)$m['spent'],2)?></td><td class="num"><?=e($currency)?> <?=number_format((float)$m['received']-(float)$m['spent'],2)?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<section class="panel"><h3>Control Exceptions</h3>
<?php if(!$exceptions):?><p class="muted">No exceptions were detected by the current checks.</p><?php endif;?>
<?php foreach($exceptions as $ex):?><p style="display:flex;justify-content:space-between;gap:10px;border-bottom:1px solid #edf1f5;padding:9px 0;margin:0"><span><?=e($ex[1])?></span><span class="status"><?=e($ex[0])?> · <?=number_format($ex[2])?></span></p><?php endforeach;?>
</section>
</div>
<p class="muted" style="font-size:11px;margin-top:12px">Control checks are operational alerts, not a statutory audit or bank reconciliation. Payment totals reflect posted records only; approved return credits and recorded refund payments are tracked separately. Pending M-PESA requests are excluded from collections.</p>
</main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
