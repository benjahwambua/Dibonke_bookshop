<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
$pageTitle='Retail POS';
$vatRate=(float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_rate' LIMIT 1")->fetchColumn()?:16);
$vatMode=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_mode' LIMIT 1")->fetchColumn()?:'inclusive');
if(!in_array($vatMode,['exclusive','inclusive'],true))$vatMode='inclusive';

$products=$conn->query("SELECT p.id,p.product_name,p.category,p.sku,p.barcode,p.isbn,p.selling_price,p.wholesale_price,p.stock_quantity,p.unit,b.brand_name FROM products p LEFT JOIN brands b ON b.id=p.brand_id WHERE p.status='Active' ORDER BY p.product_name")->fetchAll();
$customers=$conn->query("SELECT id,customer_name,phone,credit_limit,balance,price_list_id FROM customers WHERE status='Active' ORDER BY customer_name")->fetchAll();
$priceLists=$conn->query("SELECT id,name,code FROM price_lists WHERE status='Active' ORDER BY is_default DESC,name")->fetchAll();
$productPrices=$conn->query("SELECT price_list_id,product_id,min_quantity,price FROM product_prices ORDER BY price_list_id,product_id,min_quantity")->fetchAll();
$categories=$conn->query("SELECT DISTINCT category FROM products WHERE status='Active' AND category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$roleName = strtolower(trim((string)($_SESSION['role'] ?? '')));
$roleCode = strtoupper(trim((string)($_SESSION['role_code'] ?? '')));
$isCashierTerminal = in_array($roleName, ['cashier', 'sales'], true) || in_array($roleCode, ['CASHIER', 'SALES'], true);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
if ($isCashierTerminal) {
    $statsStmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN DATE(sale_date)=CURDATE() THEN total_amount ELSE 0 END),0) today, COALESCE(SUM(CASE WHEN YEAR(sale_date)=YEAR(CURDATE()) AND MONTH(sale_date)=MONTH(CURDATE()) THEN total_amount ELSE 0 END),0) month FROM sales WHERE sale_status='Completed' AND user_id=?");
    $statsStmt->execute([$currentUserId]);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = ['today'=>(float)($statsRow['today']??0),'month'=>(float)($statsRow['month']??0),'credit'=>0.0];
    $recentStmt = $conn->prepare("SELECT s.id,s.invoice_number,s.sale_date,s.total_amount,s.paid_amount,s.balance,s.payment_method,COALESCE(c.customer_name,'Walk-in Customer') customer_name FROM sales s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.sale_status='Completed' AND s.user_id=? ORDER BY s.id DESC LIMIT 10");
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
require_once __DIR__.'/../../includes/header.php';
require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
body.counter-mode .top-header,body.counter-mode #appSidebar,body.counter-mode .mobile-sidebar-backdrop,body.counter-mode .kpis,body.counter-mode .recent,body.counter-mode .pos-actions>a{display:none!important}body.counter-mode .content{margin-left:0!important;padding:14px!important}body.counter-mode .pos-wrap{max-width:none}body.counter-mode .pos-title{margin-bottom:8px}body.counter-mode .cashier{grid-template-columns:minmax(0,1.8fr) minmax(390px,.8fr);gap:12px}body.counter-mode .products{max-height:calc(100vh - 250px);grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}body.counter-mode .product{min-height:118px;padding:14px}body.counter-mode .checkout{position:sticky;top:12px}body.counter-mode .cart{max-height:35vh}
.pos-wrap{--blue:#004a99;--green:#047857;--border:#e2e8f0;max-width:1600px;margin:auto;font-size:15px}
.pos-wrap .pos-title h1{font-size:28px}.pos-wrap .scan-panel{padding:18px;background:#edf5ff;border:2px solid #9fc5ee}.pos-wrap .scan-panel>label{font-size:15px}
.pos-wrap .toolbar input{min-height:60px;font-size:21px}.pos-wrap .scan-help{font-size:12px}.pos-wrap .scan-feedback{font-size:14px;min-height:20px}
.pos-wrap .catalog,.pos-wrap .checkout{padding:20px}.pos-wrap .products{grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:12px}
.pos-wrap .product{min-height:130px;padding:15px;border:2px solid #e2e8f0}.pos-wrap .product .name{font-size:15px}.pos-wrap .product .price{font-size:18px}
.pos-wrap .product small{font-size:12px}.pos-wrap .badge{font-size:12px}.pos-wrap .field label{font-size:13px}
.pos-wrap .field input,.pos-wrap .field select{min-height:46px;font-size:15px}.pos-wrap .cart-row{font-size:14px;padding:12px 0}
.pos-wrap .cart-row input{width:58px;min-height:34px;font-size:14px}.pos-wrap .line{font-size:15px}.pos-wrap .grand{font-size:23px}
.pos-wrap .success{min-height:58px;font-size:18px}.vat-value{font-size:18px;font-weight:800;color:#174a7e;padding:10px 0}
.pos-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}.pos-title h1{margin:0}.muted{color:#64748b}.pos-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.counter-label{display:inline-flex;align-items:center;gap:7px;color:#147d5a;font-size:11px;font-weight:800}.scan-panel{padding:12px;margin-bottom:12px;border:1px solid #d9e4ee;border-radius:10px;background:#f8fbfe}.scan-panel>label{display:block;margin-bottom:7px;color:#29445e;font-size:11px;font-weight:900;letter-spacing:.04em}.scan-help{display:block;margin-top:6px;color:#718096;font-size:10px}.scan-feedback{margin-top:7px;color:#147d5a;font-size:11px;font-weight:750;min-height:15px}.toolbar input{min-height:48px;font-size:17px;font-weight:600}.change-line{color:#147d5a!important;font-weight:800}.exact-btn{width:100%;margin-bottom:8px}
.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}.kpi{background:#fff;border:1px solid var(--border);border-radius:10px;padding:13px 16px}.kpi span{display:block;color:#64748b;font-size:11px;text-transform:uppercase}.kpi strong{font-size:21px}
.cashier{display:grid;grid-template-columns:minmax(0,1.65fr) 390px;gap:14px;align-items:start}
.catalog,.checkout,.recent{background:#fff;border:1px solid var(--border);border-radius:10px}.catalog{padding:14px}.checkout{padding:16px;position:sticky;top:75px}
.toolbar{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:10px}.toolbar input,.field input,.field select{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:7px;background:#fff}.toolbar input{font-size:15px;padding:12px}
.categories{display:flex;gap:7px;overflow:auto;padding-bottom:10px}.cat{border:1px solid #cbd5e1;background:#f8fafc;border-radius:20px;padding:7px 12px;cursor:pointer;white-space:nowrap;font-size:12px}.cat.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.products{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;max-height:570px;overflow:auto;padding-right:2px}.product{min-height:105px;border:1px solid var(--border);border-radius:9px;padding:11px;background:#fff;cursor:pointer;position:relative}.product:hover{border-color:var(--blue);box-shadow:0 2px 8px #0001}.product.out{opacity:.45;cursor:not-allowed}.product .name{font-weight:800;font-size:13px;line-height:1.25}.product small{display:block;color:#64748b;margin-top:5px}.product .price{font-weight:800;color:var(--blue);margin-top:8px}.badge{position:absolute;right:7px;top:7px;font-size:10px;background:#f1f5f9;padding:3px 6px;border-radius:10px;color:#475569}
.checkout h3{margin:0 0 12px}.field{margin:9px 0}.field label{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px;text-transform:uppercase}.cart{max-height:330px;overflow:auto;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}.empty{text-align:center;color:#94a3b8;padding:35px 10px;font-size:13px}.cart-row{display:grid;grid-template-columns:1fr 54px 76px 22px;gap:5px;align-items:center;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:12px}.cart-row input{width:50px;padding:5px;border:1px solid #cbd5e1;border-radius:5px}.remove{border:0;background:none;color:#dc2626;font-size:18px;cursor:pointer}.totals{padding:9px 0}.line{display:flex;justify-content:space-between;margin:6px 0;color:#475569;font-size:13px}.grand{font-size:19px;font-weight:900;color:#0f172a;border-top:1px dashed #cbd5e1;padding-top:9px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:7px;padding:10px 13px;font-weight:800;cursor:pointer;text-decoration:none}.primary{background:var(--blue);color:#fff}.success{background:var(--green);color:#fff;width:100%;font-size:15px;padding:13px}.light{background:#eef2f7;color:#334155}.flash{padding:11px;background:#ecfdf5;color:#166534;border-radius:7px;margin-bottom:12px}.recent{margin-top:14px;overflow:auto}.recent h3{padding:0 14px}.table{width:100%;border-collapse:collapse;min-width:750px}.table th{background:var(--blue);color:#fff;text-align:left;padding:9px;font-size:11px}.table td{padding:9px;border-bottom:1px solid #edf2f7;font-size:12px}.print-link{color:var(--blue);font-weight:700;text-decoration:none}.price-tier-hint{font-size:11px;color:#64748b;margin-top:5px}
@media(max-width:1150px){.products{grid-template-columns:repeat(3,1fr)}.cashier{grid-template-columns:minmax(0,1fr) 360px}}
@media(max-width:900px){.cashier{grid-template-columns:1fr}.checkout{position:static}.products{grid-template-columns:repeat(3,1fr)}}
@media(max-width:650px){.kpis{grid-template-columns:1fr}.products{grid-template-columns:repeat(2,1fr)}.pos-title{align-items:flex-start;gap:10px;flex-direction:column}}

/* Supermarket checkout terminal refresh */
.pos-wrap{max-width:1900px;color:#172b43}
.pos-title{background:#17324d;color:#fff;padding:18px 22px;border-radius:13px;margin:0 0 14px;box-shadow:0 5px 18px rgba(16,38,59,.13)}
.pos-title h1{font-size:25px!important;color:#fff;letter-spacing:-.4px}
.pos-title .muted{color:#c5d3e0;margin-top:5px}
.pos-title .counter-label{color:#b8f2d7}
.pos-title .btn.light{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2)}
.pos-wrap .scan-panel{background:#eef6ff;border:2px solid #8ab9e8;border-radius:13px;padding:16px 18px;box-shadow:0 3px 12px rgba(23,74,126,.06)}
.pos-wrap .scan-panel>label{font-size:13px;letter-spacing:.06em;color:#17324d}
.pos-wrap .toolbar input{height:62px;border:2px solid #b8cde2;border-radius:10px;padding:12px 16px;font-size:22px;font-weight:700;background:#fff}
.pos-wrap .toolbar input:focus,.pos-wrap .field input:focus,.pos-wrap .field select:focus{outline:3px solid rgba(37,99,235,.15);border-color:#2563eb}
.pos-wrap .catalog,.pos-wrap .checkout{border:1px solid #d8e1eb;border-radius:13px;box-shadow:0 4px 16px rgba(16,38,59,.055)}
.pos-wrap .catalog{padding:16px}
.pos-wrap .checkout{padding:18px;background:#fbfcfe;top:12px}
.pos-wrap .products{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:11px;max-height:calc(100vh - 360px);min-height:280px}
.pos-wrap .product{min-height:132px;border:1px solid #dce5ef;border-radius:11px;padding:15px;background:linear-gradient(180deg,#fff,#fbfdff);transition:transform .15s,border-color .15s,box-shadow .15s}
.pos-wrap .product:hover{transform:translateY(-2px);border-color:#4386c5;box-shadow:0 7px 18px rgba(23,74,126,.12)}
.pos-wrap .product .name{font-size:15px;color:#20364d;line-height:1.35;padding-right:28px}
.pos-wrap .product .price{font-size:19px;color:#0755a0}
.pos-wrap .product small{font-size:11px}
.pos-wrap .badge{background:#eaf1f8;color:#38536e;font-weight:800}
.pos-wrap .cat{padding:9px 15px;font-weight:750;font-size:12px}
.pos-wrap .cat.active{background:#17324d;border-color:#17324d}
.pos-wrap .checkout h3{font-size:19px;color:#17324d;padding-bottom:12px;border-bottom:1px solid #e2e8f0}
.pos-wrap .field{margin:12px 0}
.pos-wrap .field label{font-size:11px;color:#40566c}
.pos-wrap .field input,.pos-wrap .field select{min-height:45px;border:1px solid #cbd7e3;border-radius:8px;font-size:14px}
.pos-wrap .cart{max-height:34vh;background:#fff;border-radius:9px;padding:0 10px}
.pos-wrap .cart-row{grid-template-columns:minmax(0,1fr) 58px 82px 25px;gap:7px;font-size:13px;padding:13px 0}
.pos-wrap .cart-row strong{color:#20364d}
.pos-wrap .cart-row input{width:52px;min-height:34px;border-radius:6px;text-align:center}
.pos-wrap .grand{font-size:25px;color:#0755a0;border-top:2px solid #d9e4ef;padding-top:12px}
.pos-wrap .success{min-height:62px;border-radius:10px;font-size:18px;letter-spacing:.2px;background:#08784f;box-shadow:0 4px 10px rgba(8,120,79,.18)}
.pos-wrap .exact-btn{border-radius:8px}
body.counter-mode .content{padding:12px!important;background:#edf1f5}
body.counter-mode .pos-title{border-radius:10px}
body.counter-mode .cashier{grid-template-columns:minmax(0,1fr) minmax(410px,480px);gap:14px}
body.counter-mode .products{max-height:calc(100vh - 290px);min-height:360px;grid-template-columns:repeat(auto-fill,minmax(185px,1fr))}
body.counter-mode .checkout{height:calc(100vh - 110px);overflow:auto}
body.counter-mode .cart{max-height:34vh}
@media(max-width:1000px){body.counter-mode .cashier{grid-template-columns:1fr}body.counter-mode .checkout{height:auto;position:static}}
</style>

<div class="pos-wrap">
<div class="pos-title"><div><h1><i class="fas fa-cash-register"></i> Retail POS</h1><div class="muted">Scan ISBN / barcode, add items, collect payment and print receipt</div></div><div class="pos-actions"><span class="counter-label"><i class="fas fa-barcode"></i> Scanner ready</span><button type="button" class="btn light" id="counterModeBtn" onclick="toggleCounterMode()"><i class="fas fa-expand"></i> Counter mode</button><a class="btn light" href="<?=BASE_URL?>/modules/customers/index.php"><i class="fas fa-users"></i> Customers</a></div></div>
<?php if($flash): ?><div class="flash"><?=e($flash)?></div><?php endif; ?>
<div class="kpis"><div class="kpi"><span>Today's Sales</span><strong>KES <?=number_format($stats['today'],2)?></strong></div><div class="kpi"><span>This Month</span><strong>KES <?=number_format($stats['month'],2)?></strong></div><div class="kpi"><span>Customer Credit</span><strong>KES <?=number_format($stats['credit'],2)?></strong></div></div>

<form method="post" action="store.php" id="saleForm">
<input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="cashier">
<section class="catalog">
<div class="scan-panel"><label for="productSearch"><i class="fas fa-barcode"></i> SCAN ITEM OR SEARCH</label><div class="toolbar"><input id="productSearch" autofocus autocomplete="off" inputmode="search" placeholder="Scan barcode / ISBN / SKU or type product name..."><button type="button" class="btn light" onclick="clearSearch()" aria-label="Clear search"><i class="fas fa-times"></i></button></div><small class="scan-help">Keep the cursor here. Most USB scanners type the code and press Enter automatically. Shortcuts: F2 scan · F4 amount paid · Ctrl+Enter complete sale.</small><div id="scanFeedback" class="scan-feedback" aria-live="polite">Ready to scan or search products</div></div>
<div class="categories"><button type="button" class="cat active" data-category="all" onclick="filterCategory('all',this)">All Products</button><?php foreach($categories as $cat): ?><button type="button" class="cat" data-category="<?=e(strtolower($cat))?>" onclick="filterCategory(<?=json_encode(strtolower($cat))?>,this)"><?=e($cat)?></button><?php endforeach; ?></div>
<div id="productGrid" class="products">
<?php foreach($products as $p): $category=strtolower((string)$p['category']); ?>
<div class="product <?=$p['stock_quantity']<=0?'out':''?>" data-id="<?=$p['id']?>" data-category="<?=e($category)?>" data-search="<?=e(strtolower($p['product_name'].' '.$p['sku'].' '.$p['barcode'].' '.$p['isbn'].' '.$p['brand_name']))?>" onclick="<?=$p['stock_quantity']>0?'addProduct('.(int)$p['id'].')':'void(0)'?>">
<span class="badge"><?=number_format((float)$p['stock_quantity'],0).' '.e($p['unit']?:'units')?></span><div class="name"><?=e($p['product_name'])?></div><small><?=e($p['brand_name']?:'Generic')?> · <?=e($p['sku']?:'No SKU')?></small><div class="price">KES <span class="price-value"><?=number_format((float)$p['selling_price'],2)?></span></div>
</div>
<?php endforeach; ?>
</div>
</section>

<section class="checkout">
<h3><i class="fas fa-shopping-cart"></i> Current Sale</h3>
<div class="field"><label>Price List</label><select name="price_list_id" id="price_list_id"><?php foreach($priceLists as $pl):?><option value="<?=$pl['id']?>" data-code="<?=e($pl['code'])?>"><?=e($pl['name'])?></option><?php endforeach;?></select></div>
<div class="price-tier-hint" id="priceTierHint"></div><div class="field"><label>Customer</label><select name="customer_id" id="customer_id"><option value="">Walk-in Customer</option><?php foreach($customers as $c): ?><option value="<?=$c['id']?>" data-limit="<?=$c['credit_limit']?>" data-balance="<?=$c['balance']?>" data-price-list="<?=$c['price_list_id']??''?>"><?=e($c['customer_name'])?><?= $c['phone']?' — '.e($c['phone']):'' ?></option><?php endforeach; ?></select></div>
<div id="cart" class="cart"><div class="empty">Add products from the catalog.</div></div>
<input type="hidden" name="cart_json" id="cart_json">
<div class="field"><label>Discount (KES)</label><input type="number" name="discount" id="discount" min="0" step=".01" value="0"></div>
<div class="field vat-summary"><label>VAT (<?=$vatRate?>%)</label><div class="vat-value"><?=$vatRate?>% VAT</div><small class="muted"><?=e($vatMode==='inclusive'?'Included in displayed prices':'Added to selling prices')?></small><input type="hidden" name="tax" id="tax" value="0"></div>
<div class="field"><label>Payment method</label><select name="payment_method" id="payment_method"><option>Cash</option><option>M-Pesa</option><option>Bank</option><option>Credit</option><option>Mixed</option></select></div><div class="field" id="mpesaPhoneField" style="display:none"><label>M-PESA Phone</label><input name="mpesa_phone" id="mpesa_phone" placeholder="07XXXXXXXX" maxlength="15"><small class="muted">For M-PESA STK Push. The customer will receive the payment prompt.</small></div>
<div class="field"><label>Amount Paid (KES)</label><input type="number" name="paid_amount" id="paid_amount" min="0" step=".01" value="0"></div>
<div class="totals"><div class="line"><span>Subtotal</span><strong id="subtotal">KES 0.00</strong></div><div class="line"><span>Discount</span><strong id="discountView">KES 0.00</strong></div><div class="line"><span>VAT</span><strong id="taxView">KES 0.00</strong></div><div class="line grand"><span>Total</span><strong id="total">KES 0.00</strong></div><div class="line"><span>Balance due</span><strong id="balance">KES 0.00</strong></div><div class="line change-line"><span>Change to give</span><strong id="changeDue">KES 0.00</strong></div></div><button type="button" class="btn light exact-btn" onclick="setExactPayment()">Set exact amount</button>
<div class="field"><label>Notes</label><input name="notes" maxlength="500" placeholder="Optional note"></div>
<button class="btn success" type="submit"><i class="fas fa-check-circle"></i> Complete Sale &amp; Print Receipt</button>
</section>
</div>
</form>

<div class="recent"><h3>Recent Sales</h3><table class="table"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Method</th><th>Receipt</th></tr></thead><tbody><?php foreach($recent as $r): ?><tr><td><strong><?=e($r['invoice_number'])?></strong></td><td><?=e($r['sale_date'])?></td><td><?=e($r['customer_name'])?></td><td>KES <?=number_format((float)$r['total_amount'],2)?></td><td>KES <?=number_format((float)$r['paid_amount'],2)?></td><td>KES <?=number_format((float)$r['balance'],2)?></td><td><?=e($r['payment_method'])?></td><td><a class="print-link" target="_blank" href="receipt.php?id=<?=$r['id']?>"><i class="fas fa-print"></i> Print</a></td></tr><?php endforeach; ?></tbody></table></div>
</div>
</main>
<script>
const isCashierTerminal=<?=json_encode($isCashierTerminal)?>;
const vatRate=<?=json_encode($vatRate)?>,vatMode=<?=json_encode($vatMode)?>;
const products=<?=json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['product_name'],'sku'=>$p['sku'],'barcode'=>$p['barcode'],'isbn'=>$p['isbn'],'price'=>(float)$p['selling_price'],'normalPrice'=>(float)$p['selling_price'],'wholesalePrice'=>(float)$p['wholesale_price'],'stock'=>(float)$p['stock_quantity'],'unit'=>$p['unit']],$products),JSON_UNESCAPED_UNICODE)?>;
const priceTiers=<?=json_encode(array_map(fn($x)=>['list'=>(int)$x['price_list_id'],'product'=>(int)$x['product_id'],'min'=>(int)$x['min_quantity'],'price'=>(float)$x['price']],$productPrices),JSON_UNESCAPED_UNICODE)?>;
let cart=[];
function selectedPrice(p,qty=1){const listId=Number(document.getElementById('price_list_id').value||0);const tiers=priceTiers.filter(t=>t.list===listId&&t.product===p.id&&t.min<=qty).sort((a,b)=>b.min-a.min);return tiers.length?tiers[0].price:null;}
function addProduct(id){const p=products.find(x=>x.id===id);if(!p||p.stock<=0)return;const row=cart.find(x=>x.id===id);const nextQty=row?Math.min(row.qty+1,p.stock):1;if(row&&nextQty===row.qty){setScanFeedback("Available stock limit reached for "+p.name,"error");return}const price=selectedPrice(p,nextQty);if(price===null){setScanFeedback("No price is configured for "+p.name,"error");return}if(row){row.qty=nextQty;row.price=price;}else cart.push({...p,price,qty:1});setScanFeedback("Added: "+p.name+" · Qty "+nextQty,"success");render();focusScanner();}
function render(){const box=document.getElementById('cart');box.innerHTML=cart.length?cart.map((x,i)=>'<div class="cart-row"><div><strong>'+esc(x.name)+'</strong><br><small>KES '+money(x.price)+'</small></div><input type="number" min="1" max="'+x.stock+'" value="'+x.qty+'" onchange="setQty('+i+',this.value)"><span>KES '+money(x.price*x.qty)+'</span><button type="button" class="remove" onclick="removeItem('+i+')">&times;</button></div>').join(''):'<div class="empty">Add products from the catalog.</div>';calc();}
function setQty(i,v){const qty=Math.max(1,Math.min(Number(v)||1,cart[i].stock));const p=products.find(x=>x.id===cart[i].id);if(!p)return;const price=selectedPrice(p,qty);if(price===null){alert("No price is configured for this quantity in the selected price list.");render();return}cart[i].qty=qty;cart[i].price=price;render()}function removeItem(i){cart.splice(i,1);render()}
function calc(){let sub=cart.reduce((a,x)=>a+x.qty*x.price,0),d=Math.max(0,Number(document.getElementById('discount').value)||0),taxable=Math.max(0,sub-d),t=vatMode==='inclusive'?taxable*vatRate/(100+vatRate):taxable*vatRate/100,total=vatMode==='inclusive'?taxable:taxable+t,paid=Math.max(0,Number(document.getElementById('paid_amount').value)||0);document.getElementById('tax').value=t.toFixed(2);document.getElementById('subtotal').textContent='KES '+money(sub);document.getElementById('discountView').textContent='KES '+money(d);document.getElementById('taxView').textContent='KES '+money(t);document.getElementById('total').textContent='KES '+money(total);document.getElementById('balance').textContent='KES '+money(Math.max(0,total-paid));document.getElementById('changeDue').textContent='KES '+money(Math.max(0,paid-total));document.getElementById('cart_json').value=JSON.stringify(cart.map(x=>({id:x.id,qty:x.qty,price:x.price})));}
function setExactPayment(){const sub=Math.max(0,cart.reduce((a,x)=>a+x.qty*x.price,0)-Number(document.getElementById('discount').value||0)),total=vatMode==='inclusive'?sub:sub+(sub*vatRate/100);document.getElementById('paid_amount').value=Math.max(0,total).toFixed(2);calc();}
function setScanFeedback(message,type='success'){const el=document.getElementById('scanFeedback');if(el){el.textContent=message;el.style.color=type==='error'?'#b42318':'#147d5a';}}
function focusScanner(){const el=document.getElementById('productSearch');if(el){el.focus();el.select();}}
function toggleCounterMode(){document.body.classList.toggle('counter-mode');const active=document.body.classList.contains('counter-mode');const btn=document.getElementById('counterModeBtn');btn.innerHTML=active?'<i class="fas fa-compress"></i> Exit counter mode':'<i class="fas fa-expand"></i> Counter mode';if(active){if(document.documentElement.requestFullscreen)document.documentElement.requestFullscreen().catch(()=>{});}else if(document.fullscreenElement&&document.exitFullscreen){document.exitFullscreen().catch(()=>{});}focusScanner();}

function filterCategory(cat,btn){document.querySelectorAll('.cat').forEach(x=>x.classList.remove('active'));btn.classList.add('active');document.querySelectorAll('.product').forEach(x=>x.style.display=(cat==='all'||x.dataset.category===cat)?'block':'none');}
function updateCatalogPrices(){document.querySelectorAll('.product').forEach(card=>{const p=products.find(x=>x.id===Number(card.dataset.id));if(p){const price=selectedPrice(p,1);card.querySelector('.price-value').textContent=price===null?"Not set":money(price);}});cart=cart.filter(x=>{const p=products.find(y=>y.id===x.id);const price=p?selectedPrice(p,x.qty):null;if(price===null)return false;x.price=price;return true;});updatePriceTierHint();render();}
function normalizeLookupCode(value){return String(value??'').toLowerCase().replace(/[\s-]+/g,'');}
function filterProducts(){const q=normalizeLookupCode(document.getElementById('productSearch').value);document.querySelectorAll('.product').forEach(x=>x.style.display=normalizeLookupCode(x.dataset.search).includes(q)?'block':'none');}
function clearSearch(){document.getElementById('productSearch').value='';filterProducts();document.getElementById('productSearch').focus();}
function money(n){return Number(n).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2})}function esc(s){return String(s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]||m))}
document.getElementById('customer_id').addEventListener('change',()=>{const o=document.querySelector('#customer_id option:checked');const pl=o?.dataset.priceList||'';if(pl&&document.querySelector('#price_list_id option[value="'+pl+'"]')){document.getElementById('price_list_id').value=pl;updateCatalogPrices();}});document.getElementById('productSearch').addEventListener('input',filterProducts);document.getElementById('productSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();const q=normalizeLookupCode(e.target.value);const p=products.find(x=>x.barcode&&normalizeLookupCode(x.barcode)===q)||products.find(x=>x.isbn&&normalizeLookupCode(x.isbn)===q)||products.find(x=>x.sku&&normalizeLookupCode(x.sku)===q);if(p){addProduct(p.id);clearSearch();}else{const visible=[...document.querySelectorAll('.product')].filter(x=>x.style.display!=='none'&&!x.classList.contains('out'));if(visible.length===1){addProduct(Number(visible[0].dataset.id));clearSearch();}else{setScanFeedback('No exact barcode, ISBN or SKU match. Check the code or search by name.','error');}}}});document.getElementById('price_list_id').addEventListener('change',updateCatalogPrices);document.getElementById('discount').addEventListener('input',calc);document.getElementById('paid_amount').addEventListener('input',calc);document.getElementById('payment_method').addEventListener('change',()=>{const m=document.getElementById('payment_method').value;document.getElementById('mpesaPhoneField').style.display=m==='M-Pesa'?'block':'none';if(m==='Credit'||m==='M-Pesa')document.getElementById('paid_amount').value=0;calc()});
document.getElementById('saleForm').addEventListener('submit',e=>{if(!cart.length){e.preventDefault();alert('Add at least one product.');return}let sub=Math.max(0,cart.reduce((a,x)=>a+x.qty*x.price,0)-Number(document.getElementById('discount').value||0)),total=vatMode==='inclusive'?sub:sub+(sub*vatRate/100),paid=Number(document.getElementById('paid_amount').value||0),method=document.getElementById('payment_method').value;if(paid>total&&method!=='Cash'){e.preventDefault();alert('Amount paid cannot exceed the sale total for '+method+'.');return}if(method==='Credit'&&!document.getElementById('customer_id').value){e.preventDefault();alert('Select a customer for Credit sales.');return}if(method==='M-Pesa'&&!document.getElementById('mpesa_phone').value.trim()){e.preventDefault();alert('Enter the customer M-PESA phone number.');return}});render();
if(isCashierTerminal){document.body.classList.add('counter-mode');const terminalButton=document.getElementById('counterModeBtn');if(terminalButton)terminalButton.innerHTML='<i class="fas fa-compress"></i> Exit counter mode';}
function updatePriceTierHint(){const hint=document.getElementById('priceTierHint');if(!hint)return;const select=document.getElementById('price_list_id');const code=select.options[select.selectedIndex]?.dataset.code||'';const label=select.options[select.selectedIndex]?.text||'Price list';const active=priceTiers.some(t=>t.list===Number(select.value));document.getElementById('priceTierHint').textContent=(code==='normal'?'Standard price':code==='wholesale'?'Wholesale pricing':label)+(active?' • Quantity tiers active':' • Configure product prices');}
document.getElementById('price_list_id').addEventListener('change',updateCatalogPrices);
updatePriceTierHint();
document.addEventListener('keydown',e=>{const tag=(e.target&&e.target.tagName||'').toLowerCase();const typing=['input','textarea','select'].includes(tag);if(e.key==='F2'){e.preventDefault();focusScanner();return}if(e.key==='F4'){e.preventDefault();const paid=document.getElementById('paid_amount');paid.focus();paid.select();return}if(e.key==='Escape'&&document.activeElement===document.getElementById('productSearch')){clearSearch();return}if(e.ctrlKey&&e.key==='Enter'){e.preventDefault();document.getElementById('saleForm').requestSubmit();return}if(!typing&&e.key==='/'&&!e.ctrlKey&&!e.altKey){e.preventDefault();focusScanner();}});
</script>
<?php require_once __DIR__.'/../../includes/footer.php'; ?>