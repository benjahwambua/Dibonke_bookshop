<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/report_tools.php';
requireModuleAccess('aged_payables');

$asAt=trim((string)($_GET['as_at']??date('Y-m-d')));
$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$asAt);
if(!$parsed||$parsed->format('Y-m-d')!==$asAt)$asAt=date('Y-m-d');
$search=trim((string)($_GET['q']??''));
$bucket=(string)($_GET['bucket']??'');
$ageSql="CASE WHEN DATEDIFF(?,DATE(p.purchase_date))<=30 THEN 'Current (0–30)' WHEN DATEDIFF(?,DATE(p.purchase_date))<=60 THEN '31–60 days' WHEN DATEDIFF(?,DATE(p.purchase_date))<=90 THEN '61–90 days' ELSE 'Over 90 days' END";
$where="p.status='Received' AND p.balance>0.009 AND DATE(p.purchase_date)<=?";
$params=[$asAt];
if($search!==''){$where.=" AND (s.supplier_name LIKE ? OR s.phone LIKE ? OR p.purchase_number LIKE ?)";$like='%'.$search.'%';array_push($params,$like,$like,$like);}
$base=" FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE ".$where;
$sql="SELECT p.id,p.purchase_number,p.purchase_date,p.total_amount,p.paid_amount,p.balance,s.supplier_name,s.phone,DATEDIFF(?,DATE(p.purchase_date)) age_days,".$ageSql." age_bucket".$base." ORDER BY p.purchase_date ASC,p.id ASC";
$q=$conn->prepare($sql);$q->execute(array_merge([$asAt,$asAt,$asAt,$asAt],$params));$allBills=$q->fetchAll(PDO::FETCH_ASSOC);
$totals=['Current (0–30)'=>0.0,'31–60 days'=>0.0,'61–90 days'=>0.0,'Over 90 days'=>0.0];$totalDue=0.0;
foreach($allBills as $r){$totals[$r['age_bucket']]+=(float)$r['balance'];$totalDue+=(float)$r['balance'];}
$bills=in_array($bucket,array_keys($totals),true)?array_values(array_filter($allBills,fn($r)=>$r['age_bucket']===$bucket)):$allBills;
$exportRows=[];foreach($bills as $r)$exportRows[]=[$r['purchase_number'],$r['supplier_name'],$r['phone'],$r['purchase_date'],$r['age_days'],$r['age_bucket'],$r['total_amount'],$r['paid_amount'],$r['balance']];
dibonkeReportExport('aged_payables_as_at_'.$asAt,['Purchase','Supplier','Phone','Purchase Date','Age Days','Age Bucket','Bill Total','Paid','Balance'],$exportRows);
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><?php dibonkeReportPrintStyles(); ?>
<style>
.ap-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.ap-tabs a{padding:9px 12px;background:#edf2f7;border-radius:8px;text-decoration:none;color:#17324d;font-size:12px;font-weight:800}.ap-panel,.ap-card{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:15px}.ap-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px;background:#fff;border:1px solid #e5eaf0;border-radius:10px;margin:14px 0}.ap-filter label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:800}.ap-filter input,.ap-filter select{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.ap-btn{background:#174a7e;color:#fff;border:0;padding:10px 14px;border-radius:7px;font-weight:800}.ap-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:15px 0}.ap-card span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}.ap-card strong{display:block;margin-top:7px;font-size:18px;color:#17324d}.ap-table{width:100%;border-collapse:collapse}.ap-table th,.ap-table td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px;white-space:nowrap}.ap-table th{background:#f3f6fa;color:#475569}.ap-num{text-align:right!important}.ap-muted{color:#64748b}@media(max-width:900px){.ap-cards{grid-template-columns:repeat(2,1fr)}}@media(max-width:550px){.ap-cards{grid-template-columns:1fr}}
</style>
<h1>Aged Payables</h1><p class="ap-muted">Outstanding supplier purchases grouped by purchase age.</p>
<nav class="ap-tabs"><a href="<?=BASE_URL?>/modules/finance/index.php">Finance Overview</a><?php if(canAccessModule('aged_receivables')): ?><a href="<?=BASE_URL?>/modules/finance/aged_receivables.php">Aged Receivables</a><?php endif; ?><a href="<?=BASE_URL?>/modules/finance/aged_payables.php">Aged Payables</a><a href="<?=BASE_URL?>/modules/finance/trial_balance.php">Trial Balance</a></nav>
<form class="ap-filter" method="get"><label>As at<input type="date" name="as_at" value="<?=e($asAt)?>"></label><label>Supplier / purchase<input name="q" value="<?=e($search)?>" placeholder="Search supplier, phone or purchase"></label><label>Age bucket<select name="bucket"><option value="">All buckets</option><?php foreach(array_keys($totals) as $b):?><option value="<?=e($b)?>" <?=$bucket===$b?'selected':''?>><?=e($b)?></option><?php endforeach;?></select></label><button class="ap-btn">Apply</button><a href="<?=BASE_URL?>/modules/finance/aged_payables.php">Reset</a></form>
<?php dibonkeReportActions('aged_payables'); ?>
<section class="ap-cards"><div class="ap-card"><span>Total Outstanding</span><strong><?=e($currency)?> <?=number_format($totalDue,2)?></strong></div><?php foreach($totals as $label=>$amount):?><div class="ap-card"><span><?=e($label)?></span><strong><?=e($currency)?> <?=number_format($amount,2)?></strong></div><?php endforeach;?></section>
<section class="ap-panel"><h2>Outstanding supplier bills</h2><div style="overflow:auto"><table class="ap-table"><thead><tr><th>Purchase</th><th>Supplier</th><th>Purchase Date</th><th>Age</th><th>Age Bucket</th><th class="ap-num">Bill Total</th><th class="ap-num">Paid</th><th class="ap-num">Balance</th></tr></thead><tbody><?php if(!$bills):?><tr><td colspan="8" class="ap-muted">No outstanding supplier bills match this report.</td></tr><?php endif;?><?php foreach($bills as $r):?><tr><td><?=e($r['purchase_number'])?></td><td><?=e($r['supplier_name'])?><?php if($r['phone']):?><br><small class="ap-muted"><?=e($r['phone'])?></small><?php endif;?></td><td><?=e($r['purchase_date'])?></td><td><?=number_format((int)$r['age_days'])?> days</td><td><?=e($r['age_bucket'])?></td><td class="ap-num"><?=number_format((float)$r['total_amount'],2)?></td><td class="ap-num"><?=number_format((float)$r['paid_amount'],2)?></td><td class="ap-num"><strong><?=number_format((float)$r['balance'],2)?></strong></td></tr><?php endforeach;?></tbody></table></div></section>
<p class="ap-muted" style="font-size:11px;margin-top:12px">Ageing is calculated from purchase date because the current purchase schema does not contain contractual due dates. “Current (0–30)” means purchases recorded within the last 30 days, not necessarily invoices that are not yet overdue.</p>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>
