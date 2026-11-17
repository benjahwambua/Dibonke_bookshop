<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/product_helpers.php';
require_once __DIR__ . '/../../includes/audit.php';

requireModulePermission('products', 'edit');

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: index.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) {
    $_SESSION['product_flash'] = 'Product not found.';
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $name = trim((string)($_POST['product_name'] ?? ''));
        if ($name === '' || strlen($name) > 160) {
            throw new RuntimeException('Enter a product name of up to 160 characters.');
        }

        $productType = normalizeProductType($_POST['product_type'] ?? 'General');
        $isbn = normalizeProductIsbn($_POST['isbn'] ?? '');

        $categoryId = (int)($_POST['category_id'] ?? 0);
        $brandId = (int)($_POST['brand_id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);

        $buyRaw = trim((string)($_POST['buying_price'] ?? ''));
        $sellRaw = trim((string)($_POST['selling_price'] ?? ''));
        $wholesaleRaw = trim((string)($_POST['wholesale_price'] ?? ''));
        $reorderRaw = trim((string)($_POST['reorder_level'] ?? ''));

        if ($buyRaw === '' || !is_numeric($buyRaw) || $sellRaw === '' || !is_numeric($sellRaw)) {
            throw new RuntimeException('Enter valid buying and selling prices.');
        }
        if ($wholesaleRaw !== '' && !is_numeric($wholesaleRaw)) {
            throw new RuntimeException('Enter a valid wholesale price or leave it blank.');
        }
        if (!preg_match('/^\d+$/', $reorderRaw)) {
            throw new RuntimeException('Reorder level must be a whole, non-negative quantity.');
        }

        $buy = (float)$buyRaw;
        $sell = (float)$sellRaw;
        $wholesale = $wholesaleRaw === '' ? 0.0 : (float)$wholesaleRaw;
        $reorder = (int)$reorderRaw;
        if ($buy < 0 || $sell < 0 || $wholesale < 0) {
            throw new RuntimeException('Prices cannot be negative.');
        }

        $sku = trim((string)($_POST['sku'] ?? '')) ?: null;
        $barcode = trim((string)($_POST['barcode'] ?? '')) ?: null;
        $unit = trim((string)($_POST['unit'] ?? 'Piece')) ?: 'Piece';
        $icon = trim((string)($_POST['icon'] ?? '📦')) ?: '📦';
        $status = (($_POST['status'] ?? 'Active') === 'Inactive') ? 'Inactive' : 'Active';

        $conn->beginTransaction();

        // Lock the current product so a concurrent sale or purchase cannot be
        // overwritten by a stale stock value from the edit form.
        $lock = $conn->prepare("SELECT stock_quantity FROM products WHERE id = ? FOR UPDATE");
        $lock->execute([$id]);
        $lockedProduct = $lock->fetch();
        if (!$lockedProduct) {
            throw new RuntimeException('Product not found.');
        }
        $stock = (int)$lockedProduct['stock_quantity'];

        $categoryName = null;
        if ($categoryId > 0) {
            $lookup = $conn->prepare("SELECT category_name, status FROM categories WHERE id = ? LIMIT 1");
            $lookup->execute([$categoryId]);
            $category = $lookup->fetch();
            if (!$category || ($category['status'] !== 'Active' && $categoryId !== (int)$p['category_id'])) {
                throw new RuntimeException('Choose an active category. An existing inactive category may be retained until you select another.');
            }
            $categoryName = $category['category_name'];
        }

        if ($brandId > 0) {
            $lookup = $conn->prepare("SELECT status FROM brands WHERE id = ? LIMIT 1");
            $lookup->execute([$brandId]);
            $brandStatus = $lookup->fetchColumn();
            if ($brandStatus === false || ($brandStatus !== 'Active' && $brandId !== (int)$p['brand_id'])) {
                throw new RuntimeException('Choose an active brand or publisher.');
            }
        }

        if ($supplierId > 0) {
            $lookup = $conn->prepare("SELECT status FROM suppliers WHERE id = ? LIMIT 1");
            $lookup->execute([$supplierId]);
            $supplierStatus = $lookup->fetchColumn();
            if ($supplierStatus === false || ($supplierStatus !== 'Active' && $supplierId !== (int)$p['supplier_id'])) {
                throw new RuntimeException('Choose an active supplier.');
            }
        }

        $priceListStmt = $conn->prepare("SELECT id FROM price_lists WHERE code = 'normal' AND status = 'Active' LIMIT 1");
        $priceListStmt->execute();
        $normalPriceListId = (int)$priceListStmt->fetchColumn();
        if ($normalPriceListId <= 0) {
            throw new RuntimeException('The Standard price list is missing. Complete the price-list database setup before editing products.');
        }

        $wholesalePriceListId = 0;
        if ($wholesale > 0) {
            $priceListStmt = $conn->prepare("SELECT id FROM price_lists WHERE code = 'wholesale' AND status = 'Active' LIMIT 1");
            $priceListStmt->execute();
            $wholesalePriceListId = (int)$priceListStmt->fetchColumn();
            if ($wholesalePriceListId <= 0) {
                throw new RuntimeException('A wholesale price was entered, but the Wholesale price list is not configured.');
            }
        }

        $update = $conn->prepare(
            "UPDATE products SET
                product_name = ?, product_type = ?, isbn = ?, category = ?,
                category_id = ?, brand_id = ?, supplier_id = ?, sku = ?,
                barcode = ?, unit = ?, buying_price = ?, selling_price = ?,
                wholesale_price = ?, stock_quantity = ?, reorder_level = ?,
                icon = ?, status = ?
             WHERE id = ?"
        );
        $update->execute([
            $name, $productType, $isbn, $categoryName, $categoryId ?: null,
            $brandId ?: null, $supplierId ?: null, $sku, $barcode, $unit,
            $buy, $sell, $wholesale, $stock, $reorder, $icon, $status, $id
        ]);

        $priceInsert = $conn->prepare(
            "INSERT INTO product_prices (price_list_id, product_id, min_quantity, price)
             VALUES (?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE price = VALUES(price)"
        );
        $priceInsert->execute([$normalPriceListId, $id, $sell]);

        if ($wholesalePriceListId > 0) {
            $priceInsert->execute([$wholesalePriceListId, $id, $wholesale]);
        } else {
            $deleteWholesale = $conn->prepare(
                "DELETE FROM product_prices
                 WHERE product_id = ? AND min_quantity = 1
                   AND price_list_id = (SELECT id FROM price_lists WHERE code = 'wholesale' LIMIT 1)"
            );
            $deleteWholesale->execute([$id]);
        }

        recordAuditEvent($conn,'Update','products','Updated product '.$name,'product',$id,['product_name'=>$p['product_name'],'sku'=>$p['sku']??null,'barcode'=>$p['barcode']??null,'selling_price'=>$p['selling_price']??null],['product_name'=>$name,'sku'=>$sku,'barcode'=>$barcode,'selling_price'=>$sell,'status'=>$status]);
        $conn->commit();
        $_SESSION['product_flash'] = 'Product updated successfully.';
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        if ($e instanceof PDOException && $e->getCode() === '23000' && strpos($e->getMessage(), 'uq_products_isbn') !== false) {
            $error = 'That ISBN is already assigned to another product.';
        } else {
            error_log('Dibonke product update failed: ' . $e->getMessage());
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'The product could not be updated. Check the database setup and application error log.';
        }
    }
}

$categoryStmt = $conn->prepare("SELECT id, category_name FROM categories WHERE status = 'Active' OR id = ? ORDER BY category_name");
$categoryStmt->execute([(int)$p['category_id']]);
$categories = $categoryStmt->fetchAll();

$brandStmt = $conn->prepare("SELECT id, brand_name FROM brands WHERE status = 'Active' OR id = ? ORDER BY brand_name");
$brandStmt->execute([(int)$p['brand_id']]);
$brands = $brandStmt->fetchAll();

$supplierStmt = $conn->prepare("SELECT id, supplier_name FROM suppliers WHERE status = 'Active' OR id = ? ORDER BY supplier_name");
$supplierStmt->execute([(int)$p['supplier_id']]);
$suppliers = $supplierStmt->fetchAll();

$pageTitle = 'Edit Product';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="<?= BASE_URL ?>/modules/products/index.php">Catalogue</a>
        <span aria-hidden="true">/</span>
        <span class="current">Edit Product</span>
    </nav>

    <div class="page-head">
        <div>
            <h1>Edit Product</h1>
            <p>Update book, stationery or general retail item details and pricing.</p>
        </div>
        <a class="btn btn-light" href="index.php"><i class="fas fa-arrow-left"></i> Back to Products</a>
    </div>

    <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="panel" style="max-width:980px">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="form-grid">
            <label>Product Name *
                <input name="product_name" required maxlength="160" value="<?= e($p['product_name']) ?>">
            </label>
            <label>Item Type
                <select name="product_type">
                    <?php foreach (['Book', 'Stationery', 'General'] as $type): ?>
                        <option value="<?= e($type) ?>" <?= ($p['product_type'] ?? 'General') === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>ISBN (optional for books)
                <input name="isbn" maxlength="20" value="<?= e($p['isbn'] ?? '') ?>" placeholder="ISBN-10 or ISBN-13">
            </label>
            <label>SKU
                <input name="sku" maxlength="80" value="<?= e($p['sku'] ?? '') ?>">
            </label>
            <label>Barcode
                <input name="barcode" maxlength="100" value="<?= e($p['barcode'] ?? '') ?>">
            </label>
            <label>Category
                <select name="category_id">
                    <option value="">Uncategorised</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int)$category['id'] ?>" <?= (int)$p['category_id'] === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Brand / Publisher
                <select name="brand_id">
                    <option value="">Not specified</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= (int)$brand['id'] ?>" <?= (int)$p['brand_id'] === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['brand_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Preferred Supplier
                <select name="supplier_id">
                    <option value="">Not specified</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= (int)$supplier['id'] ?>" <?= (int)$p['supplier_id'] === (int)$supplier['id'] ? 'selected' : '' ?>><?= e($supplier['supplier_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Purchase Price (KES) *
                <input type="number" step=".01" min="0" name="buying_price" value="<?= e($p['buying_price']) ?>" required>
            </label>
            <label>Retail Selling Price (KES) *
                <input type="number" step=".01" min="0" name="selling_price" value="<?= e($p['selling_price']) ?>" required>
            </label>
            <label>Wholesale Price (KES)
                <input type="number" step=".01" min="0" name="wholesale_price" value="<?= e($p['wholesale_price']) ?>">
            </label>
            <label>Current Stock
                <input type="number" min="0" value="<?= e($p['stock_quantity']) ?>" readonly>
            </label>
            <label>Reorder Level
                <input type="number" min="0" step="1" name="reorder_level" value="<?= e($p['reorder_level']) ?>" required>
            </label>
            <label>Unit of Measure
                <input name="unit" maxlength="50" value="<?= e($p['unit'] ?: 'Piece') ?>">
            </label>
            <label>Product Icon
                <input name="icon" maxlength="20" value="<?= e($p['icon'] ?: '📦') ?>">
            </label>
            <label>Status
                <select name="status">
                    <option value="Active" <?= $p['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $p['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </label>
        </div>

        <p class="text-muted" style="margin-top:16px">Stock is changed through the Stock Movements adjustment workflow or by recording purchases and sales, so the inventory ledger stays in sync.</p>
        <div class="form-actions">
            <a class="btn btn-light" href="index.php">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </form>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
