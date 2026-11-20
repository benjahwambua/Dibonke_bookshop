<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/product_helpers.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/product_image_upload.php';
$uploadedProductImage = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verify_csrf();
requireModulePermission('products', 'create');

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
    if ($buyRaw === '' || !is_numeric($buyRaw) || $sellRaw === '' || !is_numeric($sellRaw)) {
        throw new RuntimeException('Enter valid buying and selling prices.');
    }
    if ($wholesaleRaw !== '' && !is_numeric($wholesaleRaw)) {
        throw new RuntimeException('Enter a valid wholesale price or leave it blank.');
    }

    $buy = (float)$buyRaw;
    $sell = (float)$sellRaw;
    $wholesale = $wholesaleRaw === '' ? 0.0 : (float)$wholesaleRaw;

    $stockRaw = trim((string)($_POST['stock_quantity'] ?? '0'));
    $reorderRaw = trim((string)($_POST['reorder_level'] ?? '10'));
    if (!preg_match('/^\d+$/', $stockRaw) || !preg_match('/^\d+$/', $reorderRaw)) {
        throw new RuntimeException('Opening stock and reorder level must be whole, non-negative quantities.');
    }
    $stock = (int)$stockRaw;
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
    $bulkWholesaleRaw = trim((string)($_POST['bulk_wholesale_price'] ?? ''));
    $bulkWholesalePrice = $bulkWholesaleRaw === '' ? null : (float)$bulkWholesaleRaw;
    if ($unitsPerPurchase < 1 || ($purchaseUnit === '' && $unitsPerPurchase !== 1)) throw new RuntimeException('Enter a purchase unit and a valid units-per-purchase quantity.');
    if (($bulkSellingPrice !== null && $bulkSellingPrice < 0) || ($bulkWholesalePrice !== null && $bulkWholesalePrice < 0)) throw new RuntimeException('Bulk selling prices cannot be negative.');
    if ($purchaseUnit === '') { $purchaseUnit = $unit; $unitsPerPurchase = 1; }
    $icon = trim((string)($_POST['icon'] ?? '📦')) ?: '📦';
    $uploadedProductImage = uploadProductImage();

    $conn->beginTransaction();

    // Generate a readable SKU only when the user has not supplied one.
    $sku = $skuInput !== '' ? $skuInput : generateProductSku($conn, $productType, $name);
    $skuCheck = $conn->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
    $skuCheck->execute([$sku]);
    if ($skuCheck->fetchColumn()) {
        throw new RuntimeException('That SKU is already in use. Enter a different SKU or leave the field blank for automatic generation.');
    }

    // Resolve typed category and publisher names. New master records are created
    // in the same transaction as the product so they never become orphaned.
    $categoryName = null;
    if ($categoryNameInput !== '') {
        if (strlen($categoryNameInput) > 120) {
            throw new RuntimeException('Category name must be 120 characters or fewer.');
        }
        $lookup = $conn->prepare("SELECT id, category_name, status FROM categories WHERE LOWER(category_name) = LOWER(?) LIMIT 1");
        $lookup->execute([$categoryNameInput]);
        $existingCategory = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($existingCategory) {
            if ($existingCategory['status'] !== 'Active') {
                throw new RuntimeException('That category is inactive. Activate it in Categories before using it.');
            }
            $categoryId = (int)$existingCategory['id'];
            $categoryName = (string)$existingCategory['category_name'];
        } else {
            if (!hasModulePermission('categories', 'create')) {
                throw new RuntimeException('You can add products, but your role cannot create categories. Ask an administrator to grant category creation access.');
            }
            $createCategory = $conn->prepare("INSERT INTO categories (category_name, description, status) VALUES (?, NULL, 'Active')");
            $createCategory->execute([$categoryNameInput]);
            $categoryId = (int)$conn->lastInsertId();
            $categoryName = $categoryNameInput;
        }
    }

    if ($brandNameInput !== '') {
        if (strlen($brandNameInput) > 120) {
            throw new RuntimeException('Brand or publisher name must be 120 characters or fewer.');
        }
        $lookup = $conn->prepare("SELECT id, brand_name, status FROM brands WHERE LOWER(brand_name) = LOWER(?) LIMIT 1");
        $lookup->execute([$brandNameInput]);
        $existingBrand = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($existingBrand) {
            if ($existingBrand['status'] !== 'Active') {
                throw new RuntimeException('That brand or publisher is inactive. Activate it in Brands before using it.');
            }
            $brandId = (int)$existingBrand['id'];
        } else {
            if (!hasModulePermission('brands', 'create')) {
                throw new RuntimeException('You can add products, but your role cannot create brands or publishers. Ask an administrator to grant brand creation access.');
            }
            $createBrand = $conn->prepare("INSERT INTO brands (brand_name, description, status) VALUES (?, NULL, 'Active')");
            $createBrand->execute([$brandNameInput]);
            $brandId = (int)$conn->lastInsertId();
        }
    }

    if ($supplierNameInput !== '') {
        if (strlen($supplierNameInput) > 160) {
            throw new RuntimeException('Supplier name must be 160 characters or fewer.');
        }
        $lookup = $conn->prepare("SELECT id, status FROM suppliers WHERE LOWER(supplier_name) = LOWER(?) LIMIT 1");
        $lookup->execute([$supplierNameInput]);
        $existingSupplier = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($existingSupplier) {
            if ($existingSupplier['status'] !== 'Active') {
                throw new RuntimeException('That supplier is inactive. Activate it in Suppliers before using it.');
            }
            $supplierId = (int)$existingSupplier['id'];
        } else {
            if (!hasModulePermission('suppliers', 'create')) {
                throw new RuntimeException('You can add products, but your role cannot create suppliers. Ask an administrator to grant supplier creation access.');
            }
            $createSupplier = $conn->prepare(
                "INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, tax_pin, credit_limit, balance, status, notes)
                 VALUES (?, NULL, NULL, NULL, NULL, NULL, 0, 0, 'Active', NULL)"
            );
            $createSupplier->execute([$supplierNameInput]);
            $supplierId = (int)$conn->lastInsertId();
        }
    }

    // The product catalogue and POS both depend on an active standard price list.
    $priceListStmt = $conn->prepare("SELECT id FROM price_lists WHERE code = 'normal' AND status = 'Active' LIMIT 1");
    $priceListStmt->execute();
    $normalPriceListId = (int)$priceListStmt->fetchColumn();
    if ($normalPriceListId <= 0) {
        throw new RuntimeException('The Standard price list is missing. Complete the price-list database setup before adding products.');
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

    $insert = $conn->prepare(
        "INSERT INTO products (
            product_name, product_type, isbn, category, category_id,
            brand_id, supplier_id, sku, barcode, unit, purchase_unit, units_per_purchase, buying_price,
            selling_price, wholesale_price, bulk_selling_price, bulk_wholesale_price, stock_quantity, reorder_level,
            icon, image_path, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')"
    );
    $insert->execute([
        $name, $productType, $isbn, $categoryName, $categoryId ?: null,
        $brandId ?: null, $supplierId ?: null, $sku, $barcode, $unit, $purchaseUnit, $unitsPerPurchase,
        $buy, $sell, $wholesale, $bulkSellingPrice, $bulkWholesalePrice, $stock, $reorder, $icon, $uploadedProductImage
    ]);
    $productId = (int)$conn->lastInsertId();

    $priceInsert = $conn->prepare(
        "INSERT INTO product_prices (price_list_id, product_id, min_quantity, price)
         VALUES (?, ?, 1, ?)
         ON DUPLICATE KEY UPDATE price = VALUES(price)"
    );
    $priceInsert->execute([$normalPriceListId, $productId, $sell]);
    if ($wholesalePriceListId > 0) {
        $priceInsert->execute([$wholesalePriceListId, $productId, $wholesale]);
    }

    // Opening stock is posted to both the product balance and the stock ledger.
    if ($stock > 0) {
        $movement = $conn->prepare(
            "INSERT INTO stock_movements (
                product_id, user_id, movement_type, quantity, stock_before,
                stock_after, reference_type, reference_id, notes, movement_date
            ) VALUES (?, ?, 'Opening Stock', ?, 0, ?, 'product', ?, ?, ?)"
        );
        $movement->execute([
            $productId,
            (int)$_SESSION['user_id'],
            $stock,
            $stock,
            $productId,
            'Opening stock recorded when product was created',
            date('Y-m-d H:i:s')
        ]);
    }

    recordAuditEvent($conn,'Create','products','Created product '.$name,'product',$productId,null,['product_type'=>$productType,'sku'=>$sku,'barcode'=>$barcode,'opening_stock'=>$stock]);
    $conn->commit();
    $_SESSION['product_flash'] = 'Product created successfully.';
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    deleteProductImage($uploadedProductImage);

    if ($e instanceof PDOException && $e->getCode() === '23000' && strpos($e->getMessage(), 'uq_products_isbn') !== false) {
        $_SESSION['product_flash'] = 'That ISBN is already assigned to another product.';
    } else {
        error_log('Dibonke product creation failed: ' . $e->getMessage());
        $_SESSION['product_flash'] = $e instanceof RuntimeException
            ? 'Product could not be saved: ' . $e->getMessage()
            : 'Product could not be saved. Check the database setup and application error log.';
    }
}

header('Location: index.php');
exit;
