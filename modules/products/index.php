<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

$pageTitle='Products';

$search=trim((string)($_GET['q']??''));
$category=(int)($_GET['category']??0);
$status=(string)($_GET['status']??'Active');

$categories=$conn->query("SELECT id, category_name FROM categories WHERE status='Active' ORDER BY category_name")->fetchAll();

$sql="SELECT p.*, b.brand_name, s.supplier_name, c.category_name
      FROM products p
      LEFT JOIN brands b ON b.id=p.brand_id
      LEFT JOIN suppliers s ON s.id=p.supplier_id
      LEFT JOIN categories c ON c.id=p.category_id
      WHERE 1=1";
$params=[];
if ($search !== '') {
    $isbnSearch = (string)preg_replace('/[\s-]+/u', '', $search);
    $sql .= " AND (
        p.product_name LIKE :name_q
        OR p.sku LIKE :sku_q
        OR p.barcode LIKE :barcode_q
        OR p.isbn LIKE :isbn_q
        OR b.brand_name LIKE :brand_q
    )";
    $params[':name_q'] = "%{$search}%";
    $params[':sku_q'] = "%{$search}%";
    $params[':barcode_q'] = "%{$search}%";
    $params[':isbn_q'] = "%{$isbnSearch}%";
    $params[':brand_q'] = "%{$search}%";
}
if($category>0){ $sql.=" AND p.category_id=:category"; $params[':category']=$category; }
if(in_array($status,['Active','Inactive'],true)){ $sql.=" AND p.status=:status"; $params[':status']=$status; }
$sql.=" ORDER BY p.id DESC";
$stmt=$conn->prepare($sql); $stmt->execute($params); $products=$stmt->fetchAll();

$totalProducts=(int)$conn->query("SELECT COUNT(*) FROM products WHERE status='Active'")->fetchColumn();
$totalStock=(int)$conn->query("SELECT COALESCE(SUM(stock_quantity),0) FROM products WHERE status='Active'")->fetchColumn();
$stockValue=(float)$conn->query("SELECT COALESCE(SUM(stock_quantity*buying_price),0) FROM products WHERE status='Active'")->fetchColumn();
$lowStock=(int)$conn->query("SELECT COUNT(*) FROM products WHERE status='Active' AND stock_quantity<=reorder_level")->fetchColumn();

$brands=$conn->query("SELECT id,brand_name FROM brands WHERE status='Active' ORDER BY brand_name")->fetchAll();
$suppliers=$conn->query("SELECT id,supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name")->fetchAll();

$flash=$_SESSION['product_flash']??null; unset($_SESSION['product_flash']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
<style>
.page-head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:22px;flex-wrap:wrap}
.page-head h1{margin:0}.muted{color:#64748b}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 15px;border-radius:8px;text-decoration:none;border:0;cursor:pointer;font-weight:700;font-size:13px}.btn-primary{background:#004a99;color:#fff}.btn-primary:hover{background:#003b7a}.btn-light{background:#eef2f7;color:#334155}.btn-danger{background:#fee2e2;color:#b91c1c}.btn-edit{background:#dbeafe;color:#1d4ed8}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:20px}.stat{background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 4px #00000012}.stat strong{display:block;font-size:25px}.stat span{color:#64748b;font-size:13px}
.toolbar{background:#fff;padding:15px;border-radius:12px;margin-bottom:15px;display:flex;gap:10px;flex-wrap:wrap}.toolbar input,.toolbar select{padding:10px 12px;border:1px solid #dbe3ec;border-radius:8px;min-width:180px}.toolbar input{flex:1}
.table-card{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 4px #00000012}.products-table-wrap{width:100%;max-width:100%;overflow-x:auto;overflow-y:hidden;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;scrollbar-width:thin}.products-table{width:100%;border-collapse:collapse;min-width:1100px}.table-scroll-hint{display:none;margin:0;padding:8px 12px;background:#f8fafc;color:#64748b;font-size:11px;border-bottom:1px solid #e5e7eb}@media(max-width:1100px){.table-scroll-hint{display:block}}.products-table th{background:#004a99;color:#fff;text-align:left;padding:13px;font-size:12px}.products-table td{padding:13px;border-bottom:1px solid #e5e7eb;font-size:13px}.product-name{display:flex;gap:10px;align-items:center}.icon{font-size:25px}.badge{padding:5px 9px;background:#eef2ff;border-radius:20px;font-size:11px}.stock-low{color:#b45309;background:#fef3c7;padding:5px 8px;border-radius:6px}.stock-out{color:#b91c1c;background:#fee2e2;padding:5px 8px;border-radius:6px}.stock-ok{color:#047857;background:#d1fae5;padding:5px 8px;border-radius:6px}.actions{display:flex;gap:6px}.flash{padding:12px 15px;border-radius:9px;margin-bottom:18px;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}
@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.stats-grid{grid-template-columns:1fr}.content{padding:18px}}
</style>

<nav class="breadcrumbs" aria-label="Breadcrumb"><span class="current">Catalogue</span><span aria-hidden="true">/</span><span class="current">Products</span></nav>
<div class="page-head">
 <div><h1>Products Inventory</h1><p class="muted">Manage products, pricing, stock and suppliers.</p></div>
 <button class="btn btn-primary" onclick="document.getElementById('productModal').classList.add('show')"><i class="fas fa-plus"></i> Add Product</button>
 <div style="display:flex;gap:7px;flex-wrap:wrap"><a class="btn btn-light" href="<?=BASE_URL?>/modules/categories/index.php">Manage Categories</a><a class="btn btn-light" href="<?=BASE_URL?>/modules/brands/index.php">Manage Brands</a><a class="btn btn-light" href="<?=BASE_URL?>/modules/suppliers/index.php">Manage Suppliers</a><a class="btn btn-light" href="<?=BASE_URL?>/modules/price_lists/index.php">Manage Price Lists</a></div>
</div>

<?php if($flash): ?><div class="flash"><?=e($flash)?></div><?php endif; ?>

<div class="stats-grid">
 <div class="stat"><strong><?=number_format($totalProducts)?></strong><span>Active Products</span></div>
 <div class="stat"><strong><?=number_format($totalStock)?></strong><span>Total Stock Units</span></div>
 <div class="stat"><strong>KES <?=number_format($stockValue,2)?></strong><span>Stock Cost Value</span></div>
 <div class="stat"><strong><?=number_format($lowStock)?></strong><span>Low Stock Items</span></div>
</div>

<form class="toolbar" method="get">
 <input name="q" value="<?=e($search)?>" placeholder="Search product, ISBN, SKU, barcode or brand...">
 <select name="category"><option value="0">All Categories</option><?php foreach($categories as $c): ?><option value="<?=$c['id']?>" <?=$category===(int)$c['id']?'selected':''?>><?=e($c['category_name'])?></option><?php endforeach;?></select>
 <select name="status"><option value="Active" <?=$status==='Active'?'selected':''?>>Active</option><option value="Inactive" <?=$status==='Inactive'?'selected':''?>>Inactive</option><option value="all" <?=$status==='all'?'selected':''?>>All Status</option></select>
 <button class="btn btn-light" type="submit"><i class="fas fa-search"></i> Filter</button>
 <a class="btn btn-light" href="<?=BASE_URL?>/modules/products/index.php">Reset</a>
</form>

<div class="table-card">
<p class="table-scroll-hint">Scroll horizontally to view all product columns, including Edit and Delete.</p>
<div class="products-table-wrap" role="region" aria-label="Products inventory table" tabindex="0">
<table class="table products-table">
<thead><tr><th>Product</th><th>Type / ISBN</th><th>Category</th><th>Brand</th><th>Supplier</th><th>Buy Price</th><th>Normal Price</th><th>Wholesale Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
<tbody>
<?php if(!$products): ?><tr><td colspan="11" style="text-align:center;padding:45px;color:#64748b">No products found.</td></tr>
<?php else: foreach($products as $p): $stock=(int)$p['stock_quantity']; ?>
<tr>
<td><div class="product-name"><?php if(!empty($p['image_path'])): ?><img src="<?=BASE_URL.'/'.e($p['image_path'])?>" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:6px"><?php else: ?><span class="icon"><?=e($p['icon']?:'📦')?></span><?php endif; ?><div><strong><?=e($p['product_name'])?></strong><br><small class="muted"><?=e($p['sku']?:'No SKU')?></small></div></div></td>
<td><?=e($p['product_type']??'General')?><?php if(!empty($p['isbn'])):?><br><small class="muted">ISBN: <?=e($p['isbn'])?></small><?php endif;?></td>
<td><?=e($p['category_name']?:$p['category']?:'Uncategorized')?></td>
<td><?=e($p['brand_name']?:'—')?></td><td><?=e($p['supplier_name']?:'—')?></td>
<td>KES <?=number_format((float)$p['buying_price'],2)?></td><td>KES <?=number_format((float)$p['selling_price'],2)?></td><td>KES <?=number_format((float)$p['wholesale_price'],2)?></td>
<td><?php if($stock===0): ?><span class="stock-out">Out of stock</span><?php elseif($stock<=(int)$p['reorder_level']): ?><span class="stock-low">⚠ <?=$stock?></span><?php else:?><span class="stock-ok">✓ <?=$stock?></span><?php endif;?></td>
<td><span class="status <?=strtolower(e($p['status']))?>"><?=e($p['status'])?></span></td>
<td><div class="actions"><?php if(hasModulePermission('products','edit')):?><a class="btn btn-edit" href="edit.php?id=<?=(int)$p['id']?>" title="Edit product" aria-label="Edit <?=e($p['product_name'])?>"><i class="fas fa-edit"></i> Edit</a><?php endif;?><?php if(hasModulePermission('products','delete')):?><form method="post" action="delete.php" onsubmit="return confirm('Delete this product? Products with sales, purchase or stock history must be deactivated instead.')" style="display:inline"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="btn btn-danger" type="submit" title="Delete product"><i class="fas fa-trash"></i> Delete</button></form><?php endif;?></div></td>
</tr>
<?php endforeach; endif;?>
</tbody></table></div></div>
</main>

<div id="productModal" class="modal">
<div class="modal-box">
<h2>Add Product</h2><button class="modal-close" onclick="document.getElementById('productModal').classList.remove('show')">&times;</button>
<form method="post" action="store.php" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="form-grid">
<label>Product Name *<input name="product_name" required maxlength="160"></label>
<label>Item Type<select name="product_type"><option value="Book">Book</option><option value="Stationery">Stationery</option><option value="General" selected>General</option></select></label>
<label>ISBN (optional)<input name="isbn" maxlength="20" placeholder="ISBN-10 or ISBN-13"></label>
<label>SKU<input name="sku" maxlength="80"></label>
<label>Barcode<input name="barcode" maxlength="80"></label>
<label>Category<input name="category_name" list="productCategoryOptions" maxlength="120" placeholder="Type or choose a category" autocomplete="off"><datalist id="productCategoryOptions"><?php foreach($categories as $c):?><option value="<?=e($c['category_name'])?>"><?php endforeach;?></datalist><small>Choose an existing category or type a new one.</small></label>
<label>Brand / Publisher<input name="brand_name" list="productBrandOptions" maxlength="120" placeholder="Type or choose a brand / publisher" autocomplete="off"><datalist id="productBrandOptions"><?php foreach($brands as $b):?><option value="<?=e($b['brand_name'])?>"><?php endforeach;?></datalist><small>Choose an existing brand or type a new one.</small></label>
<label>Supplier<select name="supplier_id"><option value="">Select supplier</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>"><?=e($s['supplier_name'])?></option><?php endforeach;?></select></label>
<label>Buying Price *<input type="number" name="buying_price" min="0" step=".01" required></label>
<label>Selling Price *<input type="number" name="selling_price" min="0" step=".01" required></label>
<label>Wholesale Price<input type="number" name="wholesale_price" min="0" step=".01"></label>
<label>Opening Stock<input type="number" name="stock_quantity" min="0" value="0"></label>
<label>Reorder Level<input type="number" name="reorder_level" min="0" value="10"></label>
<label>Unit<input name="unit" value="Piece" maxlength="30"></label>
<label>Icon<input name="icon" value="📦" maxlength="10"></label>
<label>Product Image (PNG/JPEG, max 2 MB)<input type="file" name="product_image" accept="image/png,image/jpeg"><small>Max dimensions: 2400 x 2400 pixels.</small></label>
</div>
<div class="modal-actions"><button type="button" class="btn btn-light" onclick="document.getElementById('productModal').classList.remove('show')">Cancel</button><button class="btn btn-primary">Save Product</button></div>
</form></div></div>
<style>
.modal{display:none;position:fixed;inset:0;background:#0008;z-index:2000;align-items:center;justify-content:center;padding:20px}.modal.show{display:flex}.modal-box{background:#fff;border-radius:15px;padding:25px;width:min(760px,100%);max-height:90vh;overflow:auto;position:relative}.modal-box h2{margin-top:0}.modal-close{position:absolute;right:18px;top:12px;border:0;background:none;font-size:28px;cursor:pointer}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.form-grid label{font-size:12px;font-weight:700;color:#475569}.form-grid input,.form-grid select{display:block;width:100%;box-sizing:border-box;margin-top:6px;padding:11px;border:1px solid #dbe3ec;border-radius:8px}.modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:22px}@media(max-width:650px){.form-grid{grid-template-columns:1fr}}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>