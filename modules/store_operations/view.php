<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireModuleAccess('store_operations');

$id=(int)($_GET['id']??0);
$stmt=$conn->prepare("
    SELECT ds.*,s.store_name,st.transfer_number issue_number,rt.transfer_number return_number,
           u.full_name reconciled_by_name
    FROM store_daily_sessions ds
    INNER JOIN stores s ON s.id=ds.store_id
    LEFT JOIN store_transfers st ON st.id=ds.issue_transfer_id
    LEFT JOIN store_transfers rt ON rt.id=ds.return_transfer_id
    LEFT JOIN users u ON u.id=ds.reconciled_by
    WHERE ds.id=? LIMIT 1
");
$stmt->execute([$id]);
$session=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$session){http_response_code(404);exit('Daily store record not found.');}
$itemsStmt=$conn->prepare("
    SELECT di.*,p.product_name,p.sku,p.unit
    FROM store_daily_items di
    INNER JOIN products p ON p.id=di.product_id
    WHERE di.session_id=? ORDER BY p.product_name
");
$itemsStmt->execute([$id]);
$items=$itemsStmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Daily Store Record';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
<style>
.store-view-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.store-view-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px;margin:14px 0}
.store-view-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.store-view-grid div{padding:12px;border:1px solid #edf1f5;border-radius:8px}
.store-view-grid small{display:block;color:#64748b;font-size:11px}.store-view-grid strong{display:block;margin-top:5px;color:#17324d;font-size:16px}
.store-view-table{width:100%;border-collapse:collapse;min-width:720px}.store-view-table th{background:#f4f7fa;text-align:left;color:#526477;font-size:11px;padding:11px}.store-view-table td{padding:11px;border-top:1px solid #edf1f5;font-size:12px}
.store-view-wrap{overflow:auto}
@media(max-width:800px){.store-view-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.store-view-grid{grid-template-columns:1fr}}
</style>
<div class="store-view-head">
    <div><h1>Daily Store Record</h1><p><?=e($session['store_name'])?> · <?=e(date('d M Y',strtotime($session['business_date'])))?> · In charge: <?=e($session['responsible_person'])?></p></div>
    <a class="btn btn-light" href="index.php"><i class="fas fa-arrow-left"></i> Back to Store Operations</a>
</div>
<div class="store-view-panel">
    <div class="store-view-grid">
        <div><small>Stock issue reference</small><strong><?=e($session['issue_number']?:'—')?></strong></div>
        <div><small>Return reference</small><strong><?=e($session['return_number']?:'No returns recorded')?></strong></div>
        <div><small>Status</small><strong><?=e($session['status'])?></strong></div>
        <div><small>Reconciled by</small><strong><?=e($session['reconciled_by_name']?:'Pending')?></strong></div>
    </div>
</div>
<div class="store-view-panel">
    <h2 style="font-size:16px;margin:0 0 12px">Stock Reconciliation</h2>
    <div class="store-view-wrap"><table class="store-view-table">
        <thead><tr><th>Product</th><th>Issued</th><th>Returned</th><th>POS Sold</th><th>Stock Difference</th><th>Expected Sales Value</th></tr></thead>
        <tbody>
        <?php foreach($items as $item): ?><tr>
            <td><strong><?=e($item['product_name'])?></strong><br><small><?=e($item['sku']?:'No SKU')?></small></td>
            <td><?=number_format((float)$item['quantity_issued'],3)?> <?=e($item['unit']?:'units')?></td>
            <td><?=number_format((float)$item['quantity_returned'],3)?></td>
            <td><?=number_format((float)$item['quantity_sold'],3)?></td>
            <td><?=number_format((float)$item['quantity_variance'],3)?></td>
            <td>KES <?=number_format((float)$item['expected_amount'],2)?></td>
        </tr><?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<div class="store-view-panel">
    <h2 style="font-size:16px;margin:0 0 12px">Money Reconciliation</h2>
    <div class="store-view-grid">
        <div><small>Expected collections</small><strong>KES <?=number_format((float)$session['expected_sales'],2)?></strong></div>
        <div><small>Cash received</small><strong>KES <?=number_format((float)$session['cash_received'],2)?></strong></div>
        <div><small>M-Pesa received</small><strong>KES <?=number_format((float)$session['mpesa_received'],2)?></strong></div>
        <div><small>Bank received</small><strong>KES <?=number_format((float)$session['bank_received'],2)?></strong></div>
        <div><small>Other received</small><strong>KES <?=number_format((float)$session['other_received'],2)?></strong></div>
        <div><small>Total received</small><strong>KES <?=number_format((float)$session['total_received'],2)?></strong></div>
        <div><small>Money variance</small><strong>KES <?=number_format((float)$session['money_variance'],2)?></strong></div>
    </div>
    <?php if(!empty($session['notes'])): ?><p><strong>Notes:</strong> <?=nl2br(e($session['notes']))?></p><?php endif; ?>
</div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
