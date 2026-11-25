<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../includes/report_tools.php';
require_once __DIR__.'/../../includes/accounting_posting.php';
requireModuleAccess('journals');
$tables=array_flip($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
$ready=isset($tables['accounts'],$tables['journal_entries'],$tables['journal_lines']);
$flash=$_SESSION['journal_flash']??'';unset($_SESSION['journal_flash']);
if($ready&&$_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'');
 try{
  if($action==='create'){
   requireModulePermission('journals','create');
   $date=(string)($_POST['journal_date']??date('Y-m-d'));$description=trim((string)($_POST['description']??''));$reference=trim((string)($_POST['reference']??''));
   if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$description==='')throw new RuntimeException('Enter a valid journal date and description.');
   dibonkeAccountingAssertPeriodOpen($conn,$date);
   $ids=$_POST['account_id']??[];$debits=$_POST['debit']??[];$credits=$_POST['credit']??[];$notes=$_POST['line_description']??[];
   if(!is_array($ids)||!is_array($debits)||!is_array($credits))throw new RuntimeException('Invalid journal lines.');
   $lines=[];$totalD=0.0;$totalC=0.0;
   foreach($ids as $i=>$raw){$aid=(int)$raw;$d=round((float)($debits[$i]??0),2);$c=round((float)($credits[$i]??0),2);
    if($aid===0&&$d==0&&$c==0)continue;
    if($aid<1||$d<0||$c<0||(($d>0)==($c>0)))throw new RuntimeException('Each used line must have an account and either a debit or a credit, not both.');
    $lines[]=['account_id'=>$aid,'debit'=>$d,'credit'=>$c,'description'=>trim((string)($notes[$i]??''))];$totalD+=$d;$totalC+=$c;
   }
   if(count($lines)<2||$totalD<=0||abs($totalD-$totalC)>0.009)throw new RuntimeException('A journal needs at least two lines and equal debit and credit totals.');
   $conn->beginTransaction();
   $accountCheck=$conn->prepare("SELECT id FROM accounts WHERE id=? AND status='Active' FOR UPDATE");
   foreach($lines as $line){$accountCheck->execute([$line['account_id']]);if(!$accountCheck->fetchColumn())throw new RuntimeException('A selected account is inactive or does not exist.');}
   $tmp='TMP-'.strtoupper(bin2hex(random_bytes(6)));
   $q=$conn->prepare("INSERT INTO journal_entries(journal_number,journal_date,description,reference,status,total_debit,total_credit,created_by) VALUES(?,?,?,?,'Draft',?,?,?)");
   $q->execute([$tmp,$date,$description,$reference?:null,$totalD,$totalC,(int)$_SESSION['user_id']]);$jid=(int)$conn->lastInsertId();
   $number='JV-'.date('ymd').'-'.str_pad((string)$jid,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE journal_entries SET journal_number=? WHERE id=?")->execute([$number,$jid]);
   $ins=$conn->prepare("INSERT INTO journal_lines(journal_entry_id,account_id,line_description,debit,credit) VALUES(?,?,?,?,?)");
   foreach($lines as $line)$ins->execute([$jid,$line['account_id'],$line['description']?:null,$line['debit'],$line['credit']]);
   $conn->commit();$flash='Draft journal '.$number.' saved. Post it after reviewing.';
  }elseif($action==='post'){
   requireModulePermission('journals','edit');$id=(int)($_POST['id']??0);$conn->beginTransaction();
   $q=$conn->prepare("SELECT * FROM journal_entries WHERE id=? FOR UPDATE");$q->execute([$id]);$entry=$q->fetch(PDO::FETCH_ASSOC);
   if(!$entry||$entry['status']!=='Draft')throw new RuntimeException('Only draft journals can be posted.');
   dibonkeAccountingAssertPeriodOpen($conn,(string)$entry['journal_date']);
   $q=$conn->prepare("SELECT COUNT(*) lines,COALESCE(SUM(debit),0) debit,COALESCE(SUM(credit),0) credit FROM journal_lines WHERE journal_entry_id=?");$q->execute([$id]);$tot=$q->fetch(PDO::FETCH_ASSOC);
   if((int)$tot['lines']<2||abs((float)$tot['debit']-(float)$tot['credit'])>0.009||(float)$tot['debit']<=0)throw new RuntimeException('Journal failed the balance check and cannot be posted.');
   $conn->prepare("UPDATE journal_entries SET status='Posted',total_debit=?,total_credit=?,posted_by=?,posted_at=NOW() WHERE id=? AND status='Draft'")->execute([$tot['debit'],$tot['credit'],(int)$_SESSION['user_id'],$id]);
   $conn->commit();$flash='Journal '.$entry['journal_number'].' posted successfully.';
  }else throw new RuntimeException('Unknown journal action.');
 }catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$flash='Journal action failed: '.$e->getMessage();}
 $_SESSION['journal_flash']=$flash;header('Location:journals.php');exit;
}
$accounts=$ready?$conn->query("SELECT id,account_code,account_name,account_type FROM accounts WHERE status='Active' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC):[];
$entries=$ready?$conn->query("SELECT j.*,u.full_name creator FROM journal_entries j LEFT JOIN users u ON u.id=j.created_by ORDER BY j.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC):[];
$linesByEntry=[];if($ready){$q=$conn->query("SELECT l.*,a.account_code,a.account_name FROM journal_lines l JOIN accounts a ON a.id=l.account_id ORDER BY l.id");foreach($q->fetchAll(PDO::FETCH_ASSOC) as $line)$linesByEntry[$line['journal_entry_id']][]=$line;}
$exportRows=[];foreach($entries as $entry){$exportRows[]=[$entry['journal_number'],$entry['journal_date'],$entry['description'],$entry['reference'],$entry['status'],$entry['total_debit'],$entry['total_credit'],$entry['creator']??''];}dibonkeReportExport('journal_register',['Journal No.','Date','Description','Reference','Status','Debit','Credit','Created By'],$exportRows);
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><?php dibonkeReportPrintStyles(); ?><style>
.acct-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px}.acct-tabs a{padding:9px 12px;background:#edf2f7;border-radius:8px;text-decoration:none;color:#17324d;font-size:12px;font-weight:800}.acct-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px;margin:14px 0}.acct-form{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px}.acct-form label{font-size:11px;font-weight:800;color:#475569;display:flex;flex-direction:column;gap:5px}.acct-form input,.acct-form select{padding:9px;border:1px solid #cbd5e1;border-radius:7px;min-width:0}.acct-btn{background:#174a7e;color:#fff;border:0;border-radius:7px;padding:10px 14px;font-weight:800;cursor:pointer}.acct-table{width:100%;border-collapse:collapse}.acct-table th,.acct-table td{text-align:left;padding:9px;border-bottom:1px solid #edf1f5;font-size:12px}.acct-table th{background:#f3f6fa}.acct-num{text-align:right!important;white-space:nowrap}.acct-lines{width:100%;border-collapse:collapse;margin:14px 0}.acct-lines td{padding:5px}.acct-lines input,.acct-lines select{width:100%;padding:8px;border:1px solid #d4dce5;border-radius:6px}.acct-flash{padding:12px;background:#eff6ff;border-radius:8px}.acct-muted{color:#64748b}@media(max-width:800px){.acct-form{grid-template-columns:1fr 1fr}.acct-panel{overflow:auto}}@media(max-width:520px){.acct-form{grid-template-columns:1fr}}
</style>
<h1>Journal Entries</h1><?php dibonkeReportActions('journal_register'); ?><p class="acct-muted">Create balanced journals, review drafts and post approved entries to the accounting ledger.</p>
<nav class="acct-tabs"><a href="chart_of_accounts.php">Chart of Accounts</a><a href="journals.php">Journal Entries</a><a href="general_ledger.php">General Ledger</a><a href="trial_balance.php">Trial Balance</a></nav>
<?php if($flash):?><p class="acct-flash"><?=e($flash)?></p><?php endif;?>
<?php if(!$ready):?><section class="acct-panel"><h2>Database setup required</h2><p>Import <code>database/dibonke_accounting_hr.sql</code> and <code>database/dibonke_double_entry_accounting.sql</code>.</p></section><?php else:?>
<?php if(hasModulePermission('journals','create')):?><section class="acct-panel"><h2>New journal draft</h2><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><div class="acct-form"><label>Journal date<input type="date" name="journal_date" value="<?=date('Y-m-d')?>" required></label><label style="grid-column:span 2">Description<input name="description" maxlength="255" required placeholder="e.g. Opening balances"></label><label>Reference<input name="reference" maxlength="100" placeholder="Optional"></label></div><div style="overflow:auto"><table class="acct-lines"><thead><tr><th style="min-width:220px">Account</th><th style="min-width:180px">Line description</th><th style="min-width:120px">Debit (<?=$currency?>)</th><th style="min-width:120px">Credit (<?=$currency?>)</th></tr></thead><tbody><?php for($i=0;$i<8;$i++):?><tr><td><select name="account_id[]"><option value="0">Select account</option><?php foreach($accounts as $a):?><option value="<?=(int)$a['id']?>"><?=e($a['account_code'].' — '.$a['account_name'])?></option><?php endforeach;?></select></td><td><input name="line_description[]" maxlength="255"></td><td><input type="number" name="debit[]" min="0" step="0.01" value="0.00"></td><td><input type="number" name="credit[]" min="0" step="0.01" value="0.00"></td></tr><?php endfor;?></tbody></table></div><button class="acct-btn">Save as draft</button><p class="acct-muted">At least two lines are required. Each line must contain either a debit or a credit. Debits must equal credits.</p></form></section><?php endif;?>
<section class="acct-panel"><h2>Journal register</h2><div style="overflow:auto"><table class="acct-table"><thead><tr><th>Journal</th><th>Date</th><th>Description</th><th>Reference</th><th>Status</th><th class="acct-num">Debit</th><th class="acct-num">Credit</th><th>Action</th></tr></thead><tbody>
<?php if(!$entries):?><tr><td colspan="8" class="acct-muted">No journals have been created.</td></tr><?php endif;?>
<?php foreach($entries as $e1):?><tr><td><strong><?=e($e1['journal_number'])?></strong></td><td><?=e($e1['journal_date'])?></td><td><?=e($e1['description'])?></td><td><?=e($e1['reference']??'—')?></td><td><?=e($e1['status'])?></td><td class="acct-num"><?=number_format((float)$e1['total_debit'],2)?></td><td class="acct-num"><?=number_format((float)$e1['total_credit'],2)?></td><td><?php if($e1['status']==='Draft'&&hasModulePermission('journals','edit')):?><form method="post" onsubmit="return confirm('Post this journal? Posted journals cannot be edited.')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?=(int)$e1['id']?>"><button class="acct-btn">Post</button></form><?php else:?>—<?php endif;?></td></tr><tr><td colspan="8"><details><summary>View journal lines</summary><table class="acct-table"><thead><tr><th>Account</th><th>Description</th><th class="acct-num">Debit</th><th class="acct-num">Credit</th></tr></thead><tbody><?php foreach($linesByEntry[$e1['id']]??[] as $ln):?><tr><td><?=e($ln['account_code'].' — '.$ln['account_name'])?></td><td><?=e($ln['line_description']??'')?></td><td class="acct-num"><?=number_format((float)$ln['debit'],2)?></td><td class="acct-num"><?=number_format((float)$ln['credit'],2)?></td></tr><?php endforeach;?></tbody></table></details></td></tr><?php endforeach;?>
</tbody></table></div></section><?php endif;?>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>
