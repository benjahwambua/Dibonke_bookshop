<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/audit.php';
require_once __DIR__.'/../../includes/report_tools.php';
requireModuleAccess('products');
$ready=(bool)$conn->query("SHOW TABLES LIKE 'inventory_valuation_snapshots'")->fetchColumn()&&(bool)$conn->query("SHOW TABLES LIKE 'inventory_valuation_snapshot_items'")->fetchColumn();
$flash=$_SESSION['valuation_flash']??'';unset($_SESSION['valuation_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();requireModulePermission('products','edit');
    try{
        if(!$ready)throw new RuntimeException('Import database/dibonke_inventory_valuation_snapshots.sql first.');
        $notes=trim((string)($_POST['notes']??''));if(strlen($notes)>255)throw new RuntimeException('Notes must not exceed 255 characters.');
        $conn->beginTransaction();
        $products=$conn->query("SELECT id,product_name,stock_quantity,buying_price FROM products WHERE status='Active' ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
        $hasStores=(bool)$conn->query("SHOW TABLES LIKE 'stores'")->fetchColumn()&&(bool)$conn->query("SHOW TABLES LIKE 'store_stock'")->fetchColumn();
        $mainStoreId=0;if($hasStores)$mainStoreId=(int)$conn->query("SELECT id FROM stores WHERE store_type='Main' AND status='Active' ORDER BY id LIMIT 1")->fetchColumn();
        $insertHeader=$conn->prepare("INSERT INTO inventory_valuation_snapshots(snapshot_date,total_quantity,total_value,notes,created_by,created_at) VALUES(CURDATE(),0,0,?,?,NOW())");
        $insertHeader->execute([$notes?:null,(int)$_SESSION['user_id']]);$snapshotId=(int)$conn->lastInsertId();
        $insertItem=$conn->prepare("INSERT INTO inventory_valuation_snapshot_items(snapshot_id,product_id,store_id,quantity,unit_cost,total_cost) VALUES(?,?,?,?,?,?)");
        $totalQuantity=0.0;$totalValue=0.0;
        foreach($products as $product){
            $qty=(float)$product['stock_quantity'];$cost=(float)$product['buying_price'];
            if(abs($qty)>0.0001){$value=round($qty*$cost,2);$insertItem->execute([$snapshotId,(int)$product['id'],$mainStoreId>0?$mainStoreId:null,$qty,$cost,$value]);$totalQuantity+=$qty;$totalValue+=$value;}
        }
        if($hasStores){
            $storeRows=$conn->query("SELECT ss.store_id,ss.product_id,ss.quantity,p.buying_price FROM store_stock ss JOIN products p ON p.id=ss.product_id JOIN stores st ON st.id=ss.store_id WHERE st.status='Active' AND st.store_type<>'Main' AND ABS(ss.quantity)>0.0001 ORDER BY ss.store_id,ss.product_id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            foreach($storeRows as $row){$qty=(float)$row['quantity'];$cost=(float)$row['buying_price'];$value=round($qty*$cost,2);$insertItem->execute([$snapshotId,(int)$row['product_id'],(int)$row['store_id'],$qty,$cost,$value]);$totalQuantity+=$qty;$totalValue+=$value;}
        }
        $conn->prepare("UPDATE inventory_valuation_snapshots SET total_quantity=?,total_value=? WHERE id=?")->execute([round($totalQuantity,3),round($totalValue,2),$snapshotId]);
        recordAuditEvent($conn,'Create Snapshot','inventory','Created inventory valuation snapshot #'.$snapshotId.' worth KES '.number_format($totalValue,2),'inventory_valuation_snapshot',$snapshotId);
        $conn->commit();$flash='Inventory valuation snapshot #'.$snapshotId.' saved. Total value: KES '.number_format($totalValue,2).'.';
    }catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$flash='Snapshot could not be created: '.$e->getMessage();}
    $_SESSION['valuation_flash']=$flash;header('Location:valuation.php');exit;
}
$snapshots=$ready?$conn->query("SELECT v.*,u.full_name created_by_name FROM inventory_valuation_snapshots v LEFT JOIN users u ON u.id=v.created_by ORDER BY v.id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC):[];
$selectedId=(int)($_GET['id']??0);$selected=null;$items=[];
if($ready&&$selectedId>0){$q=$conn->prepare("SELECT * FROM inventory_valuation_snapshots WHERE id=?");$q->execute([$selectedId]);$selected=$q->fetch(PDO::FETCH_ASSOC)?:null;if($selected){$q=$conn->prepare("SELECT i.*,p.product_name,p.sku,p.unit,s.store_name FROM inventory_valuation_snapshot_items i JOIN products p ON p.id=i.product_id LEFT JOIN stores s ON s.id=i.store_id WHERE i.snapshot_id=? ORDER BY s.store_name,p.product_name");$q->execute([$selectedId]);$items=$q->fetchAll(PDO::FETCH_ASSOC);}}
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
$exportRows=[];foreach($items as $item)$exportRows[]=[$item['store_name']?:'Main / Default',$item['product_name'],$item['sku'],$item['quantity'],$item['unit'],$item['unit_cost'],$item['total_cost']];if($selected)dibonkeReportExport('inventory_valuation_snapshot_'.$selectedId,['Store','Product','SKU','Quantity','Unit','Weighted Average Cost','Total Value'],$exportRows);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><?php dibonkeReportPrintStyles(); ?>
<style>
.iv-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px;margin:14px 0}.iv-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.iv-form label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:800}.iv-form input{padding:10px;border:1px solid #cbd5e1;border-radius:7px;min-width:260px}.iv-btn{background:#174a7e;color:#fff;border:0;border-radius:7px;padding:10px 14px;font-weight:800}.iv-table{width:100%;border-collapse:collapse}.iv-table th,.iv-table td{padding:9px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.iv-table th{background:#f3f6fa}.num{text-align:right!important;white-space:nowrap}.muted{color:#64748b}
</style>
<h1>Inventory Valuation Snapshots</h1><p class="muted">Save dated, store-aware snapshots using each product's current weighted-average buying price. These snapshots preserve the valuation at the time they are created.</p>
<nav style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0"><a href="index.php">Inventory Overview</a><a href="valuation.php">Valuation Snapshots</a><a href="<?=BASE_URL?>/modules/stock_movements/index.php">Stock Movements</a></nav>
<?php if($flash):?><div class="iv-panel"><?=e($flash)?></div><?php endif;?>
<?php if(!$ready):?><section class="iv-panel"><p>Import <code>database/dibonke_inventory_valuation_snapshots.sql</code> to enable valuation history.</p></section><?php else:?>
<?php if(hasModulePermission('products','edit')):?><section class="iv-panel"><h2>Create valuation snapshot</h2><p class="muted">The snapshot includes Main Store stock and all active selling-store stock.</p><form method="post" class="iv-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>Notes<input name="notes" maxlength="255" placeholder="Optional month-end or stocktake reference"></label><button class="iv-btn">Save snapshot</button></form></section><?php endif;?>
<?php if($selected):?><section class="iv-panel"><h2>Snapshot #<?=(int)$selected['id']?> · <?=e($selected['snapshot_date'])?></h2><p><strong>Total quantity:</strong> <?=number_format((float)$selected['total_quantity'],3)?> &nbsp; <strong>Total valuation:</strong> <?=e($currency)?> <?=number_format((float)$selected['total_value'],2)?></p><p class="muted"><?=e($selected['notes']?:'No notes')?></p><?php dibonkeReportActions('inventory_valuation'); ?><div style="overflow:auto"><table class="iv-table"><thead><tr><th>Store</th><th>Product</th><th>SKU</th><th class="num">Quantity</th><th>Unit</th><th class="num">Weighted average cost</th><th class="num">Total value</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><?=e($item['store_name']?:'Main / Default')?></td><td><?=e($item['product_name'])?></td><td><?=e($item['sku']?:'—')?></td><td class="num"><?=number_format((float)$item['quantity'],3)?></td><td><?=e($item['unit']?:'')?></td><td class="num"><?=number_format((float)$item['unit_cost'],4)?></td><td class="num"><?=number_format((float)$item['total_cost'],2)?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<section class="iv-panel"><h2>Snapshot history</h2><div style="overflow:auto"><table class="iv-table"><thead><tr><th>Snapshot</th><th>Date</th><th>Quantity</th><th>Total value</th><th>Created by</th><th>Notes</th><th></th></tr></thead><tbody><?php foreach($snapshots as $snapshot):?><tr><td>#<?=(int)$snapshot['id']?></td><td><?=e($snapshot['snapshot_date'])?></td><td><?=number_format((float)$snapshot['total_quantity'],3)?></td><td><?=e($currency)?> <?=number_format((float)$snapshot['total_value'],2)?></td><td><?=e($snapshot['created_by_name']?:'System')?></td><td><?=e($snapshot['notes']?:'—')?></td><td><a href="valuation.php?id=<?=(int)$snapshot['id']?>">View</a></td></tr><?php endforeach;?><?php if(!$snapshots):?><tr><td colspan="7" class="muted">No valuation snapshots saved yet.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>
