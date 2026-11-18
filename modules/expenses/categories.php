<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/audit.php';
requireModuleAccess('expenses');

$flash=$_SESSION['expense_category_flash']??'';unset($_SESSION['expense_category_flash']);
$editId=(int)($_GET['edit']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['action']??'');
    $permissionAction=$action==='create'?'create':($action==='delete'?'delete':'edit');
    requireModulePermission('expenses',$permissionAction);
    try{
        if($action==='create'||$action==='update'){
            $id=(int)($_POST['id']??0);
            $name=trim((string)($_POST['category_name']??''));
            $status=($_POST['status']??'Active')==='Inactive'?'Inactive':'Active';
            if($name===''||mb_strlen($name)>100)throw new RuntimeException('Enter a category name of up to 100 characters.');
            $q=$conn->prepare("SELECT id FROM expense_categories WHERE LOWER(category_name)=LOWER(?) AND id<>? LIMIT 1");
            $q->execute([$name,$action==='update'?$id:0]);
            if($q->fetchColumn())throw new RuntimeException('An expense category with that name already exists.');
            if($action==='create'){
                $q=$conn->prepare("INSERT INTO expense_categories(category_name,status) VALUES(?,?)");$q->execute([$name,$status]);$id=(int)$conn->lastInsertId();
                recordAuditEvent($conn,'Create','expenses','Created expense category '.$name,'expense_category',$id,null,['name'=>$name,'status'=>$status]);
                $flash='Expense category created.';
            }else{
                $q=$conn->prepare("UPDATE expense_categories SET category_name=?,status=? WHERE id=?");$q->execute([$name,$status,$id]);
                if(!$q->rowCount()){ $check=$conn->prepare("SELECT id FROM expense_categories WHERE id=?");$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('Category not found.');}
                recordAuditEvent($conn,'Update','expenses','Updated expense category '.$name,'expense_category',$id,null,['name'=>$name,'status'=>$status]);
                $flash='Expense category updated.';
            }
        }elseif($action==='delete'){
            $id=(int)($_POST['id']??0);
            $q=$conn->prepare("SELECT category_name FROM expense_categories WHERE id=?");$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Category not found.');
            $q=$conn->prepare("SELECT COUNT(*) FROM expenses WHERE category_id=?");$q->execute([$id]);
            if((int)$q->fetchColumn()>0)throw new RuntimeException('This category is used by expense records. Set it to Inactive instead so historical reports remain intact.');
            $conn->prepare("DELETE FROM expense_categories WHERE id=?")->execute([$id]);
            recordAuditEvent($conn,'Delete','expenses','Deleted expense category '.$row['category_name'],'expense_category',$id,['name'=>$row['category_name']],null);
            $flash='Expense category deleted.';
        }else throw new RuntimeException('Invalid category action.');
    }catch(Throwable $e){$flash='Category action failed: '.$e->getMessage();}
    $_SESSION['expense_category_flash']=$flash;header('Location: categories.php');exit;
}
$edit=null;
if($editId){$q=$conn->prepare("SELECT * FROM expense_categories WHERE id=?");$q->execute([$editId]);$edit=$q->fetch(PDO::FETCH_ASSOC)?:null;}
$rows=$conn->query("SELECT c.*,COUNT(e.id) expense_count FROM expense_categories c LEFT JOIN expenses e ON e.category_id=c.id GROUP BY c.id ORDER BY c.status,c.category_name")->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.ec-wrap{max-width:1100px}.ec-card{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:18px;margin:16px 0}.ec-form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.ec-form label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700}.ec-form input,.ec-form select{padding:10px;border:1px solid #cbd5e1;border-radius:7px;min-width:220px}.ec-btn{padding:10px 14px;border:0;border-radius:7px;background:#174a7e;color:white;font-weight:800;cursor:pointer}.ec-table{width:100%;border-collapse:collapse}.ec-table th,.ec-table td{padding:11px;text-align:left;border-bottom:1px solid #edf1f5;font-size:13px}.ec-table th{background:#f3f6fa}.ec-muted{color:#64748b}.ec-danger{background:#fee2e2;color:#991b1b}
</style>
<div class="ec-wrap">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap"><div><h1>Expense Categories</h1><p class="ec-muted">Add, rename, activate, deactivate or remove expense categories.</p></div><a class="ec-btn" href="<?=BASE_URL?>/modules/expenses/index.php">Back to Expenses</a></div>
<?php if($flash):?><p class="ec-card"><?=e($flash)?></p><?php endif;?>
<section class="ec-card"><h2><?=$edit?'Edit Category':'Add Category'?></h2>
<?php if(($edit&&!hasModulePermission('expenses','edit'))||(!$edit&&!hasModulePermission('expenses','create'))):?><p class="ec-muted">You do not have permission to manage categories.</p><?php else:?>
<form method="post" class="ec-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="<?=$edit?'update':'create'?>"><?php if($edit):?><input type="hidden" name="id" value="<?=(int)$edit['id']?>"><?php endif;?>
<label>Category name<input name="category_name" maxlength="100" required value="<?=e($edit['category_name']??'')?>"></label>
<label>Status<select name="status"><option value="Active" <?=($edit['status']??'Active')==='Active'?'selected':''?>>Active</option><option value="Inactive" <?=($edit['status']??'')==='Inactive'?'selected':''?>>Inactive</option></select></label>
<button class="ec-btn"><?=$edit?'Save Changes':'Add Category'?></button><?php if($edit):?><a href="categories.php">Cancel</a><?php endif;?></form><?php endif;?></section>
<section class="ec-card"><h2>Manage Categories</h2><div style="overflow:auto"><table class="ec-table"><thead><tr><th>Category</th><th>Expenses</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr><td><strong><?=e($row['category_name'])?></strong></td><td><?=(int)$row['expense_count']?></td><td><?=e($row['status'])?></td><td style="white-space:nowrap"><?php if(hasModulePermission('expenses','edit')):?><a href="?edit=<?=(int)$row['id']?>">Edit</a><?php endif;?> <?php if(hasModulePermission('expenses','delete')&&(int)$row['expense_count']===0):?><form method="post" style="display:inline" onsubmit="return confirm('Delete this unused expense category?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="ec-btn ec-danger" type="submit">Delete</button></form><?php elseif(hasModulePermission('expenses','delete')):?><span class="ec-muted">In use — deactivate instead</span><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="4">No expense categories configured.</td></tr><?php endif;?></tbody></table></div></section>
</div></main><?php require_once __DIR__.'/../../includes/footer.php';?>
