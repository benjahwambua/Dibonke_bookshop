<?php
declare(strict_types=1);

/**
 * Allocate a customer receipt to oldest outstanding completed invoices.
 * Caller must have inserted the payment row and opened a transaction.
 */
function dibonkeAllocateCustomerPayment(PDO $db, int $customerId, int $paymentId, float $amount): float
{
    if (!$db->inTransaction() || $customerId <= 0 || $paymentId <= 0 || $amount <= 0) {
        throw new LogicException('Customer payment allocation requires an active transaction and valid references.');
    }
    $q = $db->prepare("SELECT id, balance FROM sales WHERE customer_id=? AND sale_status='Completed' AND balance>0.009 ORDER BY sale_date ASC, id ASC FOR UPDATE");
    $q->execute([$customerId]);
    $invoices = $q->fetchAll(PDO::FETCH_ASSOC);
    $available = array_sum(array_map(static fn($r) => (float)$r['balance'], $invoices));
    if ($amount > $available + 0.009) {
        throw new RuntimeException('The receipt exceeds the customer\'s outstanding invoice balances. Reconcile the customer account before posting this payment.');
    }
    $remaining = round($amount, 2);
    $insert = $db->prepare("INSERT INTO customer_payment_allocations(payment_id,sale_id,amount) VALUES(?,?,?)");
    $update = $db->prepare("UPDATE sales SET paid_amount=LEAST(total_amount,paid_amount+?), balance=GREATEST(0,balance-?) WHERE id=?");
    foreach ($invoices as $invoice) {
        if ($remaining <= 0.009) break;
        $allocated = round(min($remaining, (float)$invoice['balance']), 2);
        if ($allocated <= 0) continue;
        $insert->execute([$paymentId, (int)$invoice['id'], $allocated]);
        $update->execute([$allocated, $allocated, (int)$invoice['id']]);
        $remaining = round($remaining - $allocated, 2);
    }
    if ($remaining > 0.009) throw new RuntimeException('The receipt could not be fully allocated.');
    $db->prepare("UPDATE customers SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$amount, $customerId]);
    return round($amount, 2);
}

/**
 * Allocate a supplier payment FIFO across received purchases and goods receipts.
 * Caller must have locked the supplier row, inserted the payment row and opened a transaction.
 */
function dibonkeAllocateSupplierPayment(PDO $db, int $supplierId, int $paymentId, float $amount): float
{
    if (!$db->inTransaction() || $supplierId <= 0 || $paymentId <= 0 || $amount <= 0) {
        throw new LogicException('Supplier payment allocation requires an active transaction and valid references.');
    }
    $items = [];
    $p = $db->prepare("SELECT id, purchase_date document_date, total_amount, paid_amount, balance FROM purchases WHERE supplier_id=? AND status='Received' AND balance>0.009 ORDER BY purchase_date ASC, id ASC FOR UPDATE");
    $p->execute([$supplierId]);
    foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = ['type'=>'purchase','id'=>(int)$row['id'],'date'=>(string)$row['document_date'],'balance'=>(float)$row['balance']];
    }

    $g = $db->prepare("SELECT id, received_date document_date, total_amount FROM goods_receipts WHERE supplier_id=? ORDER BY received_date ASC, id ASC FOR UPDATE");
    $g->execute([$supplierId]);
    $grnAllocation = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM supplier_payment_allocations WHERE goods_receipt_id=?");
    foreach ($g->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grnAllocation->execute([(int)$row['id']]);
        $outstanding = round((float)$row['total_amount']-(float)$grnAllocation->fetchColumn(),2);
        if ($outstanding > 0.009) $items[] = ['type'=>'grn','id'=>(int)$row['id'],'date'=>(string)$row['document_date'],'balance'=>$outstanding];
    }
    usort($items, static fn($a,$b) => [$a['date'],$a['type'],$a['id']] <=> [$b['date'],$b['type'],$b['id']]);
    $available = array_sum(array_column($items,'balance'));
    if ($amount > $available + 0.009) {
        throw new RuntimeException('The payment exceeds outstanding supplier purchases and goods receipts. Reconcile the supplier account before posting this payment.');
    }

    $remaining = round($amount,2);
    $insertPurchase = $db->prepare("INSERT INTO supplier_payment_allocations(payment_id,purchase_id,goods_receipt_id,amount) VALUES(?,?,NULL,?)");
    $insertGrn = $db->prepare("INSERT INTO supplier_payment_allocations(payment_id,purchase_id,goods_receipt_id,amount) VALUES(?,NULL,?,?)");
    $updatePurchase = $db->prepare("UPDATE purchases SET paid_amount=LEAST(total_amount,paid_amount+?), balance=GREATEST(0,balance-?) WHERE id=?");
    foreach ($items as $item) {
        if ($remaining <= 0.009) break;
        $allocated = round(min($remaining,$item['balance']),2);
        if ($allocated <= 0) continue;
        if ($item['type']==='purchase') {
            $insertPurchase->execute([$paymentId,$item['id'],$allocated]);
            $updatePurchase->execute([$allocated,$allocated,$item['id']]);
        } else {
            $insertGrn->execute([$paymentId,$item['id'],$allocated]);
        }
        $remaining=round($remaining-$allocated,2);
    }
    if ($remaining > 0.009) throw new RuntimeException('The supplier payment could not be fully allocated.');
    $db->prepare("UPDATE suppliers SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$amount,$supplierId]);
    return round($amount,2);
}
