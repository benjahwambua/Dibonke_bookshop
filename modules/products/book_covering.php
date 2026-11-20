<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/audit.php';
requireModulePermission('products','edit');
$pageTitle='Book Covering Setup';
$flash=$_SESSION['book_covering_flash']??null; unset($_SESSION['book_covering_flash']);

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'save_rule');
        $uid=(int)($_SESSION['user_id']??0);
        if($action==='save_rule'){
            $bookId=(int)($_POST['book_product_id']??0);
            $charge=round((float)($_POST['charge_per_book']??0),2);
            if($bookId<=0) throw new RuntimeException('Choose an exercise book.');
            if($charge<0) throw new RuntimeException('The covering charge cannot be negative.');
            $q=$conn->prepare("SELECT id,product_name FROM products WHERE id=? AND status='Active' AND product_type='Book'");
            $q->execute([$bookId]); $book=$q->fetch(PDO::FETCH_ASSOC);
            if(!$book) throw new RuntimeException('Choose an active book product.');
            $save=$conn->prepare("INSERT INTO book_covering_service_rules(book_product_id,charge_per_book,status,created_by,updated_by) VALUES(?,?,'Active',?,?) ON DUPLICATE KEY UPDATE charge_per_book=VALUES(charge_per_book),status='Active',updated_by=VALUES(updated_by)");
            $save->execute([$bookId,$charge,$uid,$uid]);
            recordAuditEvent($conn,'Update','products','Updated covering service charge for '.$book['product_name'],'book_covering_rule',$bookId,null,['charge_per_book'=>$charge]);
            $_SESSION['book_covering_flash']='Covering charge saved for '.$book['product_name'].'.';
        }elseif($action==='deactivate_rule'){
            $ruleId=(int)($_POST['rule_id']??0);
            $q=$conn->prepare("UPDATE book_covering_service_rules SET status='Inactive',updated_by=? WHERE id=?");
            $q->execute([$uid,$ruleId]);
            recordAuditEvent($conn,'Update','products','Deactivated a book covering service rule','book_covering_rule',$ruleId);
            $_SESSION['book_covering_flash']='Covering rule deactivated.';
        }elseif($action==='internal_use'){
            $rollId=(int)($_POST['roll_product_id']??0);
            $quantity=(int)($_POST['roll_quantity']??0);
            $notes=trim((string)($_POST['use_notes']??''));
            if($rollId<=0||$quantity<=0) throw new RuntimeException('Choose a cover roll and enter at least one whole roll.');
            if($notes==='') throw new RuntimeException('Enter why the roll is being taken for internal covering use.');
            $conn->beginTransaction();
            $q=$conn->prepare("SELECT id,product_name,unit,stock_quantity FROM products WHERE id=? AND status='Active' FOR UPDATE");
            $q->execute([$rollId]); $roll=$q->fetch(PDO::FETCH_ASSOC);
            if(!$roll) throw new RuntimeException('The selected cover roll is unavailable.');
            if(strtolower((string)$roll['unit'])!=='roll') throw new RuntimeException('The selected product must use Roll as its stock unit.');
            $before=(int)$roll['stock_quantity'];
            if($quantity>$before) throw new RuntimeException('Not enough rolls in stock. Available: '.$before.'.');
            $after=$before-$quantity;
            $conn->prepare("UPDATE products SET stock_quantity=? WHERE id=?")->execute([$after,$rollId]);
            $movement=$conn->prepare("INSERT INTO stock_movements(product_id,user_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,notes,movement_date) VALUES(?,?,'Adjustment',?,?,?,'book_covering_internal_use',?, ?, NOW())");
            $movement->execute([$rollId,$uid,$quantity,$before,$after,$rollId,'Internal book-covering use: '.$notes]);
            recordAuditEvent($conn,'Create','inventory','Recorded '.$quantity.' roll(s) used internally for book covering','book_covering_internal_use',$rollId,null,['quantity'=>$quantity,'stock_before'=>$before,'stock_after'=>$after,'notes'=>$notes]);
            $conn->commit();
            $_SESSION['book_covering_flash']='Internal use recorded. '.$quantity.' roll(s) deducted from stock.';
        }else{
            throw new RuntimeException('Unknown action.');
        }
    }catch(Throwable $e){
        if($conn->inTransaction()) $conn->rollBack();
        $_SESSION['book_covering_flash']='Could not save: '.$e->getMessage();
    }
    header('Location: book_covering.php'); exit;
}
$books=$conn->query("SELECT id,product_name,sku FROM products WHERE status='Active' AND product_type='Book' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$rolls=$conn->query("SELECT id,product_name,sku,stock_quantity,unit FROM products WHERE status='Active' AND LOWER(unit)='roll' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$rules=$conn->query("SELECT r.*,b.product_name book_name FROM book_covering_service_rules r JOIN products b ON b.id=r.book_product_id ORDER BY b.product_name")->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__.'/../../includes/header.php'; require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<div class="page-head"><div><h1>Book Covering Setup</h1><p class="muted">Set the extra covering charge for each exercise-book product. Cover rolls are sold separately as whole rolls.</p></div></div>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<div class="card" style="padding:20px;margin-bottom:20px">
<h2>Covering service charges</h2>
<p class="muted">The charge is added for each exercise book marked for covering at the POS. It does not deduct roll stock automatically.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_rule">
<div class="form-grid">
<label>Exercise book<select name="book_product_id" required><option value="">Select exercise book</option><?php foreach($books as $p):?><option value="<?=(int)$p['id']?>"><?=e($p['product_name'].' · '.($p['sku']?:'No SKU'))?></option><?php endforeach;?></select></label>
<label>Extra covering charge per book (KES)<input type="number" name="charge_per_book" min="0" step="0.01" required placeholder="e.g. 10.00"></label>
</div><button class="btn btn-primary" type="submit">Save covering charge</button></form>
<div class="table-responsive" style="margin-top:20px"><table class="table"><thead><tr><th>Exercise book</th><th>Covering charge</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach($rules as $r):?><tr><td><?=e($r['book_name'])?></td><td>KES <?=number_format((float)$r['charge_per_book'],2)?></td><td><?=e($r['status'])?></td><td><?php if($r['status']==='Active'):?><form method="post" onsubmit="return confirm('Deactivate this covering charge?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="deactivate_rule"><input type="hidden" name="rule_id" value="<?=(int)$r['id']?>"><button class="btn btn-light" type="submit">Deactivate</button></form><?php endif;?></td></tr><?php endforeach;?>
<?php if(!$rules):?><tr><td colspan="4">No covering charges configured yet.</td></tr><?php endif;?>
</tbody></table></div></div>
<div class="card" style="padding:20px">
<h2>Record cover rolls used internally</h2>
<p class="muted">Use this when the shop takes a complete roll from stock for its own covering work instead of selling that roll to a customer. This records whole rolls only.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="internal_use">
<div class="form-grid">
<label>Cover roll product<select name="roll_product_id" required><option value="">Select a roll</option><?php foreach($rolls as $r):?><option value="<?=(int)$r['id']?>"><?=e($r['product_name'].' · Stock: '.number_format((int)$r['stock_quantity']).' rolls')?></option><?php endforeach;?></select></label>
<label>Whole rolls used<input type="number" name="roll_quantity" min="1" step="1" required value="1"></label>
<label style="grid-column:1/-1">Reason / Notes<input type="text" name="use_notes" maxlength="250" required placeholder="e.g. Rolls opened for in-store book covering"></label>
</div><button class="btn btn-primary" type="submit">Record internal use</button></form>
</div>
</main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>