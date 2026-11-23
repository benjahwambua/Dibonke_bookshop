<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/report_tools.php';

$pageTitle = 'Stock Movements';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust_stock') {
    verify_csrf();
    requireModulePermission('stock_movements', 'create');

    try {
        $productId = (int)($_POST['product_id'] ?? 0);
        $mode = (string)($_POST['adjustment_mode'] ?? '');
        $quantityRaw = trim((string)($_POST['quantity'] ?? ''));
        $reason = trim((string)($_POST['notes'] ?? ''));

        if ($productId <= 0) {
            throw new RuntimeException('Select a product to adjust.');
        }
        if (!in_array($mode, ['increase', 'decrease', 'set'], true)) {
            throw new RuntimeException('Select a valid stock adjustment method.');
        }
        if (!preg_match('/^\d+$/', $quantityRaw)) {
            throw new RuntimeException('Enter a whole, non-negative stock quantity.');
        }
        if ($mode !== 'set' && (int)$quantityRaw < 1) {
            throw new RuntimeException('The adjustment quantity must be at least one.');
        }
        if ($reason === '' || strlen($reason) > 500) {
            throw new RuntimeException('Enter a reason of up to 500 characters for this adjustment.');
        }

        $requestedQuantity = (int)$quantityRaw;

        $conn->beginTransaction();

        // Lock the product row so two stock adjustments or a sale/purchase
        // cannot overwrite each other's balance.
        $productStmt = $conn->prepare(
            "SELECT id, product_name, stock_quantity
             FROM products
             WHERE id = ? AND status = 'Active'
             FOR UPDATE"
        );
        $productStmt->execute([$productId]);
        $product = $productStmt->fetch();
        if (!$product) {
            throw new RuntimeException('The selected product is missing or inactive.');
        }

        $stockBefore = (int)$product['stock_quantity'];
        if ($mode === 'increase') {
            $stockAfter = $stockBefore + $requestedQuantity;
            $movementLabel = 'Increase';
        } elseif ($mode === 'decrease') {
            $stockAfter = $stockBefore - $requestedQuantity;
            $movementLabel = 'Decrease';
        } else {
            $stockAfter = $requestedQuantity;
            $movementLabel = 'Physical count';
        }

        if ($stockAfter < 0) {
            throw new RuntimeException('The adjustment would make stock negative. Current stock: ' . $stockBefore . '.');
        }
        if ($stockAfter === $stockBefore) {
            throw new RuntimeException('The entered quantity does not change the current stock balance.');
        }

        $movementQuantity = abs($stockAfter - $stockBefore);
        $update = $conn->prepare("UPDATE products SET stock_quantity = ? WHERE id = ?");
        $update->execute([$stockAfter, $productId]);

        $movement = $conn->prepare(
            "INSERT INTO stock_movements (
                product_id, user_id, movement_type, quantity, stock_before,
                stock_after, reference_type, reference_id, notes, movement_date
             ) VALUES (?, ?, 'Adjustment', ?, ?, ?, 'manual_adjustment', NULL, ?, ?)"
        );
        $movement->execute([
            $productId,
            (int)$_SESSION['user_id'],
            $movementQuantity,
            $stockBefore,
            $stockAfter,
            $movementLabel . ': ' . $reason,
            date('Y-m-d H:i:s')
        ]);

        $conn->commit();
        $_SESSION['stock_flash'] = 'Stock adjusted for ' . $product['product_name'] . '. New balance: ' . $stockAfter . '.';
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Dibonke stock adjustment failed: ' . $e->getMessage());
        $_SESSION['stock_flash'] = $e instanceof RuntimeException
            ? 'Stock adjustment failed: ' . $e->getMessage()
            : 'Stock adjustment failed. Check the database setup and application error log.';
    }

    header('Location: index.php');
    exit;
}

$search = trim((string)($_GET['q'] ?? ''));
$type = (string)($_GET['type'] ?? '');
$from = (string)($_GET['from'] ?? date('Y-m-01'));
$to = (string)($_GET['to'] ?? date('Y-m-d'));
$validDate = '/^\\d{4}-\\d{2}-\\d{2}$/';
if (!preg_match($validDate, $from)) $from = date('Y-m-01');
if (!preg_match($validDate, $to)) $to = date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$productId = (int)($_GET['product_id'] ?? 0);
$hasMovementStoreId = (bool)$conn->query("SHOW COLUMNS FROM stock_movements LIKE 'store_id'")->fetchColumn();
$storeFilter = (int)($_GET['store_id'] ?? 0);
$movementStoreSelect = $hasMovementStoreId ? 'st.store_name' : "NULL AS store_name";
$movementStoreJoin = $hasMovementStoreId ? 'LEFT JOIN stores st ON st.id = sm.store_id' : '';
$movementStores = $hasMovementStoreId
    ? $conn->query("SELECT id,store_name FROM stores ORDER BY store_type='Main' DESC,store_name")->fetchAll(PDO::FETCH_ASSOC)
    : [];

$sql = "SELECT sm.*, p.product_name, p.sku, u.full_name, {$movementStoreSelect}
        FROM stock_movements sm
        INNER JOIN products p ON p.id = sm.product_id
        LEFT JOIN users u ON u.id = sm.user_id
        {$movementStoreJoin}
        WHERE DATE(sm.movement_date) BETWEEN :from_date AND :to_date";
$params = [':from_date' => $from, ':to_date' => $to];

if ($search !== '') {
    $searchStore = $hasMovementStoreId ? " OR st.store_name LIKE :q" : '';
    $sql .= " AND (p.product_name LIKE :q OR p.sku LIKE :q OR sm.notes LIKE :q OR CAST(sm.reference_id AS CHAR) LIKE :q{$searchStore})";
    $params[':q'] = "%{$search}%";
}
if ($hasMovementStoreId && $storeFilter > 0) {
    $sql .= " AND sm.store_id = :store_id";
    $params[':store_id'] = $storeFilter;
}

$types = ['Purchase', 'Sale', 'Return', 'Adjustment', 'Damage', 'Opening Stock'];
if ($productId > 0) { $sql .= " AND sm.product_id = :product_id"; $params[':product_id'] = $productId; }

if (in_array($type, $types, true)) {
    $sql .= " AND sm.movement_type = :type";
    $params[':type'] = $type;
}
$sql .= " ORDER BY sm.id DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();
$exportRows = [];
foreach ($movements as $m) { $exportRows[] = [$m['movement_date'],$m['product_name'],$m['sku'],$m['store_name'] ?? '',$m['movement_type'],$m['quantity'],$m['stock_before'],$m['stock_after'],$m['reference_type'],$m['reference_id'],$m['full_name'],$m['notes']]; }
dibonkeReportExport('stock_movements_'.$from.'_to_'.$to,['Date','Product','SKU','Store','Movement Type','Quantity','Stock Before','Stock After','Reference Type','Reference ID','User','Notes'],$exportRows);
$filterProducts = $conn->query("SELECT id, product_name FROM products ORDER BY product_name")->fetchAll(PDO::FETCH_ASSOC);

$stats = [
    'entries' => (int)$conn->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn(),
    'purchases' => (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'Purchase'")->fetchColumn(),
    'adjustments' => (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'Adjustment'")->fetchColumn(),
    'damages' => (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'Damage'")->fetchColumn()
];

$canAdjustStock = hasModulePermission('stock_movements', 'create');
$adjustmentProducts = $canAdjustStock
    ? $conn->query("SELECT id, product_name, sku, stock_quantity, unit FROM products WHERE status = 'Active' ORDER BY product_name")->fetchAll()
    : [];

$flash = $_SESSION['stock_flash'] ?? null;
unset($_SESSION['stock_flash']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
    <?php dibonkeReportPrintStyles(); ?>
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="<?= BASE_URL ?>/modules/products/index.php">Inventory</a>
        <span aria-hidden="true">/</span>
        <span class="current">Stock Movements</span>
    </nav>

    <div class="page-head">
        <div>
            <h1>Stock Movements</h1>
            <p>A traceable record of inventory received, sold, returned, damaged or adjusted.</p>
        </div>
        <?php if ($canAdjustStock): ?>
            <button class="btn btn-primary" type="button" onclick="document.getElementById('stockAdjustmentModal').classList.add('show')">
                <i class="fas fa-sliders-h"></i> Adjust Stock
            </button>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

    <div class="stats-grid">
        <div class="stat"><strong><?= number_format($stats['entries']) ?></strong><span>Total Entries</span></div>
        <div class="stat"><strong><?= number_format($stats['purchases']) ?></strong><span>Purchase Entries</span></div>
        <div class="stat"><strong><?= number_format($stats['adjustments']) ?></strong><span>Adjustments</span></div>
        <div class="stat"><strong><?= number_format($stats['damages']) ?></strong><span>Damage Entries</span></div>
    </div>

    <form class="toolbar" method="get">
        <input name="q" value="<?= e($search) ?>" placeholder="Search product, SKU, reference or notes...">
        <select name="type">
            <option value="">All movement types</option>
            <?php foreach ($types as $movementType): ?>
                <option value="<?= e($movementType) ?>" <?= $type === $movementType ? 'selected' : '' ?>><?= e($movementType) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="product_id"><option value="0">All products</option><?php foreach ($filterProducts as $fp): ?><option value="<?= (int)$fp['id'] ?>" <?= $productId === (int)$fp['id'] ? 'selected' : '' ?>><?= e($fp['product_name']) ?></option><?php endforeach; ?></select>
        <?php if ($hasMovementStoreId): ?><select name="store_id"><option value="0">All stores</option><?php foreach ($movementStores as $movementStore): ?><option value="<?= (int)$movementStore['id'] ?>" <?= $storeFilter === (int)$movementStore['id'] ? 'selected' : '' ?>><?= e($movementStore['store_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <label>From <input type="date" name="from" value="<?= e($from) ?>"></label>
        <label>To <input type="date" name="to" value="<?= e($to) ?>"></label>
        <button class="btn btn-light" type="submit"><i class="fas fa-search"></i> Filter</button>
        <a class="btn btn-light" href="<?= BASE_URL ?>/modules/stock_movements/index.php">Reset</a>
    </form>

    <?php dibonkeReportActions('stock_movements'); ?>

    <div class="table-card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Date</th><th>Product</th><th>Store</th><th>Type</th><th>Quantity</th><th>Stock Before</th><th>Stock After</th><th>Reference</th><th>User</th><th>Notes</th></tr>
                </thead>
                <tbody>
                <?php if (!$movements): ?>
                    <tr><td colspan="10" class="empty-state">No stock movements found.</td></tr>
                <?php else: foreach ($movements as $movement): ?>
                    <tr>
                        <td><?= e(date('d M Y H:i', strtotime($movement['movement_date']))) ?></td>
                        <td><strong><?= e($movement['product_name']) ?></strong><br><small class="muted"><?= e($movement['sku'] ?: 'No SKU') ?></small></td>
                        <td><?= e($movement['store_name'] ?: '—') ?></td>
                        <td><span class="type <?= strtolower(str_replace(' ', '-', $movement['movement_type'])) ?>"><?= e($movement['movement_type']) ?></span></td>
                        <td><strong><?= number_format((int)$movement['quantity']) ?></strong></td>
                        <td><?= number_format((int)$movement['stock_before']) ?></td>
                        <td><?= number_format((int)$movement['stock_after']) ?></td>
                        <td><?= e($movement['reference_type'] ?: '—') ?> <?= $movement['reference_id'] ? '#' . (int)$movement['reference_id'] : '' ?></td>
                        <td><?= e($movement['full_name'] ?: '—') ?></td>
                        <td><?= e($movement['notes'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php if ($canAdjustStock): ?>
<div class="adjustment-modal" id="stockAdjustmentModal" role="dialog" aria-modal="true" aria-labelledby="stockAdjustmentTitle">
    <div class="adjustment-card">
        <button class="modal-close" type="button" aria-label="Close" onclick="document.getElementById('stockAdjustmentModal').classList.remove('show')">&times;</button>
        <h2 id="stockAdjustmentTitle">Adjust Stock</h2>
        <p class="text-muted">Use this for physical counts, corrections or stock losses. Every adjustment is recorded in the inventory ledger.</p>

        <form method="post" action="index.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="adjust_stock">

            <label>Product *
                <select name="product_id" id="adjustmentProduct" required>
                    <option value="">Select a product</option>
                    <?php foreach ($adjustmentProducts as $product): ?>
                        <option value="<?= (int)$product['id'] ?>" data-stock="<?= (int)$product['stock_quantity'] ?>" data-unit="<?= e($product['unit'] ?: 'units') ?>">
                            <?= e($product['product_name']) ?><?= $product['sku'] ? ' — ' . e($product['sku']) : '' ?> (Stock: <?= number_format((int)$product['stock_quantity']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Adjustment Method *
                <select name="adjustment_mode" id="adjustmentMode" required>
                    <option value="increase">Increase current stock</option>
                    <option value="decrease">Decrease current stock</option>
                    <option value="set">Set to counted stock</option>
                </select>
            </label>

            <label id="adjustmentQuantityLabel">Quantity to add/remove *
                <input type="number" name="quantity" id="adjustmentQuantity" min="1" step="1" value="1" required>
            </label>

            <label>Reason / Notes *
                <textarea name="notes" rows="3" maxlength="500" required placeholder="e.g. Physical stock count, damaged items, correction after recount"></textarea>
            </label>

            <div class="form-actions">
                <button class="btn btn-light" type="button" onclick="document.getElementById('stockAdjustmentModal').classList.remove('show')">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Save Adjustment</button>
            </div>
        </form>
    </div>
</div>
<style>
.adjustment-modal{display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.55)}
.adjustment-modal.show{display:flex}
.adjustment-card{position:relative;width:min(540px,100%);max-height:90vh;overflow:auto;padding:25px;border:1px solid var(--db-border);border-radius:14px;background:#fff;box-shadow:0 24px 70px rgba(16,38,59,.22)}
.adjustment-card h2{margin:0 35px 7px 0}
.adjustment-card>p{margin:0 0 18px}
.adjustment-card label{display:block;margin:13px 0;color:#526277;font-size:12px;font-weight:750}
.adjustment-card input,.adjustment-card select,.adjustment-card textarea{display:block;width:100%;box-sizing:border-box;margin-top:6px;padding:10px 11px;border:1px solid #d9e1e9;border-radius:8px;background:#fff;color:var(--db-ink);font:inherit;font-size:13px}
.adjustment-card .modal-close{position:absolute;right:17px;top:12px;border:0;background:none;color:#64748b;font-size:28px;cursor:pointer}
.type{display:inline-flex;padding:5px 9px;border-radius:20px;font-size:11px;font-weight:750;white-space:nowrap}
.type.purchase{background:#dcfce7;color:#166534}.type.sale{background:#fee2e2;color:#991b1b}.type.adjustment{background:#dbeafe;color:#1d4ed8}.type.damage{background:#fef3c7;color:#92400e}.type.return{background:#ede9fe;color:#6d28d9}.type.opening-stock{background:#e0f2fe;color:#075985}
@media(max-width:600px){.adjustment-card{padding:19px}.adjustment-card .form-actions{justify-content:stretch}.adjustment-card .form-actions .btn{flex:1}}
</style>
<script>
const adjustmentProduct = document.getElementById('adjustmentProduct');
const adjustmentMode = document.getElementById('adjustmentMode');
const adjustmentQuantity = document.getElementById('adjustmentQuantity');
const adjustmentQuantityLabel = document.getElementById('adjustmentQuantityLabel');
function syncAdjustmentQuantity(resetAmount = false) {
    const option = adjustmentProduct.options[adjustmentProduct.selectedIndex];
    const currentStock = option ? option.dataset.stock || '0' : '0';
    if (adjustmentMode.value === 'set') {
        adjustmentQuantityLabel.firstChild.textContent = 'Counted stock quantity *';
        adjustmentQuantity.min = '0';
        adjustmentQuantity.value = currentStock;
    } else {
        adjustmentQuantityLabel.firstChild.textContent = 'Quantity to add/remove *';
        adjustmentQuantity.min = '1';
        if (resetAmount || Number(adjustmentQuantity.value) < 1) adjustmentQuantity.value = '1';
    }
}
adjustmentProduct.addEventListener('change', () => syncAdjustmentQuantity());
adjustmentMode.addEventListener('change', () => syncAdjustmentQuantity(true));
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
