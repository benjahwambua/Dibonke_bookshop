<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireModuleAccess('store_operations');

$pageTitle = 'Store Operations';
$flash = $_SESSION['store_operations_flash'] ?? null;
unset($_SESSION['store_operations_flash']);

$stores = $conn->query("SELECT id,store_code,store_name,store_type,status FROM stores ORDER BY store_type='Main' DESC,store_name")->fetchAll(PDO::FETCH_ASSOC);
$sellingStores = array_values(array_filter($stores, static fn($s) => $s['store_type'] === 'Selling' && $s['status'] === 'Active'));
$products = $conn->query("SELECT id,product_name,sku,stock_quantity,unit,selling_price FROM products WHERE status='Active' AND stock_quantity>0 ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);
$sessions = $conn->query("
    SELECT ds.*,s.store_name,st.transfer_number issue_number,rt.transfer_number return_number,
           (SELECT COUNT(*) FROM store_daily_items di WHERE di.session_id=ds.id AND di.quantity_variance<>0) stock_variance_items
    FROM store_daily_sessions ds
    INNER JOIN stores s ON s.id=ds.store_id
    LEFT JOIN store_transfers st ON st.id=ds.issue_transfer_id
    LEFT JOIN store_transfers rt ON rt.id=ds.return_transfer_id
    ORDER BY ds.business_date DESC,ds.id DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$reconcileId = (int)($_GET['reconcile'] ?? 0);
$reconcileSession = null;
$reconcileItems = [];
if ($reconcileId > 0) {
    $q = $conn->prepare("SELECT ds.*,s.store_name FROM store_daily_sessions ds INNER JOIN stores s ON s.id=ds.store_id WHERE ds.id=? AND ds.status='Open'");
    $q->execute([$reconcileId]);
    $reconcileSession = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($reconcileSession) {
        $q = $conn->prepare("SELECT di.*,p.product_name,p.sku,p.unit FROM store_daily_items di INNER JOIN products p ON p.id=di.product_id WHERE di.session_id=? ORDER BY p.product_name");
        $q->execute([$reconcileId]);
        $reconcileItems = $q->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
<style>
.store-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.store-head h1{margin:0 0 5px}.store-head p{margin:0;color:#64748b;font-size:13px}
.store-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:16px 0}
.store-card,.store-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:16px}
.store-card small{display:block;color:#64748b;font-weight:700;font-size:11px}.store-card strong{display:block;color:#17324d;font-size:19px;margin-top:7px}
.store-panel{margin:16px 0}.store-panel h2{font-size:16px;margin:0 0 12px;color:#17324d}
.store-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.store-field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px;color:#334155}
.store-field input,.store-field select,.store-field textarea{width:100%;box-sizing:border-box;min-height:39px;border:1px solid #d7e0e9;border-radius:7px;padding:9px 10px;font:inherit;font-size:13px}
.store-field textarea{min-height:70px}
.store-table-wrap{overflow:auto}.store-table{width:100%;border-collapse:collapse;min-width:680px}
.store-table th{background:#f4f7fa;color:#526477;text-align:left;font-size:11px;padding:11px}
.store-table td{padding:10px 11px;border-top:1px solid #edf1f5;font-size:12px}
.store-table input{width:100px;min-height:34px;padding:6px 8px;border:1px solid #d7e0e9;border-radius:6px}\n.store-search{width:100%;max-width:440px;min-height:42px;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;font:inherit;font-size:13px;margin:4px 0 12px}\n.store-no-match td{color:#64748b;text-align:center;padding:18px}
.store-btn{display:inline-flex;align-items:center;gap:7px;background:#174a7e;color:#fff;border:0;border-radius:7px;padding:10px 14px;text-decoration:none;font-size:12px;font-weight:700;cursor:pointer}
.store-btn.secondary{background:#eef2f7;color:#334155}
.store-flash{padding:12px 14px;border-radius:8px;background:#eff6ff;color:#1e40af;margin:12px 0;font-size:13px}
.store-muted{font-size:12px;color:#64748b}
.store-status{font-size:10px;font-weight:800;border-radius:20px;padding:4px 8px;background:#fef3c7;color:#92400e}
.store-status.done{background:#dcfce7;color:#166534}
@media(max-width:850px){.store-grid{grid-template-columns:1fr 1fr}}
@media(max-width:600px){.store-grid,.store-form-grid{grid-template-columns:1fr}}
</style>

<div class="store-head">
    <div><h1>Store Operations</h1><p>Issue stock from Main Store each morning and reconcile returns and collections at closing.</p></div>
</div>
<?php if ($flash): ?><div class="store-flash"><?= e($flash) ?></div><?php endif; ?>

<div class="store-grid">
    <div class="store-card"><small>Stores configured</small><strong><?= count($stores) ?></strong></div>
    <div class="store-card"><small>Active selling points</small><strong><?= count($sellingStores) ?></strong></div>
    <div class="store-card"><small>Open daily records</small><strong><?= count(array_filter($sessions, static fn($s) => $s['status'] === 'Open')) ?></strong></div>
</div>

<section class="store-panel">
    <h2>Manage Stores</h2>
    <p class="store-muted">Rename stores at any time. Store codes and historical records stay unchanged. Deactivated stores are kept for reporting and can be reactivated.</p>
    <div class="store-table-wrap"><table class="store-table">
        <thead><tr><th>Store name</th><th>Code</th><th>Type</th><th>Status</th><th>Management</th></tr></thead>
        <tbody><?php foreach ($stores as $store): ?><tr>
            <td>
                <?php if (hasModulePermission('store_operations','edit')): ?>
                <form method="post" action="store.php" style="display:flex;gap:6px;align-items:center;min-width:220px">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="rename_store"><input type="hidden" name="store_id" value="<?= (int)$store['id'] ?>">
                    <input type="text" name="store_name" value="<?= e($store['store_name']) ?>" required maxlength="120" aria-label="Store name" style="width:100%;min-width:130px">
                    <button class="store-btn secondary" type="submit" title="Save store name"><i class="fas fa-save"></i> Save</button>
                </form>
                <?php else: ?><strong><?= e($store['store_name']) ?></strong><?php endif; ?>
            </td>
            <td><?= e($store['store_code']) ?></td><td><?= e($store['store_type']) ?></td><td><?= e($store['status']) ?></td>
            <td>
                <?php if (hasModulePermission('store_operations','edit') && $store['store_type'] !== 'Main'): ?>
                <form method="post" action="store.php" onsubmit="return confirm('<?= $store['status']==='Active' ? 'Deactivate' : 'Reactivate' ?> this store?')" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_store_status"><input type="hidden" name="store_id" value="<?= (int)$store['id'] ?>">
                    <button class="store-btn <?= $store['status']==='Active' ? 'secondary' : '' ?>" type="submit"><?= $store['status']==='Active' ? 'Deactivate' : 'Reactivate' ?></button>
                </form>
                <?php elseif ($store['store_type'] === 'Main'): ?><span class="store-muted">Central stock location</span><?php endif; ?>
            </td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php if (hasModulePermission('store_operations','create')): ?>
    <form method="post" action="store.php" class="store-form-grid" style="margin-top:16px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_store">
        <div class="store-field"><label for="store_name">New selling store name</label><input id="store_name" name="store_name" placeholder="e.g. Store 1" required maxlength="120"></div>
        <div class="store-field"><label for="store_code">Store code</label><input id="store_code" name="store_code" placeholder="e.g. STORE1" required maxlength="30"></div>
        <div><button class="store-btn" type="submit"><i class="fas fa-plus"></i> Add Store</button></div>
    </form>
    <?php endif; ?>
</section>

<?php if (!$reconcileSession && hasModulePermission('store_operations','create')): ?>
<section class="store-panel">
    <h2>Morning Stock Issue</h2>
    <p class="store-muted">Select a selling store, record who is taking charge, then enter the quantities being issued. Main Store stock is reduced automatically.</p>
    <?php if (!$sellingStores): ?>
        <p class="store-muted">Create your selling stores above before issuing stock.</p>
    <?php else: ?>
    <form method="post" action="store.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="issue_stock">
        <div class="store-form-grid" style="margin-bottom:14px">
            <div class="store-field"><label for="issue_store">Selling store</label><select id="issue_store" name="store_id" required><option value="">Select store</option><?php foreach ($sellingStores as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['store_name']) ?></option><?php endforeach; ?></select></div>
            <div class="store-field"><label for="responsible_person">Person taking charge today</label><input id="responsible_person" name="responsible_person" required maxlength="160" placeholder="Enter name"></div>
        </div>
        <label class="store-muted" for="issue_product_search">Search inventory by product name or SKU</label><br>
        <input class="store-search" type="search" id="issue_product_search" placeholder="Start typing a product name or SKU..." autocomplete="off">
        <div class="store-muted" id="issue_product_count" style="margin:0 0 8px"><?= count($products) ?> stocked product(s) available in Main Store</div>
        <div class="store-table-wrap"><table class="store-table" id="issue_products_table">
            <thead><tr><th>Product</th><th>SKU</th><th>Main Store available</th><th>Quantity to issue</th></tr></thead>
            <tbody><?php foreach ($products as $p): ?><tr class="issue-product-row" data-search="<?= e(mb_strtolower($p['product_name'].' '.($p['sku'] ?? ''))) ?>">
                <td><?= e($p['product_name']) ?></td><td><?= e($p['sku'] ?: '—') ?></td><td><?= number_format((float)$p['stock_quantity'],3) ?> <?= e($p['unit'] ?: 'units') ?></td>
                <td><input type="number" name="quantity[<?= (int)$p['id'] ?>]" min="0" max="<?= e((string)$p['stock_quantity']) ?>" step="0.001" value="0" aria-label="Quantity to issue for <?= e($p['product_name']) ?>"></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <p id="issue_no_products" class="store-muted" hidden>No matching products found in Main Store inventory.</p>
        <p style="margin:14px 0 0"><button class="store-btn" type="submit"><i class="fas fa-share"></i> Issue Stock &amp; Open Daily Record</button></p>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($reconcileSession): ?>
<section class="store-panel">
    <h2>End-of-Day Reconciliation — <?= e($reconcileSession['store_name']) ?></h2>
    <p class="store-muted"><?= e(date('d M Y',strtotime($reconcileSession['business_date']))) ?> · Person in charge: <?= e($reconcileSession['responsible_person']) ?></p>
    <form method="post" action="store.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reconcile_day"><input type="hidden" name="session_id" value="<?= (int)$reconcileSession['id'] ?>">
        <div class="store-table-wrap"><table class="store-table">
            <thead><tr><th>Product</th><th>Issued</th><th>Returned to Main Store</th></tr></thead>
            <tbody><?php foreach ($reconcileItems as $item): ?><tr>
                <td><?= e($item['product_name']) ?></td><td><?= number_format((float)$item['quantity_issued'],3) ?> <?= e($item['unit'] ?: 'units') ?></td>
                <td><input type="number" name="returned[<?= (int)$item['product_id'] ?>]" min="0" max="<?= e((string)$item['quantity_issued']) ?>" step="0.001" value="0" required></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <h3 style="font-size:14px;margin:18px 0 10px">Money handed over</h3>
        <div class="store-form-grid">
            <div class="store-field"><label>Cash physically received (KES)</label><input type="number" name="cash_received" min="0" step="0.01" value="0" required></div>
            <div class="store-field"><label>M-Pesa actually received (KES)</label><input type="number" name="mpesa_received" min="0" step="0.01" value="0" required></div>
            <div class="store-field"><label>Bank (KES)</label><input type="number" name="bank_received" min="0" step="0.01" value="0" required></div>
            <div class="store-field"><label>Other (KES)</label><input type="number" name="other_received" min="0" step="0.01" value="0" required></div>
            <div class="store-field" style="grid-column:1/-1"><label>Notes / explanation</label><textarea name="notes" maxlength="5000"></textarea></div>
        </div>
        <p class="store-muted">Enter the actual cash and M-Pesa handed over, then count and enter every unsold item returned to Main Store. The system compares cash and M-Pesa separately against completed POS sales for this store and date. Any difference is shown in the daily record.</p>
        <p><button class="store-btn" type="submit"><i class="fas fa-check"></i> Complete Reconciliation</button> <a class="store-btn secondary" href="index.php">Cancel</a></p>
    </form>
</section>
<?php endif; ?>

<section class="store-panel">
    <h2>Daily Store Records</h2>
    <div class="store-table-wrap"><table class="store-table">
        <thead><tr><th>Date</th><th>Store</th><th>Person in charge</th><th>Stock Issue</th><th>Returned Stock</th><th>Expected Collections</th><th>Money Received</th><th>Money Variance</th><th>Stock Difference</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (!$sessions): ?><tr><td colspan="11" class="store-muted">No daily store records yet.</td></tr>
        <?php else: foreach ($sessions as $s): ?><tr>
            <td><?= e(date('d M Y',strtotime($s['business_date']))) ?></td><td><?= e($s['store_name']) ?></td><td><?= e($s['responsible_person']) ?></td>
            <td><?= e($s['issue_number'] ?: '—') ?></td><td><?= e($s['return_number'] ?: 'Pending') ?></td>
            <td>KES <?= number_format((float)$s['expected_sales'],2) ?></td><td>KES <?= number_format((float)$s['total_received'],2) ?></td>
            <td><strong><?= $s['status']==='Reconciled' ? 'KES '.number_format((float)$s['money_variance'],2) : '—' ?></strong></td>
            <td><?= $s['status']==='Reconciled' ? number_format((int)$s['stock_variance_items']).' item(s)' : '—' ?></td>
            <td><span class="store-status <?= $s['status']==='Reconciled'?'done':'' ?>"><?= e($s['status']) ?></span></td>
            <td><a class="store-btn secondary" href="view.php?id=<?= (int)$s['id'] ?>">View</a> <a class="store-btn secondary" href="print_issue.php?id=<?= (int)$s['id'] ?>" target="_blank">Print Issue</a> <?php if ($s['status']==='Open'): ?><a class="store-btn" href="index.php?reconcile=<?= (int)$s['id'] ?>">Reconcile</a><?php endif; ?></td>
        </tr><?php endforeach; endif; ?>
        </tbody>
    </table></div>
</section>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
(() => {
    const search = document.getElementById('issue_product_search');
    const rows = Array.from(document.querySelectorAll('#issue_products_table .issue-product-row'));
    if (!search) return;
    const count = document.getElementById('issue_product_count');
    const empty = document.getElementById('issue_no_products');
    const filter = () => {
        const term = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.forEach(row => {
            const match = row.dataset.search.includes(term);
            row.hidden = !match;
            if (match) visible++;
        });
        count.textContent = visible + ' of ' + rows.length + ' stocked product(s) shown';
        empty.hidden = visible !== 0;
    };
    search.addEventListener('input', filter);
})();
</script>
