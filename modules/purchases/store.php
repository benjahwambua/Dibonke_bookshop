<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/accounting_posting.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verify_csrf();
requireModulePermission('purchases','create');

try {
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseDate = trim((string)($_POST['purchase_date'] ?? ''));
    $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Credit'));
    $paidAmount = (float)($_POST['paid_amount'] ?? 0);
    $discount = (float)($_POST['discount'] ?? 0);
    $tax = (float)($_POST['tax'] ?? 0);
    $notes = trim((string)($_POST['notes'] ?? ''));
    $items = $_POST['items'] ?? [];

    if ($supplierId <= 0) throw new RuntimeException('Please select a supplier.');
    if ($purchaseDate === '') $purchaseDate = date('Y-m-d H:i:s');
    else $purchaseDate = date('Y-m-d H:i:s', strtotime($purchaseDate));
    if ($paidAmount < 0 || $discount < 0 || $tax < 0) throw new RuntimeException('Payment, discount and tax cannot be negative.');
    if (!in_array($paymentMethod, ['Credit','Cash','M-Pesa','Bank'], true)) throw new RuntimeException('Invalid payment method.');
    if ($paidAmount > 0 && $paymentMethod === 'Credit') throw new RuntimeException('Select the actual payment method for a paid amount, or use Credit when no amount is paid.');
    if (!is_array($items) || !$items) throw new RuntimeException('Add at least one purchase item.');

    $cleanItems = [];
    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $qty = (int)($item['quantity'] ?? 0);
        $price = (float)($item['buying_price'] ?? 0);
        $unitMode = (string)($item['unit_mode'] ?? 'base');
        if ($productId <= 0 || $qty <= 0) throw new RuntimeException('Each purchase item must have a valid product and quantity.');
        if ($price < 0) throw new RuntimeException('Buying price cannot be negative.');
        if (!in_array($unitMode, ['base','bulk'], true)) throw new RuntimeException('Invalid purchase unit selected.');
        $cleanItems[] = ['product_id'=>$productId, 'entered_quantity'=>$qty, 'entered_unit_price'=>$price, 'unit_mode'=>$unitMode];
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT id, supplier_name FROM suppliers WHERE id=? AND status='Active' FOR UPDATE");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch();
    if (!$supplier) throw new RuntimeException('Supplier not found or inactive.');

    $subtotal = 0.0;
    foreach ($cleanItems as &$item) {
        $stmt = $conn->prepare("SELECT id, product_name, stock_quantity, unit, purchase_unit, units_per_purchase FROM products WHERE id=? AND status='Active' FOR UPDATE");
        $stmt->execute([$item['product_id']]);
        $product = $stmt->fetch();
        if (!$product) throw new RuntimeException('One of the selected products is missing or inactive.');
        $item['product_name'] = $product['product_name'];
        $item['stock_before'] = (int)$product['stock_quantity'];
        $factor = $item['unit_mode'] === 'bulk' ? max(1, (int)$product['units_per_purchase']) : 1;
        if ($item['unit_mode'] === 'bulk' && $factor <= 1) throw new RuntimeException('This product has no bulk purchase conversion configured.');
        $item['quantity'] = $item['entered_quantity'] * $factor;
        $item['conversion_factor'] = $factor;
        $item['purchase_unit'] = $item['unit_mode'] === 'bulk' ? (string)$product['purchase_unit'] : (string)$product['unit'];
        $item['buying_price'] = round($item['entered_unit_price'] / $factor, 4);
        $item['line_total'] = round($item['entered_quantity'] * $item['entered_unit_price'], 2);
        $subtotal += $item['line_total'];
    }
    unset($item);

    $total = round(max(0, $subtotal - $discount + $tax), 2);
    if ($paidAmount > $total) throw new RuntimeException('Paid amount cannot exceed the purchase total.');
    $balance = round($total - $paidAmount, 2);

    $purchaseNumber = 'TMP-' . strtoupper(bin2hex(random_bytes(8)));

    $stmt = $conn->prepare("INSERT INTO purchases (purchase_number,supplier_id,user_id,purchase_date,subtotal,discount,tax,total_amount,paid_amount,balance,payment_method,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Received',?)");
    $stmt->execute([
        $purchaseNumber,$supplierId,(int)$_SESSION['user_id'],$purchaseDate,
        $subtotal,$discount,$tax,$total,$paidAmount,$balance,$paymentMethod,$notes ?: null
    ]);
    $purchaseId = (int)$conn->lastInsertId();$purchaseNumber='PUR-'.date('ymd').'-'.str_pad((string)$purchaseId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE purchases SET purchase_number=? WHERE id=?")->execute([$purchaseNumber,$purchaseId]);

    $itemStmt = $conn->prepare("INSERT INTO purchase_items (purchase_id,product_id,quantity,buying_price,subtotal,entered_quantity,purchase_unit,conversion_factor,entered_unit_price) VALUES (?,?,?,?,?,?,?,?,?)");
    $stockStmt = $conn->prepare("UPDATE products SET stock_quantity=stock_quantity+?, buying_price=? WHERE id=?");
    $movementStmt = $conn->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,notes,movement_date) VALUES (?,?,?,?,?,?,?,?,?,?)");

    $runningStock = [];
    foreach ($cleanItems as $item) {
        $stockBefore = $runningStock[$item['product_id']] ?? $item['stock_before'];
        $stockAfter = $stockBefore + $item['quantity'];
        $runningStock[$item['product_id']] = $stockAfter;
        $itemStmt->execute([$purchaseId,$item['product_id'],$item['quantity'],$item['buying_price'],$item['line_total'],$item['entered_quantity'],$item['purchase_unit'],$item['conversion_factor'],$item['entered_unit_price']]);
        $stockStmt->execute([$item['quantity'],$item['buying_price'],$item['product_id']]);
        $movementStmt->execute([
            $item['product_id'],(int)$_SESSION['user_id'],'Purchase',$item['quantity'],
            $stockBefore,$stockAfter,'purchase',$purchaseId,
            'Purchase '.$purchaseNumber.' - '.$item['product_name'],$purchaseDate
        ]);
    }

    if ($balance > 0) {
        $stmt = $conn->prepare("UPDATE suppliers SET balance=balance+? WHERE id=?");
        $stmt->execute([$balance,$supplierId]);
    }

    // Record any amount paid at purchase time in the supplier payment ledger.
    if ($paidAmount > 0) {
        $paymentReceipt = 'TMP-' . strtoupper(bin2hex(random_bytes(8)));
        $hasCashShiftColumn=(bool)$conn->query("SHOW COLUMNS FROM supplier_payments LIKE 'cash_shift_id'")->fetch();
        $cashShiftId=null;
        if($paymentMethod==='Cash'&&$hasCashShiftColumn){$shiftQ=$conn->prepare("SELECT id FROM cash_shifts WHERE user_id=? AND status='Open' ORDER BY id DESC LIMIT 1");$shiftQ->execute([(int)$_SESSION['user_id']]);$cashShiftId=$shiftQ->fetchColumn()?:null;if(!$cashShiftId&&hasModulePermission('cash_management','create'))throw new RuntimeException('Open your cashier shift before recording a cash purchase payment.');}
        $paymentDate=($paymentMethod==='Cash'&&$cashShiftId)?date('Y-m-d H:i:s'):$purchaseDate;
        if($hasCashShiftColumn){
            $paymentStmt = $conn->prepare("INSERT INTO supplier_payments (receipt_number,supplier_id,goods_receipt_id,user_id,amount,payment_method,transaction_reference,payment_date,notes,cash_shift_id) VALUES (?,?,NULL,?,?,?,?,?,?,?)");
            $paymentStmt->execute([$paymentReceipt,$supplierId,(int)$_SESSION['user_id'],$paidAmount,$paymentMethod,null,$paymentDate,'Payment recorded against purchase '.$purchaseNumber,$cashShiftId]);
        }else{
            $paymentStmt = $conn->prepare("INSERT INTO supplier_payments (receipt_number,supplier_id,goods_receipt_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES (?,?,NULL,?,?,?,?,?,?)");
            $paymentStmt->execute([$paymentReceipt,$supplierId,(int)$_SESSION['user_id'],$paidAmount,$paymentMethod,null,$paymentDate,'Payment recorded against purchase '.$purchaseNumber]);
        }
        $supplierPaymentId=(int)$conn->lastInsertId();$paymentReceipt='SP-'.date('ymd').'-'.str_pad((string)$supplierPaymentId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE supplier_payments SET receipt_number=? WHERE id=?")->execute([$paymentReceipt,$supplierPaymentId]);if($paidAmount>0){$conn->prepare("INSERT INTO supplier_payment_allocations(payment_id,purchase_id,amount) VALUES(?,?,?)")->execute([$supplierPaymentId,$purchaseId,$paidAmount]);}
    }

    recordAuditEvent($conn,'Create','purchases','Created purchase '.$purchaseNumber.' for KES '.number_format($total,2),'purchase',$purchaseId,null,['supplier_id'=>$supplierId,'total_amount'=>$total,'paid_amount'=>$paidAmount,'balance'=>$balance]);
    if(dibonkeAccountingAutoPostingEnabled($conn)){
        $journalLines=[];
        $inventoryCost=round($subtotal-$discount,2);
        if($inventoryCost<0)throw new RuntimeException('Purchase discount cannot exceed the item subtotal when automatic accounting is enabled.');
        if($inventoryCost>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'inventory','Asset'),'Goods purchased into inventory',$inventoryCost,0);
        if($tax>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'input_vat','Asset'),'Recoverable input VAT',$tax,0);
        if($paidAmount>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingPaymentAccount($conn,$paymentMethod),'Purchase payment made',$paidAmount,0);
        if($balance>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'accounts_payable','Liability'),'Supplier balance due',0,$balance);
        dibonkeAccountingPostJournal($conn,'purchase',$purchaseId,date('Y-m-d',strtotime($purchaseDate)),'Purchase '.$purchaseNumber,$journalLines,(int)$_SESSION['user_id']);
    }
    $conn->commit();

    $_SESSION['purchase_flash'] = 'Purchase '.$purchaseNumber.' completed successfully. Stock has been updated.';
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('Purchase error: '.$e->getMessage());
    $_SESSION['purchase_flash'] = 'Purchase could not be completed: '.$e->getMessage();
}

header('Location: index.php');
exit;
