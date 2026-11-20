<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireModuleAccess('products');

$pageTitle = 'Inventory Overview';
$search = trim((string)($_GET['q'] ?? ''));
$stockFilter = (string)($_GET['stock'] ?? 'all');
if (!in_array($stockFilter, ['all', 'low', 'out', 'healthy'], true)) {
    $stockFilter = 'all';
}

$summary = $conn->query("
    SELECT
        COUNT(*) AS product_count,
        COUNT(CASE WHEN stock_quantity > 0 THEN 1 END) AS stocked_products,
        COALESCE(SUM(stock_quantity * buying_price), 0) AS stock_value,
        SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
        SUM(CASE WHEN stock_quantity > 0 AND stock_quantity <= reorder_level THEN 1 ELSE 0 END) AS low_stock
    FROM products
    WHERE status = 'Active'
")->fetch(PDO::FETCH_ASSOC);

$where = ["status = 'Active'"];
$params = [];
if ($search !== '') {
    $where[] = "(product_name LIKE ? OR sku LIKE ? OR barcode LIKE ? OR isbn LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($stockFilter === 'low') {
    $where[] = "stock_quantity > 0 AND stock_quantity <= reorder_level";
} elseif ($stockFilter === 'out') {
    $where[] = "stock_quantity <= 0";
} elseif ($stockFilter === 'healthy') {
    $where[] = "stock_quantity > reorder_level";
}

$sql = "SELECT id, product_name, sku, stock_quantity, reorder_level, unit, buying_price
        FROM products WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE WHEN stock_quantity <= 0 THEN 0
                 WHEN stock_quantity <= reorder_level THEN 1 ELSE 2 END,
            product_name";
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
    <style>
        .inventory-head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:20px}
        .inventory-head h1{margin:0 0 5px}.inventory-head p{margin:0;color:#64748b;font-size:13px}
        .inventory-actions{display:flex;gap:8px;flex-wrap:wrap}
        .inventory-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 14px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700}
        .inventory-btn.primary{background:#064b91;color:#fff}.inventory-btn.light{background:#eef2f7;color:#334155}
        .inventory-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}
        .inventory-stat{background:#fff;border:1px solid #e7edf3;border-radius:11px;padding:15px}
        .inventory-stat span{display:block;color:#64748b;font-size:11px;font-weight:700}
        .inventory-stat strong{display:block;margin-top:7px;color:#17324d;font-size:21px}
        .inventory-toolbar{display:flex;gap:9px;flex-wrap:wrap;align-items:center;background:#fff;padding:13px;border:1px solid #e7edf3;border-radius:11px;margin-bottom:14px}
        .inventory-toolbar input,.inventory-toolbar select{min-height:38px;padding:8px 10px;border:1px solid #dbe3ec;border-radius:7px;font-size:12px}
        .inventory-toolbar input{flex:1;min-width:210px}
        .inventory-toolbar button{min-height:38px;padding:8px 14px;border:0;border-radius:7px;background:#064b91;color:#fff;font-weight:700;cursor:pointer}
        .inventory-table-card{background:#fff;border:1px solid #e7edf3;border-radius:11px;overflow:hidden}
        .inventory-table-wrap{overflow-x:auto}
        .inventory-table{width:100%;border-collapse:collapse;min-width:700px}
        .inventory-table th{background:#f5f8fb;color:#526477;text-align:left;padding:12px;font-size:11px}
        .inventory-table td{padding:12px;border-top:1px solid #edf1f5;font-size:12px}
        .inventory-status{display:inline-block;padding:4px 8px;border-radius:20px;font-size:10px;font-weight:800}
        .inventory-status.out{background:#fee2e2;color:#b91c1c}.inventory-status.low{background:#fef3c7;color:#92400e}.inventory-status.ok{background:#dcfce7;color:#166534}
        .inventory-empty{text-align:center;padding:34px;color:#64748b}
        .inventory-note{margin:12px 2px;color:#64748b;font-size:11px}
        @media(max-width:1050px){.inventory-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:650px){.inventory-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.inventory-stat strong{font-size:18px}}
    </style>

    <div class="inventory-head">
        <div>
            <h1>Inventory Overview</h1>
            <p>See what is in stock, what is running low and what needs replenishing.</p>
        </div>
        <div class="inventory-actions">
            <a class="inventory-btn light" href="<?= BASE_URL ?>/modules/products/index.php"><i class="fas fa-book"></i> Catalogue</a>
            <?php if (canAccessModule('stock_movements')): ?>
                <a class="inventory-btn primary" href="<?= BASE_URL ?>/modules/stock_movements/index.php"><i class="fas fa-exchange-alt"></i> Stock Movements</a>
            <?php endif; ?>
        </div>
    </div>

    <section class="inventory-stats" aria-label="Inventory summary">
        <div class="inventory-stat"><span>Active products</span><strong><?= number_format((int)$summary['product_count']) ?></strong></div>
        <div class="inventory-stat"><span>Products with stock</span><strong><?= number_format((int)$summary['stocked_products']) ?></strong></div>
        <div class="inventory-stat"><span>Stock cost value</span><strong>KES <?= number_format((float)$summary['stock_value'], 2) ?></strong></div>
        <div class="inventory-stat"><span>Low stock</span><strong><?= number_format((int)$summary['low_stock']) ?></strong></div>
        <div class="inventory-stat"><span>Out of stock</span><strong><?= number_format((int)$summary['out_of_stock']) ?></strong></div>
    </section>

    <form class="inventory-toolbar" method="get">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search by book, product, SKU, barcode or ISBN">
        <select name="stock" aria-label="Stock status">
            <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>All stock levels</option>
            <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of stock</option>
            <option value="healthy" <?= $stockFilter === 'healthy' ? 'selected' : '' ?>>Healthy stock</option>
        </select>
        <button type="submit"><i class="fas fa-search"></i> Search</button>
        <a class="inventory-btn light" href="<?= BASE_URL ?>/modules/inventory/index.php">Reset</a>
    </form>

    <div class="inventory-table-card">
        <div class="inventory-table-wrap">
            <table class="inventory-table">
                <thead><tr><th>Product / Book</th><th>SKU</th><th>Available stock</th><th>Reorder level</th><th>Stock cost value</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="6" class="inventory-empty">No products match this search or stock filter.</td></tr>
                <?php else: foreach ($products as $product):
                    $quantity = (int)$product['stock_quantity'];
                    $reorder = (int)$product['reorder_level'];
                    $statusClass = $quantity <= 0 ? 'out' : ($quantity <= $reorder ? 'low' : 'ok');
                    $statusLabel = $quantity <= 0 ? 'Out of stock' : ($quantity <= $reorder ? 'Low stock' : 'In stock');
                ?>
                    <tr>
                        <td><strong><?= e($product['product_name']) ?></strong></td>
                        <td><?= e($product['sku'] ?: '—') ?></td>
                        <td><strong><?= number_format($quantity) ?></strong> <?= e($product['unit'] ?: 'units') ?></td>
                        <td><?= number_format($reorder) ?> <?= e($product['unit'] ?: 'units') ?></td>
                        <td>KES <?= number_format($quantity * (float)$product['buying_price'], 2) ?></td>
                        <td><span class="inventory-status <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <p class="inventory-note">Stock cost values use each product's recorded weighted-average buying cost. Product quantities are shown in their own stock units and are not added together across different units. To receive stock, record a purchase; use a stock adjustment only for counts or corrections.</p>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
