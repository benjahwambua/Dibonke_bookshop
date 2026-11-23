<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verify_csrf();

function storeFail(string $message): void
{
    $_SESSION['store_operations_flash'] = $message;
    header('Location: index.php');
    exit;
}

try {
    $action = (string)($_POST['action'] ?? '');
    $userId = (int)$_SESSION['user_id'];
    $requiredPermission = in_array($action, ['rename_store', 'toggle_store_status'], true) ? 'edit' : 'create';
    requireModulePermission('store_operations', $requiredPermission);

    if ($action === 'create_store') {
        $name = trim((string)($_POST['store_name'] ?? ''));
        $code = strtoupper(trim((string)($_POST['store_code'] ?? '')));
        if ($name === '' || $code === '' || !preg_match('/^[A-Z0-9_-]{2,30}$/', $code)) {
            throw new RuntimeException('Enter a store name and a valid store code (2–30 letters or numbers).');
        }
        $stmt = $conn->prepare("INSERT INTO stores (store_code,store_name,store_type,status,created_by) VALUES (?,?, 'Selling','Active',?)");
        $stmt->execute([$code, $name, $userId]);
        recordAuditEvent($conn, 'Create', 'store_operations', 'Created selling store ' . $name, 'store', (int)$conn->lastInsertId());
        storeFail('Selling store created successfully.');
    }


    if ($action === 'rename_store') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $name = trim((string)($_POST['store_name'] ?? ''));
        if ($storeId <= 0 || $name === '' || mb_strlen($name) > 120) {
            throw new RuntimeException('Enter a valid store name (up to 120 characters).');
        }
        $stmt = $conn->prepare("SELECT id,store_name,store_type FROM stores WHERE id=? LIMIT 1");
        $stmt->execute([$storeId]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) throw new RuntimeException('The selected store could not be found.');
        if ($store['store_name'] === $name) storeFail('No changes were needed.');
        $update = $conn->prepare("UPDATE stores SET store_name=? WHERE id=?");
        $update->execute([$name, $storeId]);
        recordAuditEvent($conn, 'Rename', 'store_operations', 'Renamed ' . $store['store_type'] . ' store from ' . $store['store_name'] . ' to ' . $name, 'store', $storeId);
        storeFail('Store name updated. All previous records remain linked to this store.');
    }

    if ($action === 'toggle_store_status') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        if ($storeId <= 0) throw new RuntimeException('Select a valid store.');
        $conn->beginTransaction();
        $stmt = $conn->prepare("SELECT id,store_name,store_type,status FROM stores WHERE id=? FOR UPDATE");
        $stmt->execute([$storeId]);
        $store = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) throw new RuntimeException('The selected store could not be found.');
        if ($store['store_type'] === 'Main') throw new RuntimeException('The Main Store cannot be deactivated.');
        if ($store['status'] === 'Active') {
            $open = $conn->prepare("SELECT COUNT(*) FROM store_daily_sessions WHERE store_id=? AND status='Open'");
            $open->execute([$storeId]);
            if ((int)$open->fetchColumn() > 0) throw new RuntimeException('Reconcile all open daily records before deactivating this store.');
            $stock = $conn->prepare("SELECT COALESCE(SUM(quantity),0) FROM store_stock WHERE store_id=?");
            $stock->execute([$storeId]);
            if ((float)$stock->fetchColumn() > 0.0001) throw new RuntimeException('Return all remaining store stock to Main Store before deactivating this store.');
            $newStatus = 'Inactive';
        } else {
            $newStatus = 'Active';
        }
        $update = $conn->prepare("UPDATE stores SET status=? WHERE id=?");
        $update->execute([$newStatus, $storeId]);
        recordAuditEvent($conn, $newStatus === 'Active' ? 'Reactivate' : 'Deactivate', 'store_operations', $newStatus . ' store ' . $store['store_name'], 'store', $storeId);
        $conn->commit();
        storeFail('Store ' . strtolower($newStatus) . ' successfully. Historical records have been retained.');
    }

    if ($action === 'issue_stock') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $person = trim((string)($_POST['responsible_person'] ?? ''));
        $quantities = $_POST['quantity'] ?? [];
        if ($storeId <= 0 || $person === '' || strlen($person) > 160 || !is_array($quantities)) {
            throw new RuntimeException('Select a selling store and enter the person taking charge.');
        }

        $conn->beginTransaction();
        $storeStmt = $conn->prepare("SELECT id,store_name FROM stores WHERE id=? AND store_type='Selling' AND status='Active' FOR UPDATE");
        $storeStmt->execute([$storeId]);
        $store = $storeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$store) throw new RuntimeException('The selected selling store is unavailable.');

        $today = date('Y-m-d');
        $unclosed = $conn->prepare("SELECT business_date FROM store_daily_sessions WHERE store_id=? AND status='Open' AND business_date<? ORDER BY business_date LIMIT 1 FOR UPDATE");
        $unclosed->execute([$storeId, $today]);
        $unclosedDate = $unclosed->fetchColumn();
        if ($unclosedDate) throw new RuntimeException('The previous daily record for ' . $store['store_name'] . ' (' . $unclosedDate . ') must be reconciled before issuing fresh stock.');
        $existing = $conn->prepare("SELECT id FROM store_daily_sessions WHERE store_id=? AND business_date=? FOR UPDATE");
        $existing->execute([$storeId, $today]);
        if ($existing->fetchColumn()) throw new RuntimeException('A daily issue already exists for this store today. Open its daily record instead.');

        $mainId = (int)$conn->query("SELECT id FROM stores WHERE store_code='MAIN' AND status='Active' LIMIT 1")->fetchColumn();
        if ($mainId <= 0) throw new RuntimeException('Main Store is not configured.');

        $selected = [];
        foreach ($quantities as $productId => $rawQty) {
            $qtyText = trim((string)$rawQty);
            if ($qtyText === '' || !is_numeric($qtyText)) continue;
            $qty = (float)$qtyText;
            if ($qty < 0) throw new RuntimeException('Stock quantities cannot be negative.');
            if ($qty > 0) $selected[(int)$productId] = $qty;
        }
        if (!$selected) throw new RuntimeException('Enter at least one quantity to issue.');

        $number = 'ISS-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $transfer = $conn->prepare("INSERT INTO store_transfers (transfer_number,transfer_type,from_store_id,to_store_id,transfer_date,responsible_person,created_by) VALUES (?,'Issue',?,?,NOW(),?,?)");
        $transfer->execute([$number, $mainId, $storeId, $person, $userId]);
        $transferId = (int)$conn->lastInsertId();

        $session = $conn->prepare("INSERT INTO store_daily_sessions (store_id,business_date,responsible_person,status,issue_transfer_id,created_by) VALUES (?,?,?,'Open',?,?)");
        $session->execute([$storeId, $today, $person, $transferId, $userId]);
        $sessionId = (int)$conn->lastInsertId();

        $productLock = $conn->prepare("SELECT id,product_name,stock_quantity,buying_price FROM products WHERE id=? AND status='Active' FOR UPDATE");
        $productUpdate = $conn->prepare("UPDATE products SET stock_quantity=stock_quantity-? WHERE id=?");
        $storeUpsert = $conn->prepare("INSERT INTO store_stock (store_id,product_id,quantity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity)");
        $transferItem = $conn->prepare("INSERT INTO store_transfer_items (transfer_id,product_id,quantity,unit_cost) VALUES (?,?,?,?)");
        $dailyItem = $conn->prepare("INSERT INTO store_daily_items (session_id,product_id,quantity_issued) VALUES (?,?,?)");

        foreach ($selected as $productId => $qty) {
            $productLock->execute([$productId]);
            $product = $productLock->fetch(PDO::FETCH_ASSOC);
            if (!$product) throw new RuntimeException('A selected product is missing or inactive.');
            if ((float)$product['stock_quantity'] < $qty) {
                throw new RuntimeException('Insufficient Main Store stock for ' . $product['product_name'] . '. Available: ' . $product['stock_quantity'] . '.');
            }
            $productUpdate->execute([$qty, $productId]);
            $storeUpsert->execute([$storeId, $productId, $qty]);
            $transferItem->execute([$transferId, $productId, $qty, (float)$product['buying_price']]);
            $dailyItem->execute([$sessionId, $productId, $qty]);
        }

        recordAuditEvent($conn, 'Issue Stock', 'store_operations', 'Issued stock to ' . $store['store_name'] . ' for ' . $today, 'store_daily_session', $sessionId);
        $conn->commit();
        storeFail('Stock issued to ' . $store['store_name'] . '. Daily record opened for ' . $person . '.');
    }

    if ($action === 'reconcile_day') {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $returns = $_POST['returned'] ?? [];
        $cash = (float)($_POST['cash_received'] ?? 0);
        $mpesa = (float)($_POST['mpesa_received'] ?? 0);
        $bank = (float)($_POST['bank_received'] ?? 0);
        $other = (float)($_POST['other_received'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($sessionId <= 0 || !is_array($returns) || min($cash,$mpesa,$bank,$other) < 0) {
            throw new RuntimeException('Enter valid returned quantities and collection amounts.');
        }

        $conn->beginTransaction();
        $sessionStmt = $conn->prepare("SELECT ds.*,s.store_name FROM store_daily_sessions ds INNER JOIN stores s ON s.id=ds.store_id WHERE ds.id=? FOR UPDATE");
        $sessionStmt->execute([$sessionId]);
        $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
        if (!$session || $session['status'] !== 'Open') throw new RuntimeException('This daily record is not open for reconciliation.');

        $itemsStmt = $conn->prepare("SELECT di.*,p.product_name,p.buying_price FROM store_daily_items di INNER JOIN products p ON p.id=di.product_id WHERE di.session_id=? FOR UPDATE");
        $itemsStmt->execute([$sessionId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) throw new RuntimeException('No issued products were found for this daily record.');

        $returnTotal = 0.0;
        $soldStmt = $conn->prepare("SELECT si.product_id,COALESCE(SUM(si.quantity),0) sold_quantity,COALESCE(SUM(si.subtotal),0) sales_amount
            FROM sale_items si
            INNER JOIN sales s ON s.id=si.sale_id
            WHERE s.store_id=? AND s.sale_status='Completed' AND DATE(s.sale_date)=?
            GROUP BY si.product_id");
        $soldStmt->execute([(int)$session['store_id'], $session['business_date']]);
        $soldRows = $soldStmt->fetchAll(PDO::FETCH_ASSOC);
        $soldByProduct = [];
        foreach ($soldRows as $soldRow) $soldByProduct[(int)$soldRow['product_id']] = ['quantity'=>(float)$soldRow['sold_quantity'],'amount'=>(float)$soldRow['sales_amount']];

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $qty = isset($returns[$productId]) && is_numeric($returns[$productId]) ? (float)$returns[$productId] : 0.0;
            $sold = (float)($soldByProduct[$productId]['quantity'] ?? 0);
            if ($qty < 0 || $qty > (float)$item['quantity_issued']) {
                throw new RuntimeException('Returned quantity for ' . $item['product_name'] . ' must be between zero and the quantity issued.');
            }
            if ($sold > (float)$item['quantity_issued'] - $qty + 0.0001) {
                throw new RuntimeException('Recorded POS sales for ' . $item['product_name'] . ' exceed the issued stock after returns. Check the store issue and sales records.');
            }
            $returnTotal += $qty;
        }

        $returnTransferId = null;
        if ($returnTotal > 0) {
            $number = 'RET-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $mainId = (int)$conn->query("SELECT id FROM stores WHERE store_code='MAIN' LIMIT 1")->fetchColumn();
            $transfer = $conn->prepare("INSERT INTO store_transfers (transfer_number,transfer_type,from_store_id,to_store_id,transfer_date,responsible_person,created_by,notes) VALUES (?,'Return',?,?,NOW(),?,?,?)");
            $transfer->execute([$number, (int)$session['store_id'], $mainId, $session['responsible_person'], (int)$_SESSION['user_id'], 'End-of-day return']);
            $returnTransferId = (int)$conn->lastInsertId();
        }

        $stockLock = $conn->prepare("SELECT quantity FROM store_stock WHERE store_id=? AND product_id=? FOR UPDATE");
        $stockDown = $conn->prepare("UPDATE store_stock SET quantity=quantity-? WHERE store_id=? AND product_id=? AND quantity>=?");
        $mainUp = $conn->prepare("UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?");
        $transferItem = $conn->prepare("INSERT INTO store_transfer_items (transfer_id,product_id,quantity,unit_cost) VALUES (?,?,?,?)");
        $dailyUpdate = $conn->prepare("UPDATE store_daily_items SET quantity_returned=?,quantity_sold=?,quantity_variance=?,expected_amount=? WHERE id=?");

        foreach ($items as $item) {
            $productId = (int)$item['product_id'];
            $issued = (float)$item['quantity_issued'];
            $returned = isset($returns[$productId]) && is_numeric($returns[$productId]) ? (float)$returns[$productId] : 0.0;
            $sold = (float)($soldByProduct[$productId]['quantity'] ?? 0);
            $stockVariance = $issued - $returned - $sold;
            $stockLock->execute([(int)$session['store_id'], $productId]);
            $available = $stockLock->fetchColumn();
            if ($available === false || (float)$available < ($returned + $stockVariance) - 0.0001) {
                throw new RuntimeException('The store stock balance does not match the issue, sales and returns for ' . $item['product_name'] . '.');
            }
            if ($returned > 0) {
                $stockDown->execute([$returned, (int)$session['store_id'], $productId, $returned]);
                if ($stockDown->rowCount() !== 1) throw new RuntimeException('Could not process returned stock for ' . $item['product_name'] . '.');
                $mainUp->execute([$returned, $productId]);
                $transferItem->execute([$returnTransferId, $productId, $returned, (float)$item['buying_price']]);
            }
            if ($stockVariance > 0) {
                $stockDown->execute([$stockVariance, (int)$session['store_id'], $productId, $stockVariance]);
                if ($stockDown->rowCount() !== 1) throw new RuntimeException('Could not record the stock variance for ' . $item['product_name'] . '.');
            }
            $expectedLineAmount = (float)($soldByProduct[$productId]['amount'] ?? 0);
            $dailyUpdate->execute([$returned, $sold, $stockVariance, $expectedLineAmount, (int)$item['id']]);
        }

        $received = round($cash + $mpesa + $bank + $other, 2);
        $salesStmt = $conn->prepare("SELECT COALESCE(SUM(paid_amount),0) FROM sales WHERE store_id=? AND sale_status='Completed' AND DATE(sale_date)=?");
        $salesStmt->execute([(int)$session['store_id'], $session['business_date']]);
        $expected = round((float)$salesStmt->fetchColumn(), 2);
        $variance = round($received - $expected, 2);

        $update = $conn->prepare("UPDATE store_daily_sessions SET status='Reconciled',return_transfer_id=?,expected_sales=?,cash_received=?,mpesa_received=?,bank_received=?,other_received=?,total_received=?,money_variance=?,notes=?,reconciled_by=?,reconciled_at=NOW() WHERE id=?");
        $update->execute([$returnTransferId, $expected, $cash, $mpesa, $bank, $other, $received, $variance, $notes ?: null, (int)$_SESSION['user_id'], $sessionId]);

        recordAuditEvent($conn, 'Reconcile', 'store_operations', 'Reconciled ' . $session['store_name'] . ' for ' . $session['business_date'], 'store_daily_session', $sessionId);
        $conn->commit();
        storeFail('Daily reconciliation completed. Money variance: KES ' . number_format($variance, 2) . '.');
    }

    throw new RuntimeException('Invalid Store Operations action.');
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('Dibonke Store Operations error: ' . $e->getMessage());
    storeFail($e instanceof RuntimeException ? $e->getMessage() : 'The operation could not be completed. Check the application error log.');
}
