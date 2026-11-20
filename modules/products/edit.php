<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/product_helpers.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/product_image_upload.php';

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
    $newProductImage = null;

    try {
        $name = trim((string)($_POST['product_name'] ?? ''));
        if ($name === '' || strlen($name) > 160) {
            throw new RuntimeException('Enter a product name of up to 160 characters.');
        }

        $productType = normalizeProductType($_POST['product_type'] ?? 'General');
        $isbn = normalizeProductIsbn($_POST['isbn'] ?? '');

        $categoryNameInput = trim((string)($_POST['category_name'] ?? ''));
        $brandNameInput = trim((string)($_POST['brand_name'] ?? ''));
        $supplierNameInput = trim((string)($_POST['supplier_name'] ?? ''));
        $categoryId = 0;
        $brandId = 0;
        $supplierId = 0;

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

        $skuInput = trim((string)($_POST['sku'] ?? ''));
        $barcode = trim((string)($_POST['barcode'] ?? '')) ?: null;
        $unit = trim((string)($_POST['unit'] ?? 'Piece')) ?: 'Piece';
        $purchaseUnit = trim((string)($_POST['purchase_unit'] ?? ''));
        $unitsPerPurchase = (int)($_POST['units_per_purchase'] ?? 1);
        $bulkPriceRaw = trim((string)($_POST['bulk_selling_price'] ?? ''));
        $bulkSellingPrice = $bulkPriceRaw === '' ? null : (float)$bulkPriceRaw;
        if ($unitsPerPurchase < 1 || ($purchaseUnit === '' && $unitsPerPurchase !== 1)) throw new RuntimeException('Enter a purchase unit and a valid units-per-purchase quantity.');
        if ($bulkSellingPrice !== null && $bulkSellingPrice < 0) throw new RuntimeException('Bulk selling price cannot be negative.');
        if ($purchaseUnit === '') { $purchaseUnit = $unit; $unitsPerPurchase = 1; }
        $icon = trim((string)($_POST['icon'] ?? '📦')) ?: '📦';
        $newProductImage = uploadProductImage();
        $imagePath = $newProductImage ?: (string)($p['image_path'] ?? '');
        if (!empty($_POST['remove_product_image'])) $imagePath = '';
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

        // Keep an existing/manual SKU; generate one only when this field is blank.
        $sku = $skuInput !== '' ? $skuInput : generateProductSku($conn, $productType, $name, $id);
        $skuCheck = $conn->prepare('SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1');
        $skuCheck->execute([$sku, $id]);
        if ($skuCheck->fetchColumn()) {
            throw new RuntimeException('That SKU is already in use. Enter a different SKU or leave the field blank for automatic generation.');
        }

        // Resolve category, publisher and supplier from the names typed by the user.
        // New records are created inside the same transaction as the product update.
        $categoryName = null;
        if ($categoryNameInput !== '') {
            if (strlen($categoryNameInput) > 120) throw new RuntimeException('Category name must be 120 characters or fewer.');
            $lookup = $conn->prepare("SELECT id, category_name, status FROM categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1");
            $lookup->execute([$categoryNameInput]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['status'] !== 'Active' && (int)$existing['id'] !== (int)$p['category_id']) {
                    throw new RuntimeException('That category is inactive. Activate it in Categories before using it.');
                }
                $categoryId = (int)$existing['id'];
                $categoryName = (string)$existing['category_name'];
            } else {
                if (!hasModulePermission('categories', 'create')) throw new RuntimeException('Your role cannot create categories. Ask an administrator for category creation access.');
                $create = $conn->prepare("INSERT INTO categories (category_name, description, status) VALUES (?, NULL, 'Active')");
                $create->execute([$categoryNameInput]);
                $categoryId = (int)$conn->lastInsertId();
                $categoryName = $categoryNameInput;
            }
        }

        if ($brandNameInput !== '') {
            if (strlen($brandNameInput) > 120) throw new RuntimeException('Brand or publisher name must be 120 characters or fewer.');
            $lookup = $conn->prepare("SELECT id, status FROM brands WHERE LOWER(brand_name) = LOWER(?) LIMIT 1");
            $lookup->execute([$brandNameInput]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['status'] !== 'Active' && (int)$existing['id'] !== (int)$p['brand_id']) {
                    throw new RuntimeException('That brand or publisher is inactive. Activate it in Brands before using it.');
                }
                $brandId = (int)$existing['id'];
            } else {
                if (!hasModulePermission('brands', 'create')) throw new RuntimeException('Your role cannot create brands or publishers. Ask an administrator for brand creation access.');
                $create = $conn->prepare("INSERT INTO brands (brand_name, description, status) VALUES (?, NULL, 'Active')");
                $create->execute([$brandNameInput]);
                $brandId = (int)$conn->lastInsertId();
            }
        }

        if ($supplierNameInput !== '') {
            if (strlen($supplierNameInput) > 160) throw new RuntimeException('Supplier name must be 160 characters or fewer.');
            $lookup = $conn->prepare("SELECT id, status FROM suppliers WHERE LOWER(supplier_name) = LOWER(?) LIMIT 1");
            $lookup->execute([$supplierNameInput]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['status'] !== 'Active' && (int)$existing['id'] !== (int)$p['supplier_id']) {
                    throw new RuntimeException('That supplier is inactive. Activate it in Suppliers before using it.');
                }
                $supplierId = (int)$existing['id'];
            } else {
                if (!hasModulePermission('suppliers', 'create')) throw new RuntimeException('Your role cannot create suppliers. Ask an administrator for supplier creation access.');
                $create = $conn->prepare(
                    "INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, tax_pin, credit_limit, balance, status, notes)
                     VALUES (?, NULL, NULL, NULL, NULL, NULL, 0, 0, 'Active', NULL)"
                );
                $create->execute([$supplierNameInput]);
                $supplierId = (int)$conn->lastInsertId();
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
                barcode = ?, unit = ?, purchase_unit = ?, units_per_purchase = ?, buying_price = ?, selling_price = ?,
                wholesale_price = ?, bulk_selling_price = ?, stock_quantity = ?, reorder_level = ?,
                icon = ?, image_path = ?, status = ?
             WHERE id = ?"
        );
        $update->execute([
            $name, $productType, $isbn, $categoryName, $categoryId ?: null,
            $brandId ?: null, $supplierId ?: null, $sku, $barcode, $unit, $purchaseUnit, $unitsPerPurchase,
            $buy, $sell, $wholesale, $bulkSellingPrice, $stock, $reorder, $icon, ($imagePath !== '' ? $imagePath : null), $status, $id
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
        if (($newProductImage || !empty($_POST['remove_product_image'])) && !empty($p['image_path'])) deleteProductImage((string)$p['image_path']);
        $_SESSION['product_flash'] = 'Product updated successfully.';
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        deleteProductImage($newProductImage);
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

$selectedCategoryName = '';
foreach ($categories as $categoryOption) {
    if ((int)$categoryOption['id'] === (int)$p['category_id']) { $selectedCategoryName = (string)$categoryOption['category_name']; break; }
}
$selectedBrandName = '';
foreach ($brands as $brandOption) {
    if ((int)$brandOption['id'] === (int)$p['brand_id']) { $selectedBrandName = (string)$brandOption['brand_name']; break; }
}
$selectedSupplierName = '';
foreach ($suppliers as $supplierOption) {
    if ((int)$supplierOption['id'] === (int)$p['supplier_id']) { $selectedSupplierName = (string)$supplierOption['supplier_name']; break; }
}

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

    <form method="post" enctype="multipart/form-data" class="panel" style="max-width:980px">
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
                <input name="sku" maxlength="80" value="<?= e($p['sku'] ?? '') ?>" placeholder="Generated automatically if left blank">
                <small>Leave blank to generate a unique SKU when you save.</small>
            </label>
            <label>Barcode
                <input name="barcode" maxlength="100" value="<?= e($p['barcode'] ?? '') ?>">
            </label>
            <label>Category
                <input name="category_name" list="editProductCategoryOptions" maxlength="120" value="<?= e($selectedCategoryName) ?>" placeholder="Type or choose a category" autocomplete="off">
                <datalist id="editProductCategoryOptions"><?php foreach ($categories as $category): ?><option value="<?= e($category['category_name']) ?>"><?php endforeach; ?></datalist>
                <small>Choose an existing category or type a new one.</small>
            </label>
            <label>Brand / Publisher
                <input name="brand_name" list="editProductBrandOptions" maxlength="120" value="<?= e($selectedBrandName) ?>" placeholder="Type or choose a brand / publisher" autocomplete="off">
                <datalist id="editProductBrandOptions"><?php foreach ($brands as $brand): ?><option value="<?= e($brand['brand_name']) ?>"><?php endforeach; ?></datalist>
                <small>Choose an existing brand or type a new one.</small>
            </label>
            <label>Preferred Supplier
                <input name="supplier_name" list="editProductSupplierOptions" maxlength="160" value="<?= e($selectedSupplierName) ?>" placeholder="Type or choose a supplier" autocomplete="off">
                <datalist id="editProductSupplierOptions"><?php foreach ($suppliers as $supplier): ?><option value="<?= e($supplier['supplier_name']) ?>"><?php endforeach; ?></datalist>
                <small>Choose an existing supplier or type a new one.</small>
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
            <label>Stock / Selling Unit
                <input name="unit" maxlength="50" value="<?= e($p['unit'] ?: 'Piece') ?>" required>
                <small>The smallest unit counted in stock.</small>
            </label>
            <label>Purchase Unit
                <input name="purchase_unit" maxlength="50" value="<?= e($p['purchase_unit'] ?? $p['unit']) ?>" placeholder="e.g. Carton">
            </label>
            <label>Units per Purchase Unit
                <input type="number" name="units_per_purchase" min="1" step="1" value="<?= e($p['units_per_purchase'] ?? 1) ?>" required>
                <small>Example: 50 pieces in one carton.</small>
            </label>
            <label>Bulk Selling Price (KES)
                <input type="number" name="bulk_selling_price" min="0" step=".01" value="<?= e($p['bulk_selling_price'] ?? '') ?>">
                <small>Optional price for one full purchase unit.</small>
            </label>
            <label>Product Icon
                <input name="icon" maxlength="20" value="<?= e($p['icon'] ?: '📦') ?>">
            </label>
            <label>Product Image (PNG or JPEG, max 2 MB)
                <input type="file" name="product_image" accept="image/png,image/jpeg">
                <small>Maximum dimensions: 2400 x 2400 pixels.</small>
            </label>
            <?php if (!empty($p['image_path'])): ?>
                <div>
                    <img src="<?= BASE_URL . '/' . e($p['image_path']) ?>" alt="Current product image" style="width:76px;height:76px;object-fit:cover;border-radius:8px">
                    <label style="display:block;margin-top:8px"><input type="checkbox" name="remove_product_image" value="1"> Remove current image</label>
                </div>
            <?php endif; ?>
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
