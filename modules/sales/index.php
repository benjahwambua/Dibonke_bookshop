<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
$pageTitle='Retail POS';
$vatRate=(float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_rate' LIMIT 1")->fetchColumn()?:16);
$vatMode=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_mode' LIMIT 1")->fetchColumn()?:'inclusive');
if(!in_array($vatMode,['exclusive','inclusive'],true))$vatMode='inclusive';

$storeOperationsEnabled = (bool)$conn->query("SHOW TABLES LIKE 'stores'")->fetchColumn()
    && (bool)$conn->query("SHOW TABLES LIKE 'store_stock'")->fetchColumn();
$posStores = $storeOperationsEnabled
    ? $conn->query("SELECT id,store_code,store_name,store_type FROM stores WHERE status='Active' ORDER BY store_type='Main' DESC,store_name")->fetchAll(PDO::FETCH_ASSOC)
    : [];
$mainStoreId = 0;
foreach ($posStores as $posStore) {
    if ($posStore['store_type'] === 'Main') { $mainStoreId = (int)$posStore['id']; break; }
}
$selectedStoreId = $mainStoreId;
if ($storeOperationsEnabled && isset($_GET['store_id'])) {
    $requestedStoreId = (int)$_GET['store_id'];
    foreach ($posStores as $posStore) {
        if ((int)$posStore['id'] === $requestedStoreId) { $selectedStoreId = $requestedStoreId; break; }
    }
}
$productImageColumn = (bool)$conn->query("SHOW COLUMNS FROM products LIKE 'image_path'")->fetchColumn();
$imageSelect = $productImageColumn ? 'p.image_path' : 'NULL AS image_path';
$stockSelect = $storeOperationsEnabled && $selectedStoreId !== $mainStoreId
    ? 'COALESCE(ss.quantity,0) AS stock_quantity'
    : 'p.stock_quantity AS stock_quantity';
$stockJoin = $storeOperationsEnabled && $selectedStoreId !== $mainStoreId
    ? " LEFT JOIN store_stock ss ON ss.product_id=p.id AND ss.store_id={$selectedStoreId} "
    : '';
$products=$conn->query("SELECT p.id,p.product_name,p.category,p.sku,p.barcode,p.isbn,{$imageSelect},p.selling_price,p.wholesale_price,p.bulk_selling_price,p.bulk_wholesale_price,p.purchase_unit,p.units_per_purchase,{$stockSelect},p.unit,b.brand_name FROM products p LEFT JOIN brands b ON b.id=p.brand_id {$stockJoin} WHERE p.status='Active' ORDER BY p.product_name")->fetchAll();
$coveringRules=[];
if ((bool)$conn->query("SHOW TABLES LIKE 'book_covering_service_rules'")->fetchColumn()) {
    $coveringRules=$conn->query("SELECT book_product_id,charge_per_book FROM book_covering_service_rules WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);
}
$customers=$conn->query("SELECT id,customer_name,phone,credit_limit,balance,price_list_id FROM customers WHERE status='Active' ORDER BY customer_name")->fetchAll();
$priceLists=$conn->query("SELECT id,name,code FROM price_lists WHERE status='Active' ORDER BY is_default DESC,name")->fetchAll();
$productPrices=$conn->query("SELECT price_list_id,product_id,min_quantity,price FROM product_prices ORDER BY price_list_id,product_id,min_quantity")->fetchAll();
$categories=$conn->query("SELECT DISTINCT category FROM products WHERE status='Active' AND category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$roleName = strtolower(trim((string)($_SESSION['role'] ?? '')));
$roleCode = strtoupper(trim((string)($_SESSION['role_code'] ?? '')));
$isCashierTerminal = in_array($roleName, ['cashier', 'sales'], true) || in_array($roleCode, ['CASHIER', 'SALES'], true);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$canManageOwnShift = hasModulePermission('cash_management','view') || hasModulePermission('cash_management','create');
$cashShiftRequired = hasModulePermission('cash_management','create') && (bool)$conn->query("SHOW TABLES LIKE 'cash_shift_guard'")->fetchColumn();
$hasOpenCashShift = false;
if ($cashShiftRequired) {
    $shiftCheck = $conn->prepare("SELECT id FROM cash_shifts WHERE user_id=? AND status='Open' LIMIT 1");
    $shiftCheck->execute([$currentUserId]);
    $hasOpenCashShift = (bool)$shiftCheck->fetchColumn();
}

if ($isCashierTerminal) {
    $statsStmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN DATE(sale_date)=CURDATE() THEN total_amount ELSE 0 END),0) today, COALESCE(SUM(CASE WHEN YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE()) THEN total_amount ELSE 0 END),0) month FROM sales WHERE sale_status='Completed' AND user_id=?");
    $statsStmt->execute([$currentUserId]);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = ['today'=>(float)($statsRow['today']??0),'month'=>(float)($statsRow['month']??0),'credit'=>0.0];
    $recentStmt = $conn->prepare("SELECT s.id,s.customer_id,s.invoice_number,s.sale_date,s.total_amount,s.paid_amount,s.balance,s.payment_method,COALESCE(c.customer_name,'Walk-in Customer') customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.sale_status='Completed' AND s.user_id=? ORDER BY s.id DESC LIMIT 10");
    $recentStmt->execute([$currentUserId]);
    $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stats = [
        'today'=>(float)$conn->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_status='Completed' AND DATE(sale_date)=CURDATE()")->fetchColumn(),
        'month'=>(float)$conn->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE sale_status='Completed' AND YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE())")->fetchColumn(),
        'credit'=>(float)$conn->query("SELECT COALESCE(SUM(balance),0) FROM customers")->fetchColumn()
    ];
    $recent=$conn->query("SELECT s.id,s.invoice_number,s.sale_date,s.total_amount,s.paid_amount,s.balance,s.payment_method,COALESCE(c.customer_name,'Walk-in Customer') customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.sale_status='Completed' ORDER BY s.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
}
$flash=$_SESSION['sale_flash']??null; unset($_SESSION['sale_flash']);
$printReceiptId=(int)($_GET['print_receipt']??0);
require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<?php if ($printReceiptId > 0): ?><iframe title="Automatic receipt printing" src="receipt.php?id=<?= $printReceiptId ?>&amp;autoprint=1" aria-hidden="true" tabindex="-1" style="position:fixed;width:1px;height:1px;left:-10px;bottom:0;border:0;opacity:0;pointer-events:none"></iframe><script>window.addEventListener('load',function(){const u=new URL(window.location.href);u.searchParams.delete('print_receipt');window.history.replaceState({},document.title,u.pathname+(u.search?u.search:''));});</script><?php endif; ?>
<style>
/* Dibonke POS — focused visual refresh. Behaviour and form hooks remain unchanged. */
.pos-wrap{
 --pos-ink:#172b45;--pos-navy:#173653;--pos-blue:#2563a6;--pos-green:#087b58;
 --pos-muted:#718096;--pos-border:#e2e8f0;--pos-soft:#f4f7fb;
 max-width:1900px;margin:0 auto;color:var(--pos-ink);font-size:14px;
}
body.counter-mode .top-header,body.counter-mode #appSidebar,body.counter-mode .mobile-sidebar-backdrop,
body.counter-mode .kpis,body.counter-mode .recent,body.counter-mode .pos-actions>a:not(.shift-shortcut){display:none!important}
body.counter-mode .content{margin-left:0!important;padding:18px 22px!important;background:#f3f6fa}
body.counter-mode .pos-wrap{max-width:none}
.pos-wrap .pos-title{
 display:flex;align-items:center;justify-content:space-between;gap:18px;
 padding:15px 20px;margin:0 0 16px;background:linear-gradient(115deg,#173653,#214968);
 color:#fff;border-radius:14px;box-shadow:0 7px 20px rgba(23,54,83,.13)
}
.pos-wrap .pos-title h1{display:flex;align-items:center;gap:10px;margin:0;color:#fff;font-size:21px;font-weight:800;letter-spacing:-.3px}
.pos-wrap .pos-title h1 i{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;background:rgba(255,255,255,.13);font-size:17px}
.pos-wrap .pos-title .muted{margin-top:4px;color:#c8d6e3;font-size:12px}
.pos-wrap .pos-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.pos-wrap .counter-label{display:inline-flex;align-items:center;gap:7px;color:#c1f0d9;font-size:11px;font-weight:800}
.pos-wrap .pos-actions select{min-height:38px;background:#fff;color:#23384d;font-size:13px}
.pos-wrap .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:9px 13px;border:1px solid transparent;border-radius:8px;font-size:12px;font-weight:750;cursor:pointer;text-decoration:none;transition:background .15s,border-color .15s,transform .15s}
.pos-wrap .btn.light{background:#f1f5f9;color:#29445d;border-color:#e1e8ef}
.pos-wrap .btn.light:hover{background:#e4edf5}
.pos-wrap .btn.primary{background:var(--pos-blue);color:#fff}
.pos-wrap .scan-panel{padding:14px 16px;margin-bottom:17px;background:#f2f7fc;border:1px solid #d6e4f1;border-radius:12px}
.pos-wrap .scan-panel>label{display:block;margin:0 0 8px;color:#385a78;font-size:11px;font-weight:850;letter-spacing:.055em}
.pos-wrap .toolbar{display:grid;grid-template-columns:minmax(0,1fr) 42px;gap:8px;margin:0}
.pos-wrap .toolbar input{box-sizing:border-box;width:100%;height:48px;padding:11px 14px;border:1px solid #cbd9e7;border-radius:9px;background:#fff;color:#20364d;font-size:16px;font-weight:600}
.pos-wrap .toolbar input::placeholder{color:#8796a8;font-weight:450}
.pos-wrap .toolbar input:focus,.pos-wrap .field input:focus,.pos-wrap .field select:focus{outline:3px solid rgba(37,99,166,.13);border-color:#4b86bd}
.pos-wrap .scan-help{display:block;margin-top:7px;color:#718096;font-size:10px;line-height:1.5}
.pos-wrap .scan-feedback{min-height:16px;margin-top:5px;color:#087b58;font-size:11px;font-weight:750}
.pos-wrap .search-results{display:none;position:relative;z-index:5;max-height:320px;overflow:auto;margin-top:8px;background:#fff;border:1px solid #d7e1eb;border-radius:10px;box-shadow:0 10px 24px rgba(16,38,59,.12)}
.pos-wrap .search-result{display:grid;grid-template-columns:minmax(0,1fr) auto auto;align-items:center;gap:12px;padding:11px 13px;border-bottom:1px solid #edf2f7;cursor:pointer}
.pos-wrap .search-result:last-child{border-bottom:0}
.pos-wrap .search-result:hover,.pos-wrap .search-result:focus{background:#f2f7fc;outline:none}
.pos-wrap .search-result strong{display:block;color:#20364d;font-size:13px}
.pos-wrap .search-result small,.pos-wrap .search-result .result-stock{color:#718096;font-size:10px}
.pos-wrap .search-result .result-price{color:#1d5f9e;font-weight:850;white-space:nowrap}
.pos-wrap .search-empty{padding:16px;color:#718096;text-align:center;font-size:12px}
.pos-wrap .cashier{display:grid;grid-template-columns:minmax(0,1fr) minmax(390px,440px);gap:16px;align-items:start}
.pos-wrap .catalog,.pos-wrap .checkout{min-width:0;background:#fff;border:1px solid var(--pos-border);border-radius:14px;box-shadow:0 5px 20px rgba(31,54,79,.045)}
.pos-wrap .catalog{padding:18px}
.pos-wrap .checkout{position:sticky;top:14px;padding:18px;background:#fff}
.pos-wrap .section-heading,.pos-wrap .checkout-heading{display:flex;align-items:center;justify-content:space-between;gap:12px}
.pos-wrap .section-heading{margin:2px 0 13px}
.pos-wrap .section-heading h2{margin:0;color:#193a57;font-size:19px;font-weight:850;letter-spacing:-.35px}
.pos-wrap .section-heading p{margin:4px 0 0;color:#78889a;font-size:12px}
.pos-wrap .catalog-count,.pos-wrap .basket-count{flex-shrink:0;padding:7px 11px;border-radius:20px;background:#edf4fa;color:#4d6a85;font-size:11px;font-weight:850;white-space:nowrap}
.pos-wrap .categories{display:flex;gap:7px;overflow:auto;padding:2px 0 14px}
.pos-wrap .cat{padding:8px 13px;border:1px solid #e1e8ef;border-radius:20px;background:#f7f9fc;color:#52677d;font-size:11px;font-weight:750;white-space:nowrap;cursor:pointer}
.pos-wrap .cat:hover{border-color:#a9c4dc;background:#f0f6fb}
.pos-wrap .cat.active{border-color:#173653;background:#173653;color:#fff}
.pos-wrap .products{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;max-height:calc(100vh - 340px);min-height:260px;overflow:auto;padding:1px 3px 4px 1px}
.pos-wrap .product{position:relative;min-width:0;min-height:128px;padding:13px;border:1px solid #e2e9f0;border-radius:11px;background:#fff;cursor:pointer;transition:border-color .15s,box-shadow .15s,transform .15s}
.pos-wrap .product:hover{transform:translateY(-2px);border-color:#9bbbd7;box-shadow:0 6px 16px rgba(28,75,115,.09)}
.pos-wrap .product.out{opacity:.45;cursor:not-allowed}
.pos-wrap .product .name{padding-right:28px;color:#233b52;font-size:13px;font-weight:800;line-height:1.35}
.pos-wrap .product small{display:block;margin-top:5px;color:#7b8998;font-size:10px;line-height:1.4}
.pos-wrap .product .price{margin-top:8px;color:#17649f;font-size:15px;font-weight:850}
.pos-wrap .badge{position:absolute;top:8px;right:8px;padding:3px 6px;border-radius:12px;background:#edf3f8;color:#526a81;font-size:9px;font-weight:800}
.pos-wrap .product-image{display:block;width:42px;height:48px;object-fit:contain;margin:0 0 8px;border-radius:6px}
.pos-wrap .product-symbol{display:grid;place-items:center;width:38px;height:38px;margin:0 0 8px;border-radius:9px;background:#eff5fa;color:#47749a;font-size:17px}
.pos-wrap .checkout-heading{padding-bottom:13px;margin-bottom:14px;border-bottom:1px solid #e8edf2}
.pos-wrap .checkout-kicker{color:#8292a4;font-size:10px;font-weight:850;letter-spacing:.1em}
.pos-wrap .checkout h3{margin:4px 0 0;color:#193a57;font-size:19px;font-weight:850;letter-spacing:-.3px}
.pos-wrap .checkout h3 i{margin-right:4px}
.pos-wrap .field{margin:11px 0}
.pos-wrap .payment-method-field{padding:12px;margin:12px 0;background:#f4f8fc;border:1px solid #dce7f0;border-radius:10px}
.pos-wrap .payment-method-field select{min-height:44px;font-weight:750;color:#173653}
.pos-wrap .payment-detail[hidden]{display:none!important}
.pos-wrap .payment-detail{padding:10px 11px;margin:9px 0;background:#fbfcfe;border:1px solid #e6edf3;border-radius:9px}
.pos-wrap .payment-detail label{font-size:10px}
.pos-wrap .field label{display:block;margin-bottom:5px;color:#40566c;font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.025em}
.pos-wrap .field input,.pos-wrap .field select{box-sizing:border-box;width:100%;min-height:40px;padding:9px 11px;border:1px solid #d5dfe8;border-radius:8px;background:#fff;color:#293e53;font-size:13px}
.pos-wrap .field small.muted{display:block;margin-top:6px;color:#8795a5;font-size:10px;line-height:1.45}
.pos-wrap .cart-toolbar{display:flex;align-items:center;justify-content:space-between;margin:15px 0 8px;color:#536b82;font-size:11px;font-weight:850}
.pos-wrap .clear-cart{padding:3px 0;border:0;background:transparent;color:#2876b7;font-size:11px;font-weight:800;cursor:pointer}
.pos-wrap .cart{max-height:30vh;overflow:auto;padding:0 10px;border:1px solid #e4eaf0;border-radius:10px;background:#fff}
.pos-wrap .empty{padding:28px 10px;color:#91a0af;text-align:center;font-size:12px}
.pos-wrap .cart-row{display:grid;grid-template-columns:minmax(0,1fr) 62px 78px 22px;align-items:center;gap:6px;padding:11px 0;border-bottom:1px solid #eef2f6;color:#40566c;font-size:11px}
.pos-wrap .cart-row:last-child{border-bottom:0}
.pos-wrap .cart-row strong{color:#263e55;font-size:11px}
.pos-wrap .cart-row small{color:#8291a0;font-size:9px}
.pos-wrap .cart-row input,.pos-wrap .cart-row select{box-sizing:border-box;width:100%;min-height:30px;padding:4px;border:1px solid #d7e0e9;border-radius:6px;text-align:center;font-size:10px}
.pos-wrap .remove{border:0;background:transparent;color:#c44c4c;font-size:17px;cursor:pointer}
.pos-wrap .totals{padding:9px 0}
.pos-wrap .line{display:flex;justify-content:space-between;gap:12px;margin:7px 0;color:#617386;font-size:11px}
.pos-wrap .line strong{color:#344c63}
.pos-wrap .grand{padding-top:11px;border-top:1px solid #dce5ed;color:#193a57;font-size:18px;font-weight:900}
.pos-wrap .grand strong{color:#17649f;font-size:21px}
.pos-wrap .change-line{color:#087b58!important;font-weight:800}
.pos-wrap .vat-value{padding:5px 0;color:#315e83;font-size:14px;font-weight:850}
.pos-wrap .exact-btn{width:100%;margin:0 0 9px;border-radius:8px}
.pos-wrap .success{width:100%;min-height:48px;padding:12px;border:0;border-radius:9px;background:#087b58;color:#fff;font-size:14px;font-weight:850;box-shadow:0 4px 10px rgba(8,123,88,.13)}
.pos-wrap .success:hover{background:#066b4c}
.pos-wrap .flash{padding:11px 14px;margin-bottom:12px;border:1px solid #b9e5d1;border-radius:9px;background:#effaf4;color:#176b48;font-size:12px}
.pos-wrap .recent{margin-top:16px;overflow:auto;border:1px solid var(--pos-border);border-radius:12px;background:#fff}
.pos-wrap .recent h3{padding:0 15px;color:#193a57;font-size:15px}
.pos-wrap .table{width:100%;border-collapse:collapse;min-width:750px}
.pos-wrap .table th{padding:10px;background:#edf3f8;color:#405b74;text-align:left;font-size:10px}
.pos-wrap .table td{padding:10px;border-bottom:1px solid #edf2f6;color:#53677b;font-size:11px}
.pos-wrap .print-link{color:#216ca8;font-weight:750;text-decoration:none}
.pos-wrap .price-tier-hint{margin-top:5px;color:#7b8998;font-size:10px}
.pos-wrap .change-line{font-weight:850}
body.counter-mode .pos-wrap .pos-title{min-height:66px}
body.counter-mode .pos-wrap .cashier{grid-template-columns:minmax(0,1fr) minmax(390px,440px);gap:16px}
body.counter-mode .pos-wrap .checkout{height:calc(100vh - 130px);overflow:auto}
body.counter-mode .pos-wrap .products{max-height:calc(100vh - 320px);min-height:300px}
body.counter-mode .pos-wrap .cart{max-height:26vh}
@media(max-width:1100px){
 .pos-wrap .cashier,body.counter-mode .pos-wrap .cashier{grid-template-columns:minmax(0,1fr) minmax(340px,400px)}
 .pos-wrap .products{grid-template-columns:repeat(auto-fill,minmax(155px,1fr))}
}
@media(max-width:850px){
 body.counter-mode .content{padding:12px!important}
 .pos-wrap .pos-title{align-items:flex-start;flex-direction:column}
 .pos-wrap .pos-actions{justify-content:flex-start}
 .pos-wrap .cashier,body.counter-mode .pos-wrap .cashier{grid-template-columns:1fr}
 .pos-wrap .checkout,body.counter-mode .pos-wrap .checkout{position:static;height:auto;overflow:visible}
 .pos-wrap .products,body.counter-mode .pos-wrap .products{max-height:none;min-height:220px;grid-template-columns:repeat(auto-fill,minmax(145px,1fr))}
 .pos-wrap .cart{max-height:320px}
}
@media(max-width:520px){
 body.counter-mode .content{padding:9px!important}
 .pos-wrap .catalog,.pos-wrap .checkout{padding:13px}
 .pos-wrap .pos-title{padding:13px}
 .pos-wrap .pos-actions{gap:6px}
 .pos-wrap .pos-actions .btn{font-size:10px;padding:8px}
 .pos-wrap .section-heading h2{font-size:17px}
 .pos-wrap .products{grid-template-columns:repeat(2,minmax(0,1fr))}
 .pos-wrap .cart-row{grid-template-columns:minmax(0,1fr) 52px 68px 20px}
}
</style>

<div class="pos-wrap">
<div class="pos-title"><div><h1><i class="fas fa-cash-register"></i> Retail POS</h1><div class="muted">Scan ISBN / barcode, add items, collect payment and print receipt</div></div><div class="pos-actions"><?php if($storeOperationsEnabled): ?><label class="counter-label" style="display:flex;align-items:center;gap:7px">Store <select aria-label="POS store" onchange="window.location.href='index.php?store_id='+encodeURIComponent(this.value)" style="max-width:150px;padding:6px;border:1px solid #dbe3ec;border-radius:7px"><?php foreach($posStores as $posStore): ?><option value="<?= (int)$posStore['id'] ?>" <?= $selectedStoreId===(int)$posStore['id']?'selected':'' ?>><?= e($posStore['store_name']) ?></option><?php endforeach; ?></select></label><?php endif; ?><span class="counter-label"><i class="fas fa-barcode"></i> Scanner ready</span><button type="button" class="btn light" id="counterModeBtn" onclick="toggleCounterMode()"><i class="fas fa-expand"></i> Counter mode</button><?php if($canManageOwnShift):?><a class="btn light shift-shortcut" target="_blank" rel="noopener" href="<?=BASE_URL?>/modules/sales/my_shift.php"><i class="fas fa-cash-register"></i> My Shift</a><?php endif;?><?php if(canAccessModule('customer_orders')): ?><a class="btn light" href="<?=BASE_URL?>/modules/customer_orders/index.php"><i class="fas fa-file-signature"></i> Customer Orders</a><?php endif; ?></div></div>
<?php if($flash): ?><div class="flash"><?=e($flash)?></div><?php endif; ?>


<form method="post" action="store.php" id="saleForm">
<input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($storeOperationsEnabled): ?><input type="hidden" name="store_id" value="<?= (int)$selectedStoreId ?>"><?php endif; ?>
<div class="cashier">
<section class="catalog">
<div class="scan-panel"><label for="productSearch"><i class="fas fa-barcode"></i> SCAN ITEM OR SEARCH</label><div class="toolbar"><input id="productSearch" autofocus autocomplete="off" inputmode="search" placeholder="Scan barcode / ISBN / SKU or type product name..."><button type="button" class="btn light" onclick="clearSearch()" aria-label="Clear search"><i class="fas fa-times"></i></button></div><small class="scan-help">Keep the cursor here. Most USB scanners type the code and press Enter automatically. Shortcuts: F2 scan · F4 amount paid · Ctrl+Enter complete sale.</small><div id="scanFeedback" class="scan-feedback" aria-live="polite">Ready to scan or search products</div><div id="searchResults" class="search-results" role="listbox" aria-label="Matching products"></div></div>
<div class="section-heading"><div><h2>Browse products</h2><p>Find books, stationery and everyday essentials</p></div><span class="catalog-count" id="catalogCount"><?=count($products)?> items</span></div><div class="categories"><button type="button" class="cat active" data-category="all">All Products</button><?php foreach($categories as $cat): ?><button type="button" class="cat" data-category="<?=e(strtolower(trim($cat)))?>"><?=e($cat)?></button><?php endforeach; ?></div>
<div id="productGrid" class="products">
<?php foreach($products as $p): $category=strtolower(trim((string)$p['category'])); ?>
<div class="product <?=$p['stock_quantity']<=0?'out':''?>" data-id="<?=$p['id']?>" data-category="<?=e($category)?>" data-search="<?=e(strtolower($p['product_name'].' '.$p['sku'].' '.$p['barcode'].' '.$p['isbn'].' '.$p['brand_name']))?>">
<span class="badge"><?=number_format((float)$p['stock_quantity'],0).' '.e($p['unit']?:'units')?></span><?php if(!empty($p['image_path'])): ?><img class="product-image" src="<?=BASE_URL.'/'.e($p['image_path'])?>" alt=""><?php else: ?><div class="product-symbol"><i class="fas <?=stripos((string)$p['category'],'station')!==false?'fa-pen-ruler':(stripos((string)$p['category'],'book')!==false?'fa-book-open':'fa-box-open')?>"></i></div><?php endif; ?><div class="name"><?=e($p['product_name'])?></div><small><?=e($p['brand_name']?:'Generic')?> · <?=e($p['sku']?:'No SKU')?></small><div class="price">KES <span class="price-value"><?=number_format((float)$p['selling_price'],2)?></span></div>
</div>
<?php endforeach; ?>
</div>
</section>

<section class="checkout">
<div class="checkout-heading"><div><span class="checkout-kicker">CURRENT TRANSACTION</span><h3><i class="fas fa-shopping-basket"></i> Order summary</h3></div><div class="basket-count" id="basketCount">0 items</div></div>
<div class="field"><label for="customer_id">Customer (optional)</label><select name="customer_id" id="customer_id"><option value="">Walk-in / No customer</option><?php foreach($customers as $customer):?><option value="<?=(int)$customer['id']?>" data-price-list="<?=(int)($customer['price_list_id']??0)?>"><?=e($customer['customer_name'])?><?php if(!empty($customer['phone'])):?> — <?=e($customer['phone'])?><?php endif;?></option><?php endforeach;?></select><small class="muted">Choose a customer to attach this sale to their account and apply their assigned price list.</small></div><div class="field" style="display:none"><label>Price List</label><select name="price_list_id" id="price_list_id"><?php foreach($priceLists as $pl):?><option value="<?=$pl['id']?>" data-code="<?=e($pl['code'])?>"><?=e($pl['name'])?></option><?php endforeach;?></select></div>
<div class="cart-toolbar"><span>Items in this basket</span><button type="button" class="clear-cart" onclick="clearCart()">Clear all</button></div><div id="cart" class="cart"><div class="empty">Your basket is empty. Select a product to begin.</div></div>
<input type="hidden" name="cart_json" id="cart_json">
<div class="field"><label>Discount (KES)</label><input type="number" name="discount" id="discount" min="0" step=".01" value="0"></div>
<div class="field vat-summary"><label>VAT (<?=$vatRate?>%)</label><div class="vat-value"><?=$vatRate?>% VAT</div><small class="muted"><?=e($vatMode==='inclusive'?'Included in displayed prices':'Added to selling prices')?></small><input type="hidden" name="tax" id="tax" value="0"></div>
<div class="field payment-method-field"><label for="payment_method">Payment method</label><select name="payment_method" id="payment_method"><option value="Cash" selected>Cash</option><option value="M-Pesa">M-PESA</option><option value="Bank">Bank / Transfer</option></select><small class="muted">Choose how the customer is paying to display the relevant payment details.</small></div>
<div class="field payment-detail" id="cashPaymentField"><label for="cash_tendered">Cash received (KES)</label><input type="number" name="cash_tendered" id="cash_tendered" min="0" step=".01" value="0"><small class="muted">Enter the cash physically received. Change is calculated automatically.</small></div>
<div class="field payment-detail" id="bankPaymentField" hidden><label for="bank_amount">Bank / transfer received (KES)</label><input type="number" name="bank_amount" id="bank_amount" min="0" step=".01" value="0"><small class="muted">Enter the amount confirmed as received in the bank.</small></div>
<div class="field payment-detail" id="mpesaPaymentField" hidden><label for="mpesa_amount">M-PESA amount (KES)</label><input type="number" name="mpesa_amount" id="mpesa_amount" min="0" step=".01" value="0"><small class="muted">An STK Push will be sent. The payment is applied after M-PESA confirmation.</small></div>
<div class="field payment-detail" id="mpesaPhoneField" hidden><label for="mpesa_phone">M-PESA phone number</label><input name="mpesa_phone" id="mpesa_phone" placeholder="07XXXXXXXX" maxlength="15"><small class="muted">Enter the customer's Safaricom number.</small></div>
<div class="totals"><div class="line"><span>Subtotal</span><strong id="subtotal">KES 0.00</strong></div><div class="line"><span>Discount</span><strong id="discountView">KES 0.00</strong></div><div class="line"><span>VAT</span><strong id="taxView">KES 0.00</strong></div><div class="line grand"><span>Total</span><strong id="total">KES 0.00</strong></div><div class="line"><span>Balance due</span><strong id="balance">KES 0.00</strong></div><div class="line change-line"><span>Change to give</span><strong id="changeDue">KES 0.00</strong></div></div><button type="button" class="btn light exact-btn" onclick="setExactPayment()">Set exact amount</button>
<div class="field"><label>Notes</label><input name="notes" maxlength="500" placeholder="Optional note"></div>
<button class="btn success" type="submit"><i class="fas fa-check-circle"></i> Complete Sale &amp; Print Receipt</button>
</section>
</div>
</form>

<div class="recent"><h3>Recent Sales</h3><table class="table"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Method</th><th>Receipt</th></tr></thead><tbody><?php foreach($recent as $r): ?><tr><td><strong><?=e($r['invoice_number'])?></strong></td><td><?=e($r['sale_date'])?></td><td><?php if(!empty($r['customer_id'])):?><a href="<?=BASE_URL?>/modules/customers/view.php?id=<?=(int)$r['customer_id']?>"><?=e($r['customer_name'])?></a><?php else:?><?=e($r['customer_name'])?><?php endif;?></td><td>KES <?=number_format((float)$r['total_amount'],2)?></td><td>KES <?=number_format((float)$r['paid_amount'],2)?></td><td>KES <?=number_format((float)$r['balance'],2)?></td><td><?=e($r['payment_method'])?></td><td><a class="print-link" target="_blank" href="receipt.php?id=<?=$r['id']?>"><i class="fas fa-print"></i> Print</a></td></tr><?php endforeach; ?></tbody></table></div>
</div>
</main>
<script>
const isCashierTerminal=<?=json_encode($isCashierTerminal)?>;
const vatRate=<?=json_encode($vatRate)?>,vatMode=<?=json_encode($vatMode)?>;
const products=<?=json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['product_name'],'category'=>strtolower(trim((string)$p['category'])),'sku'=>$p['sku'],'barcode'=>$p['barcode'],'isbn'=>$p['isbn'],'price'=>(float)$p['selling_price'],'normalPrice'=>(float)$p['selling_price'],'wholesalePrice'=>(float)$p['wholesale_price'],'bulkPrice'=>$p['bulk_selling_price']===null?null:(float)$p['bulk_selling_price'],'bulkWholesalePrice'=>$p['bulk_wholesale_price']===null?null:(float)$p['bulk_wholesale_price'],'purchaseUnit'=>$p['purchase_unit']?:$p['unit'],'factor'=>max(1,(int)$p['units_per_purchase']),'stock'=>(float)$p['stock_quantity'],'unit'=>$p['unit']],$products),JSON_UNESCAPED_UNICODE)?>;
const coveringRules=<?=json_encode(array_map(fn($r)=>['bookId'=>(int)$r['book_product_id'],'charge'=>(float)$r['charge_per_book']],$coveringRules),JSON_UNESCAPED_UNICODE)?>;
const priceTiers=<?=json_encode(array_map(fn($x)=>['list'=>(int)$x['price_list_id'],'product'=>(int)$x['product_id'],'min'=>(int)$x['min_quantity'],'price'=>(float)$x['price']],$productPrices),JSON_UNESCAPED_UNICODE)?>;
let cart=[];
function selectedPrice(p,qty=1){const listId=Number(document.getElementById('price_list_id').value||0);const tiers=priceTiers.filter(t=>t.list===listId&&t.product===p.id&&t.min<=qty).sort((a,b)=>b.min-a.min);return tiers.length?tiers[0].price:null;}
function unitPrice(p,mode){const list=document.getElementById('price_list_id'),code=list?.options[list.selectedIndex]?.dataset.code||'normal';if(mode==='bulk'){if(code==='wholesale'&&p.bulkWholesalePrice!==null&&p.bulkWholesalePrice>0)return p.bulkWholesalePrice;if(code!=='wholesale'&&p.bulkPrice!==null&&p.bulkPrice>0)return p.bulkPrice;const base=selectedPrice(p,p.factor);return base===null?null:base*p.factor;}return selectedPrice(p,1);}
function baseQty(x){return x.qty*(x.saleUnit==='bulk'?x.factor:1);}
function addProduct(id){const p=products.find(x=>x.id===id);if(!p||p.stock<=0)return;const row=cart.find(x=>x.id===id&&x.saleUnit==='base');const nextBase=(row?baseQty(row):0)+1;if(nextBase>p.stock){setScanFeedback("Available stock limit reached for "+p.name,"error");return}const price=unitPrice(p,'base');if(price===null){setScanFeedback("No price is configured for "+p.name,"error");return}if(row){row.saleUnit='base';row.factor=p.factor;row.qty=nextBase;row.price=price;}else cart.push({...p,price,qty:1,saleUnit:'base'});setScanFeedback("Added: "+p.name+" · 1 "+p.unit,"success");render();focusScanner();}
function changeSaleUnit(i,mode){const x=cart[i];if(!x)return;const p=products.find(y=>y.id===x.id);if(!p)return;const nextMode=mode==='bulk'&&p.factor>1?'bulk':'base';const price=unitPrice(p,nextMode);if(price===null){alert("No price is configured for this selling unit.");render();return}x.saleUnit=nextMode;x.factor=p.factor;x.qty=Math.max(1,x.qty);x.price=price;if(baseQty(x)>p.stock){alert("Not enough stock for that quantity.");x.saleUnit='base';x.factor=p.factor;x.qty=Math.min(x.qty,p.stock);x.price=unitPrice(p,'base');}render();}
function render(){const box=document.getElementById('cart');const count=cart.reduce((n,x)=>n+baseQty(x),0);const basket=document.getElementById('basketCount');if(basket)basket.textContent=count+' '+(count===1?'unit':'units');box.innerHTML=cart.length?cart.map((x,i)=>{const rule=coveringRules.find(r=>r.bookId===x.id);const extra=rule&&x.cover?rule.charge*baseQty(x):0;return '<div class="cart-row"><div><strong>'+esc(x.name)+'</strong><br><small>'+esc(x.saleUnit==='bulk'?x.purchaseUnit:x.unit)+' · KES '+money(x.price)+' each</small>'+(rule?'<label style="display:block;margin-top:5px;font-size:11px;color:#0755a0"><input type="checkbox" '+(x.cover?'checked':'')+' onchange="toggleCover('+i+',this.checked)"> Add covering service (+KES '+money(rule.charge)+' each)</label>':'')+'</div><div><select aria-label="Selling unit" onchange="changeSaleUnit('+i+',this.value)" style="width:100%;font-size:10px;padding:4px"><option value="base" '+(x.saleUnit==='base'?'selected':'')+'>'+esc(x.unit)+'</option>'+(x.factor>1?'<option value="bulk" '+(x.saleUnit==='bulk'?'selected':'')+'>'+esc(x.purchaseUnit)+'</option>':'')+'</select><input type="number" min="1" max="'+(x.saleUnit==='bulk'?Math.floor(x.stock/x.factor):x.stock)+'" value="'+x.qty+'" onchange="setQty('+i+',this.value)"></div><span>KES '+money(x.price*x.qty+extra)+'</span><button type="button" class="remove" onclick="removeItem('+i+')">&times;</button></div>';}).join(''):'<div class="empty">Add products from the catalog.</div>';calc();}
function toggleCover(i,enabled){if(!cart[i])return;cart[i].cover=!!enabled;render();}
function setQty(i,v){const x=cart[i];if(!x)return;const p=products.find(y=>y.id===x.id);if(!p)return;const qty=Math.max(1,Math.floor(Number(v)||1));if(qty*(x.saleUnit==='bulk'?x.factor:1)>p.stock){alert("Not enough stock available.");render();return}const price=unitPrice(p,x.saleUnit);if(price===null){alert("No price is configured for this quantity in the selected price list.");render();return}x.qty=qty;x.price=price;render()}function removeItem(i){cart.splice(i,1);render()}
function calc(){let sub=cart.reduce((a,x)=>{const r=coveringRules.find(z=>z.bookId===x.id);return a+x.qty*x.price+(r&&x.cover?r.charge*baseQty(x):0)},0),d=Math.max(0,Number(document.getElementById('discount').value)||0),taxable=Math.max(0,sub-d),t=vatMode==='inclusive'?taxable*vatRate/(100+vatRate):taxable*vatRate/100,total=vatMode==='inclusive'?taxable:taxable+t;const method=document.getElementById('payment_method')?.value;if(method==='M-Pesa'&&mpesaAmountAuto){document.getElementById('mpesa_amount').value=Math.max(0,total).toFixed(2);}let cash=Math.max(0,Number(document.getElementById('cash_tendered').value)||0),bank=Math.max(0,Number(document.getElementById('bank_amount').value)||0),mpesa=Math.max(0,Number(document.getElementById('mpesa_amount').value)||0),cashApplied=Math.min(cash,Math.max(0,total-bank-mpesa)),planned=cashApplied+bank+mpesa;document.getElementById('tax').value=t.toFixed(2);document.getElementById('subtotal').textContent='KES '+money(sub);document.getElementById('discountView').textContent='KES '+money(d);document.getElementById('taxView').textContent='KES '+money(t);document.getElementById('total').textContent='KES '+money(total);document.getElementById('balance').textContent='KES '+money(Math.max(0,total-planned));document.getElementById('changeDue').textContent='KES '+money(Math.max(0,cash-cashApplied));document.getElementById('cart_json').value=JSON.stringify(cart.map(x=>({id:x.id,qty:x.qty,unit:x.saleUnit,cover:!!x.cover})));}
function setExactPayment(){const sub=Math.max(0,cart.reduce((a,x)=>{const r=coveringRules.find(z=>z.bookId===x.id);return a+x.qty*x.price+(r&&x.cover?r.charge*x.qty:0)},0)-Number(document.getElementById('discount').value||0)),total=vatMode==='inclusive'?sub:sub+(sub*vatRate/100);const method=document.getElementById('payment_method').value;['cash_tendered','bank_amount','mpesa_amount'].forEach(id=>document.getElementById(id).value='0');const target=method==='M-Pesa'?'mpesa_amount':method==='Bank'?'bank_amount':'cash_tendered';document.getElementById(target).value=Math.max(0,total).toFixed(2);calc();}
function setScanFeedback(message,type='success'){const el=document.getElementById('scanFeedback');if(el){el.textContent=message;el.style.color=type==='error'?'#b42318':'#147d5a';}}
function focusScanner(){const el=document.getElementById('productSearch');if(el){el.focus();el.select();}}
function toggleCounterMode(){document.body.classList.toggle('counter-mode');const active=document.body.classList.contains('counter-mode');const btn=document.getElementById('counterModeBtn');btn.innerHTML=active?'<i class="fas fa-compress"></i> Exit counter mode':'<i class="fas fa-expand"></i> Counter mode';if(active){if(document.documentElement.requestFullscreen)document.documentElement.requestFullscreen().catch(()=>{});}else if(document.fullscreenElement&&document.exitFullscreen){document.exitFullscreen().catch(()=>{});}focusScanner();}

let activeCategory='all';
function normalizeCategory(value){return String(value??'').trim().toLocaleLowerCase().replace(/\s+/g,' ');}
function applyCategoryFilter(){
 const cards=[...document.querySelectorAll('#productGrid .product')];
 let visible=0;
 cards.forEach(card=>{const show=activeCategory==='all'||normalizeCategory(card.dataset.category)===activeCategory;card.style.display=show?'':'none';if(show)visible++;});
 const count=document.getElementById('catalogCount');if(count)count.textContent=visible+' items';
}
function filterCategory(cat,btn){
 activeCategory=normalizeCategory(cat||'all');
 document.querySelectorAll('.cat').forEach(x=>x.classList.toggle('active',normalizeCategory(x.dataset.category)===activeCategory));
 const search=document.getElementById('productSearch');if(search)search.value='';
 const grid=document.getElementById('productGrid'),results=document.getElementById('searchResults');
 if(grid)grid.style.display='grid';if(results){results.style.display='none';results.innerHTML='';}
 filterProducts();
}
function updateCatalogPrices(){document.querySelectorAll('.product').forEach(card=>{const p=products.find(x=>x.id===Number(card.dataset.id));if(p){const price=selectedPrice(p,1);card.querySelector('.price-value').textContent=price===null?"Not set":money(price);}});cart=cart.filter(x=>{const p=products.find(y=>y.id===x.id);const price=p?unitPrice(p,x.saleUnit):null;if(price===null)return false;x.price=price;return true;});updatePriceTierHint();render();}
function normalizeLookupCode(value){return String(value??'').toLowerCase().replace(/[\s-]+/g,'');}
function filterProducts(){
 const q=normalizeLookupCode(document.getElementById('productSearch').value);
 const cards=[...document.querySelectorAll('#productGrid .product')];
 let visible=0;
 cards.forEach(card=>{
  const inCategory=activeCategory==='all'||normalizeCategory(card.dataset.category)===activeCategory;
  const matches=!q||normalizeLookupCode(card.dataset.search||'').includes(q);
  const show=inCategory&&matches;
  card.style.display=show?'':'none';
  if(show)visible++;
 });
 const count=document.getElementById('catalogCount');
 if(count)count.textContent=visible+' items';
 const grid=document.getElementById('productGrid'),results=document.getElementById('searchResults');
 if(grid)grid.style.display='grid';
 if(results){results.style.display='none';results.innerHTML='';}
}
function clearSearch(){document.getElementById('productSearch').value='';filterProducts();document.getElementById('productSearch').focus();}
function clearCart(){if(!cart.length)return;if(!confirm('Clear all items from this sale?'))return;cart=[];render();setScanFeedback('Basket cleared. Ready for the next items.');focusScanner();}
function money(n){return Number(n).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2})}function esc(s){return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]||m))}
document.querySelector('.categories').addEventListener('click',e=>{const button=e.target.closest('.cat');if(button)filterCategory(button.dataset.category,button);});
document.getElementById('productGrid').addEventListener('click',e=>{const card=e.target.closest('.product');if(!card||card.classList.contains('out'))return;addProduct(Number(card.dataset.id));});
document.getElementById('productSearch').addEventListener('input',filterProducts);document.getElementById('productSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();const q=normalizeLookupCode(e.target.value);const exact=products.find(x=>[x.barcode,x.isbn,x.sku].some(v=>v&&normalizeLookupCode(v)===q));const matches=products.filter(x=>normalizeLookupCode([x.name,x.sku,x.barcode,x.isbn].filter(Boolean).join(' ')).includes(q));const p=exact||(matches.length===1?matches[0]:null);if(p){addProduct(p.id);clearSearch();}else{setScanFeedback(matches.length?'More than one item matches. Select the correct item from the list.':'No matching product found. Check the name or code.','error');}}});const defaultPriceListId=document.getElementById('price_list_id').value;document.getElementById('customer_id').addEventListener('change',()=>{const option=document.getElementById('customer_id').selectedOptions[0];const customerPriceList=Number(option?.dataset.priceList||0);document.getElementById('price_list_id').value=customerPriceList>0?String(customerPriceList):defaultPriceListId;updateCatalogPrices();});document.getElementById('price_list_id').addEventListener('change',updateCatalogPrices);document.getElementById('discount').addEventListener('input',calc);let mpesaAmountAuto=true;['cash_tendered','bank_amount'].forEach(id=>document.getElementById(id).addEventListener('input',calc));document.getElementById('mpesa_amount').addEventListener('input',()=>{mpesaAmountAuto=false;calc();});
function updatePaymentFields(resetAmounts=false){const method=document.getElementById('payment_method').value;document.getElementById('cashPaymentField').hidden=method!=='Cash';document.getElementById('bankPaymentField').hidden=method!=='Bank';document.getElementById('mpesaPaymentField').hidden=method!=='M-Pesa';document.getElementById('mpesaPhoneField').hidden=method!=='M-Pesa';if(resetAmounts){['cash_tendered','bank_amount','mpesa_amount'].forEach(id=>document.getElementById(id).value='0');mpesaAmountAuto=true;}calc();}
document.getElementById('payment_method').addEventListener('change',()=>updatePaymentFields(true));updatePaymentFields();
document.getElementById('saleForm').addEventListener('submit',e=>{if(!cart.length){e.preventDefault();alert('Add at least one product.');return}let sub=Math.max(0,cart.reduce((a,x)=>a+x.qty*x.price,0)-Number(document.getElementById('discount').value||0)),total=vatMode==='inclusive'?sub:sub+(sub*vatRate/100),cash=Math.max(0,Number(document.getElementById('cash_tendered').value||0)),bank=Math.max(0,Number(document.getElementById('bank_amount').value||0)),mpesa=Math.max(0,Number(document.getElementById('mpesa_amount').value||0)),cashApplied=Math.min(cash,Math.max(0,total-bank-mpesa));if(bank+mpesa>total+0.001){e.preventDefault();alert('Payment amounts exceed the sale total.');return}if(mpesa>0&&!document.getElementById('mpesa_phone').value.trim()){e.preventDefault();alert('Enter the customer M-PESA phone number.');return}});render();
if(isCashierTerminal){document.body.classList.add('counter-mode');const terminalButton=document.getElementById('counterModeBtn');if(terminalButton)terminalButton.innerHTML='<i class="fas fa-compress"></i> Exit counter mode';}
function updatePriceTierHint(){const hint=document.getElementById('priceTierHint');if(!hint)return;const select=document.getElementById('price_list_id');const code=select.options[select.selectedIndex]?.dataset.code||'';const label=select.options[select.selectedIndex]?.text||'Price list';const active=priceTiers.some(t=>t.list===Number(select.value));document.getElementById('priceTierHint').textContent=(code==='normal'?'Standard price':code==='wholesale'?'Wholesale pricing':label)+(active?' • Quantity tiers active':' • Configure product prices');}
document.getElementById('price_list_id').addEventListener('change',updateCatalogPrices);
updatePriceTierHint();
document.addEventListener('keydown',e=>{const tag=(e.target&&e.target.tagName||'').toLowerCase();const typing=['input','textarea','select'].includes(tag);if(e.key==='F2'){e.preventDefault();focusScanner();return}if(e.key==='F4'){e.preventDefault();const method=document.getElementById('payment_method').value;const paid=document.getElementById(method==='M-Pesa'?'mpesa_amount':method==='Bank'?'bank_amount':'cash_tendered');paid.focus();paid.select();return}if(e.key==='Escape'&&document.activeElement===document.getElementById('productSearch')){clearSearch();return}if(e.ctrlKey&&e.key==='Enter'){e.preventDefault();document.getElementById('saleForm').requestSubmit();return}if(!typing&&e.key==='/'&&!e.ctrlKey&&!e.altKey){e.preventDefault();focusScanner();}});
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>