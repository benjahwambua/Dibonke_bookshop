<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
requireModuleAccess('customer_orders');
$customers=$conn->query("SELECT id,customer_name,phone,price_list_id FROM customers WHERE status='Active' ORDER BY customer_name")->fetchAll();
$products=$conn->query("SELECT id,product_name,unit,selling_price,wholesale_price,purchase_unit,units_per_purchase,bulk_selling_price,stock_quantity FROM products WHERE status='Active' ORDER BY product_name")->fetchAll();
$lists=$conn->query("SELECT id,name,code FROM price_lists WHERE status='Active' ORDER BY is_default DESC,name")->fetchAll();
$orders=$conn->query("SELECT o.*,c.customer_name,c.phone,u.full_name creator FROM customer_sales_orders o JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.created_by ORDER BY o.id DESC LIMIT 100")->fetchAll();
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
$flash=$_SESSION['customer_order_flash']??'';unset($_SESSION['customer_order_flash']);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.co-wrap{max-width:1450px;margin:auto}.co-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.co-panel{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:18px;margin:15px 0}.co-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.co-field label{display:block;font-size:12px;font-weight:750;margin-bottom:5px;color:#475569}.co-field input,.co-field select,.co-field textarea{width:100%;box-sizing:border-box;padding:10px;border:1px solid #cbd5e1;border-radius:7px}.co-table{width:100%;border-collapse:collapse}.co-table th,.co-table td{padding:9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.co-table th{background:#f5f7fa}.co-btn{border:0;border-radius:7px;padding:10px 14px;background:#174a7e;color:#fff;font-weight:750;cursor:pointer;text-decoration:none;display:inline-block}.co-btn.alt{background:#eaf0f6;color:#17324d}.co-actions{display:flex;gap:7px;flex-wrap:wrap}.co-status{font-weight:800;font-size:11px}.co-muted{color:#64748b;font-size:12px}@media(max-width:700px){.co-grid{grid-template-columns:1fr}}
</style>
<div class="co-wrap">
<div class="co-head"><div><h1>Customer Sales Orders</h1><p class="co-muted">Prepare orders for registered customers. Validate them before they move to Pending Invoices.</p></div><a class="co-btn alt" href="<?=BASE_URL?>/modules/billing/index.php">Confirmed invoices</a></div>
<?php if($flash):?><div class="co-panel"><?=e($flash)?></div><?php endif;?>
<?php if(hasModulePermission('customer_orders','create')):?>
<form class="co-panel" method="post" action="store.php" id="orderForm">
<input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<h3 style="margin-top:0">Create customer order</h3>
<div class="co-grid">
<div class="co-field"><label>Customer</label><select name="customer_id" id="customerId" required><option value="">Select existing customer</option><?php foreach($customers as $c):?><option value="<?=$c['id']?>" data-list="<?= (int)$c['price_list_id']?>"><?=e($c['customer_name'])?><?= $c['phone']?' — '.e($c['phone']):''?></option><?php endforeach;?></select></div>
<div class="co-field"><label>Price list</label><select name="price_list_id" id="priceList" required><?php foreach($lists as $l):?><option value="<?=$l['id']?>" data-code="<?=e($l['code'])?>"><?=e($l['name'])?></option><?php endforeach;?></select></div>
</div>
<div style="overflow:auto;margin-top:14px"><table class="co-table" id="orderLines"><thead><tr><th style="min-width:230px">Product</th><th>Unit</th><th>Quantity</th><th>Unit price (KES)</th><th>Total</th><th></th></tr></thead><tbody id="lineRows"></tbody></table></div>
<div class="co-actions" style="margin-top:12px"><button type="button" class="co-btn alt" onclick="addLine()">+ Add product</button><strong style="margin-left:auto" id="orderTotal"><?=$currency?> 0.00</strong></div>
<div class="co-field" style="margin-top:12px"><label>Order notes (optional)</label><textarea name="notes" rows="2" maxlength="1000"></textarea></div>
<p class="co-muted">Creating an order does not deduct stock or create an invoice. Validation checks stock; confirmation creates the invoice and posts the sale.</p>
<button class="co-btn" type="submit">Save order</button>
</form>
<?php endif;?>
<section class="co-panel"><h3 style="margin-top:0">Orders</h3><div style="overflow:auto"><table class="co-table"><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($orders as $o):?><tr><td><strong><?=e($o['order_number'])?></strong></td><td><?=e($o['order_date'])?></td><td><?=e($o['customer_name'])?></td><td><?=e($currency)?> <?=number_format((float)$o['total_amount'],2)?></td><td class="co-status"><?=e($o['status'])?></td><td><div class="co-actions"><?php if($o['status']==='Draft'&&hasModulePermission('customer_orders','edit')):?><a class="co-btn" href="view.php?id=<?=(int)$o['id']?>">Review &amp; Validate</a><?php elseif($o['status']==='Validated'):?><a class="co-btn" href="view.php?id=<?=(int)$o['id']?>">View order</a><?php elseif($o['status']==='Invoiced'&&$o['sale_id']):?><a class="co-btn alt" target="_blank" href="invoice.php?id=<?=$o['id']?>">Print invoice</a><?php endif;?></div></td></tr><?php endforeach;?>
<?php if(!$orders):?><tr><td colspan="6" class="co-muted">No customer orders yet.</td></tr><?php endif;?>
</tbody></table></div></section>
</div>
<script>
const products=<?=json_encode(array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['product_name'],'unit'=>$p['unit'],'price'=>(float)$p['selling_price'],'wholesale'=>(float)$p['wholesale_price'],'bulkUnit'=>$p['purchase_unit'],'factor'=>max(1,(int)$p['units_per_purchase']),'bulkPrice'=>$p['bulk_selling_price']===null?null:(float)$p['bulk_selling_price'],'stock'=>(int)$p['stock_quantity']],$products),JSON_UNESCAPED_UNICODE)?>;
const tiers=<?=json_encode($conn->query("SELECT price_list_id,product_id,min_quantity,price FROM product_prices ORDER BY min_quantity DESC")->fetchAll(PDO::FETCH_ASSOC))?>;
const money=n=>Number(n).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
function price(p,unit){const list=Number(document.getElementById('priceList').value);const tier=tiers.find(t=>Number(t.price_list_id)===list&&Number(t.product_id)===p.id&&Number(t.min_quantity)<=1);let base=tier?Number(tier.price):p.price;if(unit==='bulk')return p.bulkPrice>0?p.bulkPrice:base*p.factor;return base;}
function addLine(){const body=document.getElementById('lineRows'),tr=document.createElement('tr');tr.innerHTML='<td><select name="product_id[]" required style="width:100%;padding:8px"><option value="">Choose product</option>'+products.map(p=>'<option value="'+p.id+'">'+String(p.name).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))+'</option>').join('')+'</select></td><td><select name="unit_mode[]" onchange="lineChanged(this)" style="padding:8px"><option value="base">Stock unit</option><option value="bulk">Bulk unit</option></select></td><td><input name="quantity[]" type="number" min="1" step="1" value="1" required style="width:80px;padding:8px"></td><td><input name="unit_price[]" type="number" min="0" step=".01" required style="width:110px;padding:8px"></td><td class="lineTotal">0.00</td><td><button type="button" onclick="this.closest(\'tr\').remove();total()" aria-label="Remove">×</button></td>';body.appendChild(tr);tr.querySelector('select[name="product_id[]"]').addEventListener('change',()=>fillLine(tr));tr.querySelectorAll('input').forEach(x=>x.addEventListener('input',total));}
function fillLine(tr){const p=products.find(x=>x.id===Number(tr.querySelector('[name="product_id[]"]').value));if(!p)return;const unit=tr.querySelector('[name="unit_mode[]"]');unit.options[1].disabled=p.factor<=1;tr.querySelector('[name="unit_price[]"]').value=price(p,unit.value).toFixed(2);tr.querySelector('.lineTotal').textContent=money(Number(tr.querySelector('[name="quantity[]"]').value)*Number(tr.querySelector('[name="unit_price[]"]').value));total();}
function lineChanged(el){const tr=el.closest('tr');fillLine(tr);}
function total(){let n=0;document.querySelectorAll('#lineRows tr').forEach(tr=>{const q=Number(tr.querySelector('[name="quantity[]"]').value)||0,p=Number(tr.querySelector('[name="unit_price[]"]').value)||0;n+=q*p;tr.querySelector('.lineTotal').textContent=money(q*p)});document.getElementById('orderTotal').textContent='<?=$currency?> '+money(n);}
document.getElementById('customerId').addEventListener('change',e=>{const id=e.target.selectedOptions[0]?.dataset.list;if(id&&document.querySelector('#priceList option[value="'+id+'"]'))document.getElementById('priceList').value=id;});
document.getElementById('priceList').addEventListener('change',()=>document.querySelectorAll('#lineRows tr').forEach(fillLine));
document.getElementById('lineRows').addEventListener('input',total);addLine();
</script>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>
