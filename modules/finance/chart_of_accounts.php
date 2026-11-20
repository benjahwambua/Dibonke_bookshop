<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('chart_of_accounts');
$tables=array_flip($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
$ready=isset($tables['accounts']);
$flash=$_SESSION['coa_flash']??'';unset($_SESSION['coa_flash']);
$editId=(int)($_GET['edit']??0);$editAccount=null;
if($ready && $_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$action=(string)($_POST['action']??'create');$permission=$action==='create'?'create':($action==='delete'?'delete':'edit');requireModulePermission('chart_of_accounts',$permission);
 try{
  if($action==='create'||$action==='update'){
   $id=(int)($_POST['id']??0);$code=strtoupper(trim((string)($_POST['account_code']??'')));$name=trim((string)($_POST['account_name']??''));$type=(string)($_POST['account_type']??'');$description=trim((string)($_POST['description']??''));$status=($_POST['status']??'Active')==='Inactive'?'Inactive':'Active';
   if(!preg_match('/^[A-Z0-9-]{2,20}$/',$code)||$name===''||!in_array($type,['Asset','Liability','Equity','Income','Expense'],true))throw new RuntimeException('Enter a valid code, name and account type.');
   $q=$conn->prepare("SELECT id FROM accounts WHERE account_code=? AND id<>?");$q->execute([$code,$action==='update'?$id:0]);if($q->fetchColumn())throw new RuntimeException('That account code is already in use.');
   if($action==='create'){$q=$conn->prepare("INSERT INTO accounts(account_code,account_name,account_type,description,status) VALUES(?,?,?,?,?)");$q->execute([$code,$name,$type,$description?:null,$status]);$_SESSION['coa_flash']='Account created successfully.';}
   else{$q=$conn->prepare("SELECT is_system FROM accounts WHERE id=?");$q->execute([$id]);$system=$q->fetchColumn();if($system===false)throw new RuntimeException('Account not found.');$q=$conn->prepare("UPDATE accounts SET account_code=?,account_name=?,account_type=?,description=?,status=? WHERE id=?");$q->execute([$code,$name,$type,$description?:null,$status,$id]);$_SESSION['coa_flash']='Account updated successfully.';}
  }elseif($action==='delete'){
   $id=(int)($_POST['id']??0);$q=$conn->prepare("SELECT account_code,account_name,is_system FROM accounts WHERE id=?");$q->execute([$id]);$account=$q->fetch(PDO::FETCH_ASSOC);if(!$account)throw new RuntimeException('Account not found.');if((int)$account['is_system']===1)throw new RuntimeException('System accounts cannot be deleted.');
   $journalTable=$conn->query("SHOW TABLES LIKE 'journal_lines'")->fetchColumn();if($journalTable){$q=$conn->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id=?");$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('This account is referenced by journal entries. Set it to Inactive instead.');}
   $conn->prepare("DELETE FROM accounts WHERE id=?")->execute([$id]);$_SESSION['coa_flash']='Account deleted.';
  }else throw new RuntimeException('Invalid account action.');
 }catch(Throwable $e){$_SESSION['coa_flash']='Account action failed: '.$e->getMessage();}
 header('Location:index.php');exit;
}
if($ready&&$editId){$q=$conn->prepare("SELECT * FROM accounts WHERE id=?");$q->execute([$editId]);$editAccount=$q->fetch(PDO::FETCH_ASSOC)?:null;}
$accounts=$ready?$conn->query("SELECT id,account_code,account_name,account_type,is_system,status,description FROM accounts ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC):[];
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.coa-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:16px 0}.coa-card,.coa-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px}.coa-card span{display:block;color:#64748b;font-size:11px;font-weight:800;text-transform:uppercase}.coa-card strong{display:block;font-size:23px;color:#17324d;margin-top:5px}.coa-form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.coa-form label{font-size:11px;font-weight:800;display:flex;flex-direction:column;gap:5px}.coa-form input,.coa-form select{padding:10px;border:1px solid #cbd5e1;border-radius:7px;min-width:150px}.coa-btn{background:#174a7e;color:white;border:0;border-radius:7px;padding:10px 14px;font-weight:800}.coa-table{width:100%;border-collapse:collapse}.coa-table th,.coa-table td{text-align:left;padding:10px;border-bottom:1px solid #edf1f5;font-size:12px}.coa-table th{background:#f3f6fa}.muted{color:#64748b}@media(max-width:900px){.coa-grid{grid-template-columns:repeat(2,1fr)}}
</style>
<h1>Chart of Accounts</h1><p class="muted">The account structure for Dibonke's double-entry accounting system.</p>
<?php if($flash):?><p class="coa-panel"><?=e($flash)?></p><?php endif;?>
<?php if(!$ready):?><section class="coa-panel"><h2>Database setup required</h2><p>Import <code>database/dibonke_accounting_hr.sql</code> after pulling this branch.</p></section>
<?php else:
$counts=['Asset'=>0,'Liability'=>0,'Equity'=>0,'Income'=>0,'Expense'=>0];foreach($accounts as $a){$counts[$a['account_type']]++;}
?><section class="coa-grid"><?php foreach($counts as $type=>$count):?><div class="coa-card"><span><?=e($type)?> accounts</span><strong><?=$count?></strong></div><?php endforeach;?></section>
<?php if(hasModulePermission('chart_of_accounts',$editAccount?'edit':'create')):?><section class="coa-panel"><h2><?=$editAccount?'Edit account':'Add account'?></h2><form method="post" class="coa-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="<?=$editAccount?'update':'create'?>"><?php if($editAccount):?><input type="hidden" name="id" value="<?=(int)$editAccount['id']?>"><?php endif;?><label>Code<input name="account_code" maxlength="20" required value="<?=e($editAccount['account_code']??'')?>"></label><label>Account name<input name="account_name" maxlength="120" required value="<?=e($editAccount['account_name']??'')?>"></label><label>Type<select name="account_type"><?php foreach(array_keys($counts) as $t):?><option <?=($editAccount['account_type']??'Asset')===$t?'selected':''?>><?=e($t)?></option><?php endforeach;?></select></label><label>Description<input name="description" maxlength="500" value="<?=e($editAccount['description']??'')?>"></label><label>Status<select name="status"><option value="Active" <?=($editAccount['status']??'Active')==='Active'?'selected':''?>>Active</option><option value="Inactive" <?=($editAccount['status']??'')==='Inactive'?'selected':''?>>Inactive</option></select></label><button class="coa-btn"><?=$editAccount?'Save Changes':'Add account'?></button><?php if($editAccount):?><a href="index.php">Cancel</a><?php endif;?></form></section><?php endif;?>
<section class="coa-panel" style="margin-top:14px;overflow:auto"><h2>Account register</h2><table class="coa-table"><thead><tr><th>Code</th><th>Account</th><th>Type</th><th>System</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach($accounts as $a):?><tr><td><strong><?=e($a['account_code'])?></strong></td><td><?=e($a['account_name'])?></td><td><?=e($a['account_type'])?></td><td><?=$a['is_system']?'Yes':'No'?></td><td><?=e($a['status'])?></td><td><?php if(hasModulePermission('chart_of_accounts','edit')):?><a href="?edit=<?=(int)$a['id']?>">Edit</a><?php endif;?> <?php if(hasModulePermission('chart_of_accounts','delete')&&!$a['is_system']):?><form method="post" style="display:inline" onsubmit="return confirm('Delete this unused account?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$a['id']?>"><button class="coa-btn" type="submit">Delete</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></section>
<p class="muted">This phase establishes the account register only. Journal posting, general ledger balances and the Trial Balance follow after transaction-to-account mappings are defined and validated.</p><?php endif;?>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>
