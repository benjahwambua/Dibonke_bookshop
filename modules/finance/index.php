<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('finance');

$from=$_GET['from']??date('Y-m-01');
$to=$_GET['to']??date('Y-m-d');
$from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)?$from:date('Y-m-01');
$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)?$to:date('Y-m-d');
if($from>$to){$swap=$from;$from=$to;$to=$swap;}
$run=function(string $sql,array $params=[])use($conn){$q=$conn->prepare($sql);$q->execute($params);return $q;};

$sales=$run("SELECT COUNT(*) invoices,COALESCE(SUM(total_amount),0) revenue,COALESCE(SUM(paid_amount),0) paid_at_sale,COALESCE(SUM(balance),0) receivables FROM sales WHERE sale_status='Completed' AND DATE(sale_date) BETWEEN ? AND ?",[$from,$to])->fetch();
$customerReceipts=(float)$run("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$walkInCollections=(float)$run("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE customer_id IS NULL AND sale_status='Completed' AND DATE(sale_date) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$hasMpesaTransactions=(bool)$conn->query("SHOW TABLES LIKE 'mpesa_transactions'")->fetchColumn();
$hasSalePaymentLines=(bool)$conn->query("SHOW TABLES LIKE 'sale_payment_lines'")->fetchColumn();
$unallocatedC2BReceipts=0.0;$unallocatedStkOverpayments=0.0;$unallocatedWalkInC2BOverpayments=0.0;
if($hasMpesaTransactions){
    $unallocatedC2BReceipts=(float)$run("SELECT COALESCE(SUM(amount_unallocated),0) FROM mpesa_transactions WHERE transaction_type='C2B' AND direction='Incoming' AND result_status='Success' AND reference_type IS NULL AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
    $unallocatedStkOverpayments=(float)$run("SELECT COALESCE(SUM(mt.amount_unallocated),0) FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id WHERE mt.transaction_type='STK Push' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL AND mt.amount_unallocated>0.009 ".($hasSalePaymentLines?"AND NOT EXISTS (SELECT 1 FROM sale_payment_lines spl WHERE spl.mpesa_transaction_id=mt.id) ":"")."AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
    $unallocatedWalkInC2BOverpayments=(float)$run("SELECT COALESCE(SUM(mt.amount_unallocated),0) FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id WHERE mt.transaction_type='C2B' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL AND mt.amount_unallocated>0.009 ".($hasSalePaymentLines?"AND NOT EXISTS (SELECT 1 FROM sale_payment_lines spl WHERE spl.mpesa_transaction_id=mt.id) ":"")."AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
}
$collections=$customerReceipts+$walkInCollections+$unallocatedC2BReceipts+$unallocatedStkOverpayments+$unallocatedWalkInC2BOverpayments;
$supplierPayments=(float)$run("SELECT COALESCE(SUM(amount),0) FROM supplier_payments WHERE DATE(payment_date) BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$expenses=(float)$run("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN ? AND ?",[$from,$to])->fetchColumn();
$hasCustomerCreditRefunds=(bool)$conn->query("SHOW TABLES LIKE 'customer_credit_refunds'")->fetchColumn();
$customerCreditRefunds=$hasCustomerCreditRefunds?(float)$run("SELECT COALESCE(SUM(amount),0) FROM customer_credit_refunds WHERE DATE(created_at) BETWEEN ? AND ?",[$from,$to])->fetchColumn():0.0;
$hasReturnPayments=(bool)$conn->query("SHOW TABLES LIKE 'sales_return_payments'")->fetchColumn();
$salesRefunds=$hasReturnPayments?(float)$run("SELECT COALESCE(SUM(amount),0) FROM sales_return_payments WHERE DATE(paid_at) BETWEEN ? AND ?",[$from,$to])->fetchColumn():0.0;
$net=$collections-$supplierPayments-$expenses-$customerCreditRefunds-$salesRefunds;
$customerOutstanding=(float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM customers")->fetchColumn();
$supplierOutstanding=(float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM suppliers")->fetchColumn();
$expenseRows=$run("SELECT e.expense_number,e.expense_date,e.expense_title,e.amount,e.payment_method,ec.category_name FROM expenses e LEFT JOIN expense_categories ec ON ec.id=e.category_id WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC,e.id DESC LIMIT 12",[$from,$to])->fetchAll();
$methodSources="SELECT payment_method,COUNT(*) transactions,COALESCE(SUM(amount),0) amount FROM customer_payments WHERE DATE(payment_date) BETWEEN ? AND ? GROUP BY payment_method UNION ALL SELECT s.payment_method,COUNT(*),COALESCE(SUM(s.paid_amount),0) FROM sales s WHERE s.customer_id IS NULL AND s.sale_status='Completed' AND DATE(s.sale_date) BETWEEN ? AND ? ".($hasSalePaymentLines?"AND NOT EXISTS (SELECT 1 FROM sale_payment_lines spl WHERE spl.sale_id=s.id) ":"")."GROUP BY s.payment_method";
$methodParams=[$from,$to,$from,$to];
if($hasSalePaymentLines){$methodSources.=" UNION ALL SELECT spl.payment_method,COUNT(*),COALESCE(SUM(spl.amount),0) FROM sale_payment_lines spl JOIN sales s ON s.id=spl.sale_id WHERE s.customer_id IS NULL AND s.sale_status='Completed' AND spl.status='Confirmed' AND DATE(COALESCE(spl.confirmed_at,spl.created_at)) BETWEEN ? AND ? GROUP BY spl.payment_method";$methodParams[]=$from;$methodParams[]=$to;}
if($hasMpesaTransactions){
    $methodSources.=" UNION ALL SELECT CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,COUNT(*),COALESCE(SUM(amount_unallocated),0) FROM mpesa_transactions WHERE transaction_type='C2B' AND direction='Incoming' AND result_status='Success' AND reference_type IS NULL AND amount_unallocated>0.009 AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?";
    $methodParams[]=$from;$methodParams[]=$to;
    $methodSources.=" UNION ALL SELECT CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,COUNT(*),COALESCE(SUM(mt.amount_unallocated),0) FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id WHERE mt.transaction_type='STK Push' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL AND mt.amount_unallocated>0.009 AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?";
    $methodParams[]=$from;$methodParams[]=$to;
    $methodSources.=" UNION ALL SELECT CONVERT('M-Pesa' USING utf8mb4) COLLATE utf8mb4_unicode_ci,COUNT(*),COALESCE(SUM(mt.amount_unallocated),0) FROM mpesa_transactions mt JOIN sales s ON mt.reference_type='sale' AND mt.reference_id=s.id WHERE mt.transaction_type='C2B' AND mt.direction='Incoming' AND mt.result_status='Success' AND s.sale_status='Completed' AND s.customer_id IS NULL AND mt.amount_unallocated>0.009 AND DATE(COALESCE(mt.transaction_date,mt.created_at)) BETWEEN ? AND ?";
    $methodParams[]=$from;$methodParams[]=$to;
}
$methodRows=$run("SELECT payment_method,SUM(transactions) transactions,SUM(amount) amount FROM ({$methodSources}) payment_sources GROUP BY payment_method ORDER BY amount DESC",$methodParams)->fetchAll();
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.finance-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;flex-wrap:wrap}.filter,.panel,.metric{background:#fff;border:1px solid #e5eaf0;border-radius:12px}.filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px;margin:16px 0}.filter label{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:5px}.filter input{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.btn{border:0;border-radius:7px;background:#174a7e;color:#fff;padding:10px 15px;font-weight:800;cursor:pointer}.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:15px 0}.metric{padding:16px}.metric span{display:block;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase}.metric strong{display:block;margin-top:7px;font-size:21px;color:#17324d}.metric small{display:block;margin-top:5px;color:#64748b}.columns{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}.panel{padding:17px;margin-bottom:14px}.panel h3{margin:0 0 13px}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.table th{background:#f3f6fa;color:#475569}.num{text-align:right!important;white-space:nowrap}.positive{color:#047857!important}.negative{color:#b42318!important}.muted{color:#64748b}@media(max-width:1050px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.columns{grid-template-columns:1fr}}@media(max-width:560px){.metrics{grid-template-columns:1fr}.metric strong{font-size:19px}}
</style>
<style>
.finance-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 20px;padding:8px;background:#eef2f7;border-radius:11px}
.finance-tabs a{padding:10px 14px;border-radius:8px;text-decoration:none;color:#40546a;font-size:12px;font-weight:800}
.finance-tabs a.active{background:#17324d;color:#fff}
</style>
<nav class="finance-tabs" aria-label="Finance sections">
<a class="active" href="<?=BASE_URL?>/modules/finance/index.php">Overview</a>
<a href="<?=BASE_URL?>/modules/finance/ledger.php">Ledger</a>
<a href="<?=BASE_URL?>/modules/finance/control.php">Financial Control</a>
<a href="<?=BASE_URL?>/modules/finance/chart_of_accounts.php">Chart of Accounts</a>
<a href="<?=BASE_URL?>/modules/finance/journals.php">Journals</a>
<a href="<?=BASE_URL?>/modules/finance/general_ledger.php">General Ledger</a>
<a href="<?=BASE_URL?>/modules/finance/trial_balance.php">Trial Balance</a>
<a href="<?=BASE_URL?>/modules/finance/aged_receivables.php">Aged Receivables</a>
<?php if(canAccessModule('aged_payables')): ?><a href="<?=BASE_URL?>/modules/finance/aged_payables.php">Aged Payables</a><?php endif; ?>
<a href="<?=BASE_URL?>/modules/expenses/index.php">Expenses</a>
</nav>
<div class="finance-head"><div><h1>Finance</h1><p class="muted">A consolidated view of sales collections, supplier payments, expenses and working balances.</p></div></div>
<form class="filter"><div><label>From</label><input type="date" name="from" value="<?=e($from)?>"></div><div><label>To</label><input type="date" name="to" value="<?=e($to)?>"></div><button class="btn">Apply Period</button></form>
<section class="metrics">
<div class="metric"><span>Sales Revenue</span><strong><?=e($currency)?> <?=number_format((float)$sales['revenue'],2)?></strong><small><?=number_format((int)$sales['invoices'])?> completed invoices</small></div>
<div class="metric"><span>Customer Collections</span><strong><?=e($currency)?> <?=number_format($collections,2)?></strong><small>POS payments, later collections and unmatched M-Pesa receipts</small></div>
<div class="metric"><span>Supplier Payments</span><strong><?=e($currency)?> <?=number_format($supplierPayments,2)?></strong><small>Payments recorded in the selected period</small></div>
<div class="metric"><span>Operating Expenses</span><strong><?=e($currency)?> <?=number_format($expenses,2)?></strong><small>Expenses recorded in the selected period</small></div>
<div class="metric"><span>Net Cash Movement</span><strong class="<?=$net>=0?'positive':'negative'?>"><?=e($currency)?> <?=number_format($net,2)?></strong><small>Collections less supplier payments and expenses</small></div>
<div class="metric"><span>Customer Receivables</span><strong><?=e($currency)?> <?=number_format($customerOutstanding,2)?></strong><small>Current outstanding customer balances</small></div>
<div class="metric"><span>Supplier Payables</span><strong><?=e($currency)?> <?=number_format($supplierOutstanding,2)?></strong><small>Current supplier balances</small></div>
<div class="metric"><span>Uncollected on Invoices</span><strong><?=e($currency)?> <?=number_format((float)$sales['receivables'],2)?></strong><small>Outstanding from invoices in this period</small></div>
</section>
<div class="columns">
<section class="panel"><h3>Recent Expenses</h3><div style="overflow:auto"><table class="table"><thead><tr><th>Date</th><th>Expense</th><th>Category</th><th>Method</th><th class="num">Amount</th></tr></thead><tbody>
<?php if(!$expenseRows):?><tr><td colspan="5" class="muted">No expenses in this period.</td></tr><?php endif;?>
<?php foreach($expenseRows as $r):?><tr><td><?=e($r['expense_date'])?></td><td><?=e($r['expense_title'])?><br><small><?=e($r['expense_number'])?></small></td><td><?=e($r['category_name']??'—')?></td><td><?=e($r['payment_method'])?></td><td class="num"><?=e($currency)?> <?=number_format((float)$r['amount'],2)?></td></tr><?php endforeach;?>
</tbody></table></div><p><a href="<?=BASE_URL?>/modules/expenses/index.php">Open expense register</a></p></section>
<section class="panel"><h3>Sales Collections by Method</h3><div style="overflow:auto"><table class="table"><thead><tr><th>Method</th><th>Transactions</th><th class="num">Amount</th></tr></thead><tbody>
<?php if(!$methodRows):?><tr><td colspan="3" class="muted">No sales in this period.</td></tr><?php endif;?>
<?php foreach($methodRows as $r):?><tr><td><?=e($r['payment_method'])?></td><td><?=number_format((int)$r['transactions'])?></td><td class="num"><?=e($currency)?> <?=number_format((float)$r['amount'],2)?></td></tr><?php endforeach;?>
</tbody></table></div><p><a href="<?=BASE_URL?>/modules/reports/index.php?from=<?=e($from)?>&amp;to=<?=e($to)?>">View detailed reports</a></p></section>
</div>
<p class="muted" style="font-size:11px">This is an operational cash summary based on recorded transactions. It is not yet a double-entry general ledger or bank reconciliation.</p>
</main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
