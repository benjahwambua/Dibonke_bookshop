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
$like='%'.$search.'%';
$pSql="SELECT 'Purchase' source_type,p.id source_id,p.purchase_number document_number,p.purchase_date document_date,p.total_amount,p.paid_amount,p.balance,s.supplier_name,s.phone
       FROM purchases p JOIN suppliers s ON s.id=p.supplier_id
       WHERE p.status='Received' AND p.balance>0.009 AND DATE(p.purchase_date)<=?";
$pParams=[$asAt];
if($search!==''){$pSql.=" AND (s.supplier_name LIKE ? OR s.phone LIKE ? OR p.purchase_number LIKE ?)";array_push($pParams,$like,$like,$like);}
$p=$conn->prepare($pSql);$p->execute($pParams);$rows=$p->fetchAll(PDO::FETCH_ASSOC);

$gSql="SELECT 'Goods Receipt' source_type,gr.id source_id,gr.grn_number document_number,gr.received_date document_date,gr.total_amount,
       COALESCE(SUM(spa.amount),0)+COALESCE((SELECT SUM(sp2.amount) FROM supplier_payments sp2 WHERE sp2.goods_receipt_id=gr.id),0) paid_amount,
       GREATEST(0,gr.total_amount-COALESCE(SUM(spa.amount),0)-COALESCE((SELECT SUM(sp2.amount) FROM supplier_payments sp2 WHERE sp2.goods_receipt_id=gr.id),0)) balance,
       s.supplier_name,s.phone
       FROM goods_receipts gr JOIN suppliers s ON s.id=gr.supplier_id
       LEFT JOIN supplier_payment_allocations spa ON spa.goods_receipt_id=gr.id
       WHERE DATE(gr.received_date)<=?";
$gParams=[$asAt];
if($search!==''){$gSql.=" AND (s.supplier_name LIKE ? OR s.phone LIKE ? OR gr.grn_number LIKE ?)";array_push($gParams,$like,$like,$like);}
$gSql.=" GROUP BY gr.id,gr.grn_number,gr.received_date,gr.total_amount,s.supplier_name,s.phone
         HAVING balance>0.009 ORDER BY document_date ASC,source_type,source_id";
$g=$conn->prepare($gSql);$g->execute($gParams);$rows=array_merge($rows,$g->fetchAll(PDO::FETCH_ASSOC));

$totals=['Current (0–30)'=>0.0,'31–60 days'=>0.0,'61–90 days'=>0.0,'Over 90 days'=>0.0];$totalDue=0.0;
foreach($rows as &$r){
 $days=max(0,(int)(new DateTimeImmutable($r['document_date']))->diff(new DateTimeImmutable($asAt))->format('%r%a'));
 $r['age_days']=$days;
 $r['age_bucket']=$days<=30?'Current (0–30)':($days<=60?'31–60 days':($days<=90?'61–90 days':'Over 90 days'));
 $totals[$r['age_bucket']]+=(float)$r['balance'];$totalDue+=(float)$r['balance'];
}
unset($r);
usort($rows,static fn($a,$b)=>[$a['document_date'],$a['source_type'],$a['source_id']]<=>[$b['document_date'],$b['source_type'],$b['source_id']]);
$bills=in_array($bucket,array_keys($totals),true)?array_values(array_filter($rows,fn($r)=>$r['age_bucket']===$bucket)):$rows;
$exportRows=[];foreach($bills as $r)$exportRows[]=[$r['source_type'],$r['document_number'],$r['supplier_name'],$r['phone'],$r['document_date'],$r['age_days'],$r['age_bucket'],$r['total_amount'],$r['paid_amount'],$r['balance']];
dibonkeReportExport('aged_payables_as_at_'.$asAt,['Source','Reference','Supplier','Phone','Date','Age Days','Age Bucket','Bill Total','Paid','Balance'],$exportRows);
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><?php dibonkeReportPrintStyles(); ?>
<style>
.ap-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0}.ap-tabs a{padding:9px 12px;background:#edf2f7;border-radius:8px;text-decoration:none;color:#17324d;font-size:12px;font-weight:800}.ap-panel,.ap-card{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:15px}.ap-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px;background:#fff;border:1px solid #e5eaf0;border-radius:10px;margin:14px 0}.ap-filter label{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:800}.ap-filter input,.ap-filter select{padding:9px;border:1px solid #cbd5e1;border-radius:7px}.ap-btn{background:#174a7e;color:#fff;border:0;padding:10px 14px;border-radius:7px;font-weight:800}.ap-cards{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:15px 0}.ap-card span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}.ap-card strong{display:block;margin-top:7px;font-size:18px;color:#17324d}.ap-table{width:100%;border-collapse:collapse}.ap-table th,.ap-table td{padding:10px 9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px;white-space:nowrap}.ap-table th{background:#f3f6fa;color:#475569}.ap-num{text-align:right!important}.ap-muted{color:#64748b}@media(max-width:900px){.ap-cards{grid-template-columns:repeat(2,1fr)}}@media(max-width:550px){.ap-cards{grid-template-columns:1fr}}
</style>
<h1>Aged Payables</h1><p class="ap-muted">Outstanding supplier purchases and goods receipts grouped by document age.</p>
<nav class="ap-tabs"><a href="<?=BASE_URL?>/modules/finance/index.php">Finance Overview</a><?php if(canAccessModule('aged_receivables')): ?><a href="<?=BASE_URL?>/modules/finance/aged_receivables.php">Aged Receivables</a><?php endif; ?><a href="<?=BASE_URL?>/modules/finance/aged_payables.php">Aged Payables</a><a href="<?=BASE_URL?>/modules/finance/trial_balance.php">Trial Balance</a></nav>
<form class="ap-filter" method="get"><label>As at<input type="date" name="as_at" value="<?=e($asAt)?>"></label><label>Supplier / document<input name="q" value="<?=e($search)?>" placeholder="Search supplier, phone or reference"></label><label>Age bucket<select name="bucket"><option value="">All buckets</option><?php foreach(array_keys($totals) as $b):?><option value="<?=e($b)?>" <?=$bucket===$b?'selected':''?>><?=e($b)?></option><?php endforeach;?></select></label><button class="ap-btn">Apply</button><a href="<?=BASE_URL?>/modules/finance/aged_payables.php">Reset</a></form>
<?php dibonkeReportActions('aged_payables'); ?>
<section class="ap-cards"><div class="ap-card"><span>Total Outstanding</span><strong><?=e($currency)?> <?=number_format($totalDue,2)?></strong></div><?php foreach($totals as $label=>$amount):?><div class="ap-card"><span><?=e($label)?></span><strong><?=e($currency)?> <?=number_format($amount,2)?></strong></div><?php endforeach;?></section>
<section class="ap-panel"><h2>Outstanding supplier documents</h2><div style="overflow:auto"><table class="ap-table"><thead><tr><th>Type</th><th>Reference</th><th>Supplier</th><th>Date</th><th>Age</th><th>Age Bucket</th><th class="ap-num">Bill Total</th><th class="ap-num">Paid</th><th class="ap-num">Balance</th></tr></thead><tbody><?php if(!$bills):?><tr><td colspan="9" class="ap-muted">No outstanding supplier documents match this report.</td></tr><?php endif;?><?php foreach($bills as $r):?><tr><td><?=e($r['source_type'])?></td><td><?=e($r['document_number'])?></td><td><?=e($r['supplier_name'])?><?php if($r['phone']):?><br><small class="ap-muted"><?=e($r['phone'])?></small><?php endif;?></td><td><?=e($r['document_date'])?></td><td><?=number_format((int)$r['age_days'])?> days</td><td><?=e($r['age_bucket'])?></td><td class="ap-num"><?=number_format((float)$r['total_amount'],2)?></td><td class="ap-num"><?=number_format((float)$r['paid_amount'],2)?></td><td class="ap-num"><strong><?=number_format((float)$r['balance'],2)?></strong></td></tr><?php endforeach;?></tbody></table></div></section>
<p class="ap-muted" style="font-size:11px;margin-top:12px">Ageing uses the purchase or goods-received date because the current schemas do not store contractual due dates. Historical supplier payments that are not linked to a purchase or GRN must be reconciled before new payments can be allocated.</p>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>
