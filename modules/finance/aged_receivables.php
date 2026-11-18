<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/report_tools.php';
requireModuleAccess('aged_receivables');

$asAt=trim((string)($_GET['as_at']??date('Y-m-d')));
$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$asAt);
if(!$parsed||$parsed->format('Y-m-d')!==$asAt)$asAt=date('Y-m-d');
$search=trim((string)($_GET['q']??''));
$bucket=(string)($_GET['bucket']??'');
$bucketSql="CASE WHEN DATEDIFF(?,DATE(s.sale_date))<=30 THEN 'Current (0–30)' WHEN DATEDIFF(?,DATE(s.sale_date))<=60 THEN '31–60 days' WHEN DATEDIFF(?,DATE(s.sale_date))<=90 THEN '61–90 days' ELSE 'Over 90 days' END";
$where="s.sale_status='Completed' AND s.balance>0.009 AND DATE(s.sale_date)<=?";
$params=[$asAt];
if($search!==''){$where.=" AND (c.customer_name LIKE ? OR c.phone LIKE ? OR s.invoice_number LIKE ?)";$like='%'.$search.'%';array_push($params,$like,$like,$like);}
$base=" FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE ".$where;
$sql="SELECT s.id,s.customer_id,s.invoice_number,s.sale_date,s.total_amount,s.paid_amount,s.balance,c.customer_name,c.phone,DATEDIFF(?,DATE(s.sale_date)) age_days,".$bucketSql." age_bucket".$base." ORDER BY s.sale_date ASC,s.id ASC";
$statement=$conn->prepare($sql);$statement->execute(array_merge([$asAt,$asAt,$asAt,$asAt],$params));$allInvoices=$statement->fetchAll(PDO::FETCH_ASSOC);
$totals=['Current (0–30)'=>0.0,'31–60 days'=>0.0,'61–90 days'=>0.0,'Over 90 days'=>0.0];$totalDue=0.0;
foreach($allInvoices as $r){$totals[$r['age_bucket']]+=(float)$r['balance'];$totalDue+=(float)$r['balance'];}
$invoices=in_array($bucket,array_keys($totals),true)?array_values(array_filter($allInvoices,fn($r)=>$r['age_bucket']===$bucket)):$allInvoices;
$customerSql="SELECT c.id customer_id,COALESCE(c.customer_name,'Walk-in / Unassigned') customer_name,COUNT(*) invoice_count,SUM(s.balance) balance".$base." GROUP BY s.customer_id,c.id,c.customer_name ORDER BY balance DESC";
$customerQuery=$conn->prepare($customerSql);$customerQuery->execute($params);$customerTotals=$customerQuery->fetchAll(PDO::FETCH_ASSOC);
$exportRows=[];foreach($invoices as $r){$exportRows[]=[$r['invoice_number'],$r['customer_name']??'Walk-in / Unassigned',$r['phone']??'',$r['sale_date'],$r['age_days'],$r['age_bucket'],$r['total_amount'],$r['paid_amount'],$r['balance']];}
dibonkeReportExport('aged_receivables_as_at_'.$asAt,['Invoice','Customer','Phone','Invoice Date','Age Days','Age Bucket','Invoice Total','Paid','Balance'],$exportRows);
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.age-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;padding:8px;background:#eef2f7;border-radius:11px}.age-tabs a{padding:9px 12px;border-radius:8px;text-decoration:none;color:#40546a;font-size:12px;font-weight:800}.age-tabs a.active{background:#17324d;color:#fff}.age-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:15px 0}.age-card,.age-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:15px}.age-card span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}.age-card strong{display:block;margin-top:7px;font-size:18px;color:#17324d}.age-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px;background:#fff;border:1px solid #e5eaf0;border-radius:10px;margin:14px 0}.age-filter label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:800}.age-filter input,.age-filter select{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.age-btn{background:#174a7e;color:#fff;border:0;padding:10px 14px;border-radius:7px;font-weight:800}.age-table{width:100%;border-collapse:collapse}.age-table th,.age-table td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px;white-space:nowrap}.age-table th{background:#f3f6fa;color:#475569}.age-num{text-align:right!important}.age-muted{color:#64748b}.age-layout{display:grid;grid-template-columns:1.4fr .8fr;gap:14px}.age-panel h2{margin-top:0}@media(max-width:1100px){.age-cards{grid-template-columns:repeat(2,1fr)}.age-layout{grid-template-columns:1fr}}@media(max-width:560px){.age-cards{grid-template-columns:1fr}}
</style>
<?php dibonkeReportPrintStyles(); ?>
<h1>Aged Receivables</h1><p class="age-muted">Outstanding completed sales grouped by invoice age, with customer-level balances.</p>
<nav class="age-tabs"><a href="<?=BASE_URL?>/modules/finance/index.php">Finance Overview</a><a href="<?=BASE_URL?>/modules/finance/control.php">Financial Control</a><a class="active" href="<?=BASE_URL?>/modules/finance/aged_receivables.php">Aged Receivables</a><a href="<?=BASE_URL?>/modules/finance/trial_balance.php">Trial Balance</a></nav>
<form class="age-filter" method="get"><label>Age as at<input type="date" name="as_at" value="<?=e($asAt)?>"></label><label>Customer / invoice<input name="q" value="<?=e($search)?>" placeholder="Search customer, phone or invoice"></label><label>Age bucket<select name="bucket"><option value="">All buckets</option><?php foreach(array_keys($totals) as $b):?><option value="<?=e($b)?>" <?=$bucket===$b?'selected':''?>><?=e($b)?></option><?php endforeach;?></select></label><button class="age-btn">Apply</button><a href="<?=BASE_URL?>/modules/finance/aged_receivables.php">Reset</a></form>
<?php dibonkeReportActions('aged_receivables'); ?>
<section class="age-cards"><div class="age-card"><span>Total Outstanding</span><strong><?=e($currency)?> <?=number_format($totalDue,2)?></strong></div><?php foreach($totals as $label=>$amount):?><div class="age-card"><span><?=e($label)?></span><strong><?=e($currency)?> <?=number_format($amount,2)?></strong></div><?php endforeach;?></section>
<div class="age-layout"><section class="age-panel"><h2>Outstanding invoices</h2><div style="overflow:auto"><table class="age-table"><thead><tr><th>Invoice</th><th>Customer</th><th>Invoice Date</th><th>Age</th><th>Age Bucket</th><th class="age-num">Invoice Total</th><th class="age-num">Paid</th><th class="age-num">Balance</th></tr></thead><tbody>
<?php if(!$invoices):?><tr><td colspan="8" class="age-muted">No outstanding invoices match this report.</td></tr><?php endif;?>
<?php foreach($invoices as $r):?><tr><td><a href="<?=BASE_URL?>/modules/sales/receipt.php?id=<?=(int)$r['id']?>"><?=e($r['invoice_number'])?></a></td><td><?php if(!empty($r['customer_id'])):?><a href="<?=BASE_URL?>/modules/customers/view.php?id=<?=(int)$r['customer_id']?>"><?=e($r['customer_name'])?></a><?php else:?><?=e($r['customer_name']??'Walk-in / Unassigned')?><?php endif;?><?php if(!empty($r['phone'])):?><br><small class="age-muted"><?=e($r['phone'])?></small><?php endif;?></td><td><?=e($r['sale_date'])?></td><td><?=number_format((int)$r['age_days'])?> days</td><td><?=e($r['age_bucket'])?></td><td class="age-num"><?=number_format((float)$r['total_amount'],2)?></td><td class="age-num"><?=number_format((float)$r['paid_amount'],2)?></td><td class="age-num"><strong><?=number_format((float)$r['balance'],2)?></strong></td></tr><?php endforeach;?>
</tbody></table></div></section>
<section class="age-panel"><h2>Customer balances</h2><div style="overflow:auto"><table class="age-table"><thead><tr><th>Customer</th><th>Invoices</th><th class="age-num">Outstanding</th></tr></thead><tbody><?php if(!$customerTotals):?><tr><td colspan="3">No balances.</td></tr><?php endif;?><?php foreach($customerTotals as $c):?><tr><td><a href="<?=BASE_URL?>/modules/customers/view.php?id=<?=(int)$c['customer_id']?>"><?=e($c['customer_name'])?></a></td><td><?=number_format((int)$c['invoice_count'])?></td><td class="age-num"><strong><?=number_format((float)$c['balance'],2)?></strong></td></tr><?php endforeach;?></tbody></table></div></section></div>
<p class="age-muted" style="font-size:11px;margin-top:12px">Ageing is calculated from the invoice/sale date because the current sales schema does not contain a contractual due date. “Current (0–30)” means invoices issued within the last 30 days; this is invoice-age analysis, not a determination of overdue status. Balances are read from completed sales with a positive outstanding balance.</p>
</main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
