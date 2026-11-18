<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/audit.php';

$flash=$_SESSION['price_list_flash']??null;unset($_SESSION['price_list_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  verify_csrf();
  $action=(string)($_POST['action']??'');
  $permissionAction=in_array($action,['create','tier'],true)?'create':($action==='delete_list'||$action==='delete_tier'?'delete':'edit');
  requireModulePermission('price_lists',$permissionAction);

  if($action==='create'){
   $name=trim((string)($_POST['name']??''));$code=strtolower(trim((string)($_POST['code']??'')));$description=trim((string)($_POST['description']??''));
   if($name===''||$code==='')throw new RuntimeException('Name and code are required.');
   if(!preg_match('/^[a-z0-9_-]{2,40}$/',$code))throw new RuntimeException('Code must be 2–40 characters and use only letters, numbers, hyphens or underscores.');
   $st=$conn->prepare("INSERT INTO price_lists(name,code,description,status) VALUES(?,?,?,'Active')");$st->execute([$name,$code,$description?:null]);
   $newId=(int)$conn->lastInsertId();
   recordAuditEvent($conn,'Create','price_lists','Created price list '.$name,'price_list',$newId,null,['name'=>$name,'code'=>$code]);
   $_SESSION['price_list_flash']='Price list created successfully.';

  }elseif($action==='update_list'){
   $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$code=strtolower(trim((string)($_POST['code']??'')));$description=trim((string)($_POST['description']??''));$status=($_POST['status']??'Active')==='Inactive'?'Inactive':'Active';
   if($id<1||$name===''||$code==='')throw new RuntimeException('Enter a price list name and code.');
   if(!preg_match('/^[a-z0-9_-]{2,40}$/',$code))throw new RuntimeException('Code must be 2–40 characters and use only letters, numbers, hyphens or underscores.');
   $q=$conn->prepare("SELECT * FROM price_lists WHERE id=? FOR UPDATE");$conn->beginTransaction();$q->execute([$id]);$old=$q->fetch(PDO::FETCH_ASSOC);
   if(!$old)throw new RuntimeException('Price list not found.');
   if((int)$old['is_default']===1 && $status!=='Active')throw new RuntimeException('The default price list must remain active.');
   if(in_array($old['code'],['normal','wholesale'],true) && $code!==$old['code'])throw new RuntimeException('The system code for the Standard and Wholesale price lists cannot be changed.');
   if($old['code']==='normal' && $status!=='Active')throw new RuntimeException('The Standard price list must remain active.');
   if($old['code']==='wholesale' && $status!=='Active'){
    $used=(int)$conn->query("SELECT COUNT(*) FROM products WHERE wholesale_price>0 AND status='Active'")->fetchColumn();
    if($used>0)throw new RuntimeException('Wholesale pricing is still configured on active products. Update those product prices before deactivating this list.');
   }
   $u=$conn->prepare("UPDATE price_lists SET name=?,code=?,description=?,status=? WHERE id=?");$u->execute([$name,$code,$description?:null,$status,$id]);
   recordAuditEvent($conn,'Update','price_lists','Updated price list '.$name,'price_list',$id,['name'=>$old['name'],'code'=>$old['code'],'status'=>$old['status']],['name'=>$name,'code'=>$code,'status'=>$status]);
   $conn->commit();$_SESSION['price_list_flash']='Price list updated successfully.';

  }elseif($action==='delete_list'){
   $id=(int)($_POST['id']??0);$conn->beginTransaction();
   $q=$conn->prepare("SELECT * FROM price_lists WHERE id=? FOR UPDATE");$q->execute([$id]);$list=$q->fetch(PDO::FETCH_ASSOC);
   if(!$list)throw new RuntimeException('Price list not found.');
   if((int)$list['is_default']===1 || in_array($list['code'],['normal','wholesale'],true))throw new RuntimeException('The Standard and Wholesale system price lists cannot be deleted. You can manage their descriptions and pricing tiers instead.');
   $del=$conn->prepare("DELETE FROM price_lists WHERE id=?");$del->execute([$id]);
   recordAuditEvent($conn,'Delete','price_lists','Deleted price list '.$list['name'],'price_list',$id,['name'=>$list['name'],'code'=>$list['code']],null);
   $conn->commit();$_SESSION['price_list_flash']='Price list deleted. Its price tiers were removed; customer assignments and historical sale links were cleared by the database relationships.';

  }elseif($action==='tier'){
   $list=(int)($_POST['price_list_id']??0);$product=(int)($_POST['product_id']??0);$min=max(1,(int)($_POST['min_quantity']??1));$price=(float)($_POST['price']??0);
   if($list<1||$product<1||$price<=0)throw new RuntimeException('Select a price list and product and enter a valid price.');
   $st=$conn->prepare("SELECT status FROM price_lists WHERE id=?");$st->execute([$list]);if($st->fetchColumn()!=='Active')throw new RuntimeException('Choose an active price list.');
   $st=$conn->prepare("INSERT INTO product_prices(price_list_id,product_id,min_quantity,price) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE price=VALUES(price)");
   $st->execute([$list,$product,$min,$price]);$_SESSION['price_list_flash']='Price tier saved successfully.';

  }elseif($action==='update_tier'){
   $id=(int)($_POST['id']??0);$min=(int)($_POST['min_quantity']??0);$price=(float)($_POST['price']??0);
   if($id<1||$min<1||$price<=0)throw new RuntimeException('Enter a valid minimum quantity and price.');
   $q=$conn->prepare("UPDATE product_prices SET min_quantity=?,price=? WHERE id=?");$q->execute([$min,$price,$id]);
   if(!$q->rowCount()){$check=$conn->prepare("SELECT id FROM product_prices WHERE id=?");$check->execute([$id]);if(!$check->fetchColumn())throw new RuntimeException('Price tier not found.');}
   $_SESSION['price_list_flash']='Price tier updated successfully.';

  }elseif($action==='delete_tier'){
   $id=(int)($_POST['id']??0);$conn->prepare("DELETE FROM product_prices WHERE id=?")->execute([$id]);$_SESSION['price_list_flash']='Price tier removed.';
  }else throw new RuntimeException('Invalid action.');

 }catch(Throwable $e){
  if(isset($conn)&&$conn instanceof PDO&&$conn->inTransaction())$conn->rollBack();
  error_log('Dibonke price list action failed: '.$e->getMessage());
  $_SESSION['price_list_flash']='Price list action failed: '.$e->getMessage();
 }
 header('Location:index.php');exit;
}
$lists=$conn->query("SELECT pl.*,COUNT(pp.id) tier_count FROM price_lists pl LEFT JOIN product_prices pp ON pp.price_list_id=pl.id GROUP BY pl.id ORDER BY pl.is_default DESC,pl.name")->fetchAll(PDO::FETCH_ASSOC);
$products=$conn->query("SELECT id,product_name,sku,selling_price,wholesale_price FROM products WHERE status='Active' ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$tiers=$conn->query("SELECT pp.*,pl.name list_name,p.product_name,p.sku FROM product_prices pp JOIN price_lists pl ON pl.id=pp.price_list_id JOIN products p ON p.id=pp.product_id ORDER BY pl.name,p.product_name,pp.min_quantity")->fetchAll(PDO::FETCH_ASSOC);
$canCreate=hasModulePermission('price_lists','create');$canEdit=hasModulePermission('price_lists','edit');$canDelete=hasModulePermission('price_lists','delete');
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><style>
.head{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;gap:15px}.muted{color:#64748b}.grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.card{background:#fff;padding:20px;border-radius:12px;box-shadow:0 1px 4px #0001}.card h2{margin-top:0}.form{display:grid;gap:10px}.form label{font-size:12px;font-weight:800;color:#475569}.form input,.form select,.form textarea{width:100%;box-sizing:border-box;margin-top:5px;padding:10px;border:1px solid #dbe3ec;border-radius:8px}.btn{border:0;border-radius:8px;padding:9px 12px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block;font-size:12px}.primary{background:#004a99;color:#fff}.light{background:#eef2f7;color:#334155}.danger{background:#fee2e2;color:#991b1b}.flash{padding:12px;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:15px}.table{width:100%;border-collapse:collapse;min-width:760px}.table th{background:#004a99;color:#fff;text-align:left;padding:10px;font-size:12px}.table td{padding:10px;border-bottom:1px solid #e5e7eb;font-size:12px;vertical-align:middle}.table input,.table select{width:100%;box-sizing:border-box;padding:8px;border:1px solid #dbe3ec;border-radius:7px}.tag{padding:4px 8px;border-radius:20px;background:#e0f2fe;color:#075985;font-weight:700;font-size:11px}.actions{display:flex;gap:6px;flex-wrap:wrap}.inline-form{display:grid;grid-template-columns:1.2fr .8fr 1.4fr .8fr auto;gap:7px;align-items:center;min-width:650px}.tier-form{display:flex;gap:6px;align-items:center;min-width:190px}.tier-form input{max-width:85px}.hint{font-size:11px;color:#64748b;margin-top:8px}@media(max-width:850px){.grid{grid-template-columns:1fr}}
</style>
<div class="head"><div><h1>Price Lists</h1><p class="muted">Manage configured price lists and the product prices within each list.</p></div></div>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<div class="grid">
<?php if($canCreate):?><div class="card"><h2>Create Price List</h2><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create"><label>Name<input name="name" placeholder="e.g. School Pricing" required></label><label>Code<input name="code" placeholder="e.g. school" pattern="[A-Za-z0-9_-]{2,40}" required></label><label>Description<textarea name="description"></textarea></label><button class="btn primary">Create Price List</button></form></div><?php endif;?>
<?php if($canCreate):?><div class="card"><h2>Add Price Tier</h2><form method="post" class="form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="tier"><label>Price List<select name="price_list_id" required><?php foreach($lists as $l):if($l['status']==='Active'):?><option value="<?=$l['id']?>"><?=e($l['name'])?></option><?php endif;endforeach;?></select></label><label>Product<select name="product_id" required><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=e($p['product_name'])?><?= $p['sku']?' — '.e($p['sku']):''?></option><?php endforeach;?></select></label><label>Minimum Quantity<input type="number" name="min_quantity" min="1" value="1" required></label><label>Price (KES)<input type="number" name="price" min="0.01" step=".01" required></label><button class="btn primary">Save Price Tier</button></form></div><?php endif;?>
</div>
<div class="card" style="margin-top:18px"><h2>Configured Price Lists</h2><p class="hint">Edit the name, code, description or status of a list. Standard and Wholesale are protected system lists; their system codes cannot be changed or deleted.</p><div style="overflow:auto"><table class="table"><thead><tr><th>Name</th><th>Code</th><th>Description</th><th>Status</th><th>Price Tiers</th><th>Actions</th></tr></thead><tbody>
<?php if(!$lists):?><tr><td colspan="6" style="text-align:center;padding:30px">No price lists configured.</td></tr><?php else:foreach($lists as $l):$system=in_array($l['code'],['normal','wholesale'],true)||(int)$l['is_default']===1;?>
<tr><td colspan="6"><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_list"><input type="hidden" name="id" value="<?=$l['id']?>"><input name="name" value="<?=e($l['name'])?>" aria-label="Price list name" required><input name="code" value="<?=e($l['code'])?>" aria-label="Price list code" pattern="[A-Za-z0-9_-]{2,40}" required <?= $system?'readonly title="System price list code is protected"':''?>><input name="description" value="<?=e($l['description']??'')?>" aria-label="Description" placeholder="Description"><select name="status" aria-label="Status" <?= $l['code']==='normal'||(int)$l['is_default']===1?'disabled':''?>><option value="Active" <?=$l['status']==='Active'?'selected':''?>>Active</option><option value="Inactive" <?=$l['status']==='Inactive'?'selected':''?>>Inactive</option></select><div class="actions"><span class="tag"><?=number_format((int)$l['tier_count'])?> tiers</span><?php if($canEdit):?><button class="btn primary" type="submit">Save Changes</button><?php endif;?><?php if($canDelete&&!$system):?><button class="btn danger" type="submit" formaction="" onclick="this.form.action.value='delete_list';return confirm('Delete this price list and all its product price tiers? Customer assignments and historical sale links will be cleared. This cannot be undone.')">Delete</button><?php endif;?></div></form></td></tr>
<?php endforeach;endif;?></tbody></table></div></div>
<div class="card" style="margin-top:18px"><h2>Configured Product Prices</h2><div style="overflow:auto"><table class="table"><thead><tr><th>Price List</th><th>Product</th><th>Minimum Qty</th><th>Price (KES)</th><th>Action</th></tr></thead><tbody><?php if(!$tiers):?><tr><td colspan="5" style="text-align:center;padding:30px">No pricing tiers configured.</td></tr><?php else:foreach($tiers as $t):?><tr><td><strong><?=e($t['list_name'])?></strong></td><td><?=e($t['product_name'])?><?= $t['sku']?' <small class="muted">('.e($t['sku']).')</small>':''?></td><td><?php if($canEdit):?><form method="post" class="tier-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_tier"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="number" name="min_quantity" min="1" value="<?= (int)$t['min_quantity']?>" aria-label="Minimum quantity"><button class="btn primary">Save</button></form><?php else:?><span class="tag"><?=number_format((int)$t['min_quantity'])?>+</span><?php endif;?></td><td><?php if($canEdit):?><form method="post" class="tier-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_tier"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="min_quantity" value="<?= (int)$t['min_quantity']?>"><input type="number" name="price" min="0.01" step=".01" value="<?=e($t['price'])?>" aria-label="Price"><button class="btn primary">Save</button></form><?php else:?><strong>KES <?=number_format((float)$t['price'],2)?></strong><?php endif;?></td><td><?php if($canDelete):?><form method="post" onsubmit="return confirm('Remove this product price tier?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete_tier"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn danger">Remove</button></form><?php endif;?></td></tr><?php endforeach;endif;?></tbody></table></div></div>
</main>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>
