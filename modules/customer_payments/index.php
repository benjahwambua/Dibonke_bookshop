<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/report_tools.php';

$from=trim((string)($_GET['from']??date('Y-m-01')));
$to=trim((string)($_GET['to']??date('Y-m-d')));
$valid='/^\\d{4}-\\d{2}-\\d{2}$/';
if(!preg_match($valid,$from))$from=date('Y-m-01');
if(!preg_match($valid,$to))$to=date('Y-m-d');
if($from>$to){[$from,$to]=[$to,$from];}
$search=trim((string)($_GET['q']??''));
$method=trim((string)($_GET['method']??''));
$where=['DATE(cp.payment_date) BETWEEN ? AND ?'];$params=[$from,$to];
if($search!==''){$where[]='(c.customer_name LIKE ? OR c.phone LIKE ? OR cp.receipt_number LIKE ? OR cp.transaction_reference LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like,$like);}
if(in_array($method,['Cash','M-Pesa','Bank','Cheque','Other'],true)){$where[]='cp.payment_method=?';$params[]=$method;}
$recentQ=$conn->prepare("SELECT cp.*,c.customer_name,c.phone,u.full_name FROM customer_payments cp JOIN customers c ON c.id=cp.customer_id LEFT JOIN users u ON u.id=cp.user_id WHERE ".implode(' AND ',$where)." ORDER BY cp.payment_date DESC,cp.id DESC");
$recentQ->execute($params);$recent=$recentQ->fetchAll(PDO::FETCH_ASSOC);
$customers=$conn->query("SELECT id,customer_name,phone,balance FROM customers WHERE status='Active' AND balance>0 ORDER BY customer_name")->fetchAll();
$total=(float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM customers")->fetchColumn();
$month=(float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM customer_payments WHERE YEAR(payment_date)=YEAR(CURDATE()) AND MONTH(payment_date)=MONTH(CURDATE())")->fetchColumn();
$periodTotal=0.0;$exportRows=[];foreach($recent as $p){$periodTotal+=(float)$p['amount'];$exportRows[]=[$p['receipt_number'],$p['payment_date'],$p['customer_name'],$p['phone'],$p['amount'],$p['payment_method'],$p['transaction_reference']??'',$p['full_name']??''];}
dibonkeReportExport('customer_payments_'.$from.'_to_'.$to,['Receipt','Date','Customer','Phone','Amount','Method','Reference','Received By'],$exportRows);
$flash=$_SESSION['payment_flash']??null;unset($_SESSION['payment_flash']);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';?>
<main class="content"><?php dibonkeReportPrintStyles(); ?>
<style>.grid{display:grid;grid-template-columns:360px minmax(0,1fr);gap:18px}.panel{background:#fff;padding:20px;border-radius:12px;box-shadow:0 1px 4px #0001;min-width:0}.stats{display:flex;gap:15px;margin:15px 0}.stat{background:#fff;padding:15px;border-radius:10px;flex:1}.stat strong{display:block;font-size:22px}.field{margin:12px 0}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}.field input,.field select,.filter input,.filter select{width:100%;padding:10px;box-sizing:border-box;border:1px solid #dbe3ec;border-radius:7px}.btn{border:0;border-radius:7px;padding:11px 15px;background:#004a99;color:#fff;font-weight:700;cursor:pointer;width:100%}.flash{padding:12px;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:12px}.table{width:100%;border-collapse:collapse}.table th{background:#004a99;color:#fff;padding:10px;text-align:left}.table td{padding:10px;border-bottom:1px solid #e5e7eb;font-size:13px}.filter{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:9px;align-items:end;margin:12px 0}.filter label{font-size:11px;font-weight:700}.filter label input,.filter label select{display:block;margin-top:5px}.filter button{padding:10px;border:0;border-radius:7px;background:#174a7e;color:#fff;font-weight:700}@media(max-width:1000px){.grid{grid-template-columns:1fr}}@media(max-width:700px){.filter{grid-template-columns:1fr 1fr}.stats{flex-direction:column}}</style>
<h1>Customer Payments</h1><p>Receive payments against customer credit and keep balances accurate.</p>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<div class="stats"><div class="stat"><strong>KES <?=number_format($total,2)?></strong><small>Outstanding Credit</small></div><div class="stat"><strong>KES <?=number_format($month,2)?></strong><small>Payments This Month</small></div><div class="stat"><strong>KES <?=number_format($periodTotal,2)?></strong><small>Filtered Collections</small></div></div>
<div class="grid"><div class="panel"><h3>Receive Payment</h3><form method="post" action="store.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="field"><label>Customer</label><select name="customer_id" required><option value="">Select customer</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>"><?=e($c['customer_name'])?> — KES <?=number_format((float)$c['balance'],2)?></option><?php endforeach;?></select></div>
<div class="field"><label>Amount</label><input type="number" name="amount" min=".01" step=".01" required></div>
<div class="field"><label>Payment Method</label><select name="payment_method" id="cp_method"><option>Cash</option><option>M-Pesa</option><option>Bank</option><option>Cheque</option><option>Other</option></select></div><div class="field" id="cp_phone_box" style="display:none"><label>M-PESA Phone</label><input name="mpesa_phone" placeholder="07XXXXXXXX" maxlength="15"></div>
<div class="field"><label>Transaction Reference</label><input name="transaction_reference" maxlength="100"></div>
<div class="field"><label>Notes</label><input name="notes" maxlength="500"></div><button class="btn">Record Payment</button></form></div>
<div class="panel"><h3>Payment History</h3><form class="filter" method="get"><label>From<input type="date" name="from" value="<?=e($from)?>"></label><label>To<input type="date" name="to" value="<?=e($to)?>"></label><label>Method<select name="method"><option value="">All methods</option><?php foreach(['Cash','M-Pesa','Bank','Cheque','Other'] as $m):?><option value="<?=e($m)?>" <?=$method===$m?'selected':''?>><?=e($m)?></option><?php endforeach;?></select></label><label>Search<input name="q" value="<?=e($search)?>" placeholder="Customer, phone, receipt..."></label><button>Filter</button></form><?php dibonkeReportActions('customer_payments'); ?><div style="overflow:auto"><table class="table"><thead><tr><th>Receipt</th><th>Date</th><th>Customer</th><th>Amount</th><th>Method</th><th>Reference</th><th>Received By</th></tr></thead><tbody><?php foreach($recent as $p):?><tr><td><?=e($p['receipt_number'])?></td><td><?=e($p['payment_date'])?></td><td><a href="<?=BASE_URL?>/modules/customers/view.php?id=<?=(int)$p['customer_id']?>"><?=e($p['customer_name'])?></a></td><td>KES <?=number_format((float)$p['amount'],2)?></td><td><?=e($p['payment_method'])?></td><td><?=e($p['transaction_reference']??'')?></td><td><?=e($p['full_name']??'—')?></td></tr><?php endforeach;?><?php if(!$recent):?><tr><td colspan="7">No customer payments match these filters.</td></tr><?php endif;?></tbody></table></div></div></div></main><?php require_once __DIR__.'/../../includes/footer.php';?>