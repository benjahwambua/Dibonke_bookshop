<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModulePermission('products','edit');
$pageTitle='Book Covering Setup';
$flash=$_SESSION['book_covering_flash']??null; unset($_SESSION['book_covering_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $bookId=(int)($_POST['book_product_id']??0);
        $coverId=(int)($_POST['cover_product_id']??0);
        $cm=(int)($_POST['material_cm_per_book']??0);
        $charge=round((float)($_POST['charge_per_book']??0),2);
        if($bookId<=0||$coverId<=0||$bookId===$coverId)throw new RuntimeException('Choose an exercise book and a different cover-roll stock item.');
        if($cm<=0)throw new RuntimeException('Enter the centimetres of roll used for one book.');
        if($charge<0)throw new RuntimeException('The covering charge cannot be negative.');
        $q=$conn->prepare("SELECT id,product_name,unit FROM products WHERE id IN (?,?) AND status='Active'");
        $q->execute([$bookId,$coverId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==2)throw new RuntimeException('Both selected products must be active.');
        $book=null;$cover=null;foreach($rows as $row){if((int)$row['id']===$bookId)$book=$row;else $cover=$row;}
        if(strtolower((string)$cover['unit'])!=='centimetre' && strtolower((string)$cover['unit'])!=='cm')throw new RuntimeException('Set the cover-roll product stock unit to Centimetre (cm) before creating this rule.');
        $save=$conn->prepare("INSERT INTO book_covering_rules(book_product_id,cover_product_id,material_cm_per_book,charge_per_book,status,created_by,updated_by) VALUES(?,?,?,?, 'Active',?,?) ON DUPLICATE KEY UPDATE cover_product_id=VALUES(cover_product_id),material_cm_per_book=VALUES(material_cm_per_book),charge_per_book=VALUES(charge_per_book),status='Active',updated_by=VALUES(updated_by)");
        $uid=(int)$_SESSION['user_id'];$save->execute([$bookId,$coverId,$cm,$charge,$uid,$uid]);
        $_SESSION['book_covering_flash']='Covering rule saved for '.$book['product_name'].'.';
    }catch(Throwable $e){$_SESSION['book_covering_flash']='Could not save rule: '.$e->getMessage();}
    header('Location: book_covering.php');exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='deactivate'){
    verify_csrf();
    $q=$conn->prepare("UPDATE book_covering_rules SET status='Inactive',updated_by=? WHERE id=?");
    $q->execute([(int)$_SESSION['user_id'],(int)($_POST['rule_id']??0)]);
    header('Location: book_covering.php');exit;
}
$books=$conn->query("SELECT id,product_name,sku FROM products WHERE status='Active' AND product_type='Book' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$materials=$conn->query("SELECT id,product_name,sku,stock_quantity,unit FROM products WHERE status='Active' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$rules=$conn->query("SELECT r.*,b.product_name book_name,m.product_name material_name,m.stock_quantity material_stock FROM book_covering_rules r JOIN products b ON b.id=r.book_product_id JOIN products m ON m.id=r.cover_product_id ORDER BY b.product_name")->fetchAll(PDO::FETCH_ASSOC);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><div class="page-head"><div><h1>Book Covering Setup</h1><p>Set the roll length used and the extra charge for each exercise-book size.</p></div></div>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<div class="card" style="padding:20px;margin-bottom:20px"><h2>Add or update a covering rule</h2><p class="muted">Record roll stock in centimetres. For example, a 10-metre roll is received as 1,000 cm. Enter the material used for one book, not the whole roll.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="form-grid"><label>Exercise book<select name="book_product_id" required><option value="">Select exercise book</option><?php foreach($books as $p):?><option value="<?=$p['id']?>"><?=e($p['product_name'].' · '.($p['sku']?:'No SKU'))?></option><?php endforeach;?></select></label>
<label>Cover roll stock item<select name="cover_product_id" required><option value="">Select roll material</option><?php foreach($materials as $p):?><option value="<?=$p['id']?>"><?=e($p['product_name'].' · Stock: '.number_format((float)$p['stock_quantity']).' '.($p['unit']?:'units'))?></option><?php endforeach;?></select></label>
<label>Roll used per book (cm)<input type="number" name="material_cm_per_book" min="1" step="1" required placeholder="e.g. 28"></label>
<label>Extra covering charge per book (KES)<input type="number" name="charge_per_book" min="0" step="0.01" required placeholder="e.g. 10.00"></label></div>
<button class="btn btn-primary" type="submit">Save covering rule</button></form></div>
<div class="card" style="padding:20px"><h2>Configured book sizes</h2><div class="table-responsive"><table class="table"><thead><tr><th>Exercise book</th><th>Roll material</th><th>Usage per book</th><th>Extra charge</th><th>Roll stock</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($rules as $r):?><tr><td><?=e($r['book_name'])?></td><td><?=e($r['material_name'])?></td><td><?=number_format((int)$r['material_cm_per_book'])?> cm</td><td>KES <?=number_format((float)$r['charge_per_book'],2)?></td><td><?=number_format((float)$r['material_stock'])?> cm</td><td><?=e($r['status'])?></td><td><?php if($r['status']==='Active'):?><form method="post" style="display:inline" onsubmit="return confirm('Deactivate this covering rule?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="rule_id" value="<?=$r['id']?>"><button type="submit" class="btn btn-light">Deactivate</button></form><?php endif;?></td></tr><?php endforeach;?><?php if(!$rules):?><tr><td colspan="7">No covering rules configured yet.</td></tr><?php endif;?></tbody></table></div></div></main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>