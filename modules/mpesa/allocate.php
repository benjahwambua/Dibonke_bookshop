<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/accounting_posting.php';
require_once __DIR__ . '/../../includes/payment_allocation.php';
require_once __DIR__ . '/../../includes/audit.php';

requireModuleAccess('mpesa');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verify_csrf();
requireModulePermission('mpesa', 'edit');

try {
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);
    if ($transactionId <= 0 || $customerId <= 0) {
        throw new RuntimeException('Select a customer for this M-PESA receipt.');
    }

    $conn->beginTransaction();

    $txQ = $conn->prepare("SELECT * FROM mpesa_transactions WHERE id=? AND transaction_type='C2B' AND direction='Incoming' AND result_status='Success' AND reference_type IS NULL AND reference_id IS NULL FOR UPDATE");
    $txQ->execute([$transactionId]);
    $tx = $txQ->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        throw new RuntimeException('This M-PESA receipt is no longer awaiting allocation. Refresh the page and check its status.');
    }

    $customerQ = $conn->prepare("SELECT id, customer_name, balance, status FROM customers WHERE id=? FOR UPDATE");
    $customerQ->execute([$customerId]);
    $customer = $customerQ->fetch(PDO::FETCH_ASSOC);
    if (!$customer || strcasecmp((string)$customer['status'], 'Active') !== 0) {
        throw new RuntimeException('The selected customer is not active.');
    }

    $amount = round((float)$tx['amount'], 2);
    $outstanding = round((float)$customer['balance'], 2);
    if ($amount <= 0 || $outstanding + 0.009 < $amount) {
        throw new RuntimeException('This customer has less outstanding credit than the receipt amount. Choose a customer with enough outstanding invoices; excess receipts must remain unallocated.');
    }

    $userId = (int)$_SESSION['user_id'];
    $receiptNumber = 'TMP-' . strtoupper(bin2hex(random_bytes(8)));
    $paymentQ = $conn->prepare("INSERT INTO customer_payments(receipt_number,customer_id,sale_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES(?,?,?,?,?,'M-Pesa',?,?,?)");
    $paymentQ->execute([
        $receiptNumber,
        $customerId,
        null,
        $userId,
        $amount,
        $tx['transaction_reference'],
        $tx['transaction_date'] ?: $tx['created_at'],
        'Allocated from direct C2B receipt ' . $tx['transaction_reference'],
    ]);
    $paymentId = (int)$conn->lastInsertId();
    $receiptNumber = 'CR-' . date('ymd') . '-' . str_pad((string)$paymentId, 5, '0', STR_PAD_LEFT);
    $conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$receiptNumber, $paymentId]);

    // FIFO allocation updates invoice balances and the customer summary in this transaction.
    dibonkeAllocateCustomerPayment($conn, $customerId, $paymentId, $amount);

    // Only reclassify the unallocated-receipt liability if the original C2B
    // callback actually posted that liability journal. This avoids creating
    // an orphan journal when automatic posting was disabled at receipt time.
    $sourceJournal = $conn->prepare("SELECT id,status FROM journal_entries WHERE source_type='mpesa_c2b_receipt' AND source_id=? FOR UPDATE");
    $sourceJournal->execute([$transactionId]);
    $originalJournal = $sourceJournal->fetch(PDO::FETCH_ASSOC);
    if ($originalJournal && $originalJournal['status'] === 'Posted') {
        $journalLines = [
            dibonkeAccountingLine(dibonkeAccountingAccount($conn, 'unallocated_receipts', 'Liability'), 'Direct C2B receipt allocated to customer', $amount, 0),
            dibonkeAccountingLine(dibonkeAccountingAccount($conn, 'accounts_receivable', 'Asset'), 'Customer invoices settled by C2B receipt', 0, $amount),
        ];
        dibonkeAccountingPostJournal(
            $conn,
            'mpesa_c2b_allocation',
            $transactionId,
            date('Y-m-d', strtotime((string)($tx['transaction_date'] ?: $tx['created_at']))),
            'Allocation of C2B receipt ' . $tx['transaction_reference'],
            $journalLines,
            $userId
        );
    }

    $conn->prepare("UPDATE mpesa_transactions SET reference_type='customer_payment', reference_id=?, result_description=CONCAT(COALESCE(result_description,''),' | Allocated to ',?) WHERE id=? AND reference_type IS NULL")
        ->execute([$paymentId, $customer['customer_name'], $transactionId]);

    recordAuditEvent(
        $conn,
        'Allocate',
        'mpesa_transactions',
        'Allocated C2B receipt ' . $tx['transaction_reference'] . ' to customer ' . $customer['customer_name'],
        'mpesa_transaction',
        $transactionId,
        ['reference_type' => null, 'reference_id' => null],
        ['reference_type' => 'customer_payment', 'reference_id' => $paymentId, 'customer_id' => $customerId, 'amount' => $amount]
    );

    $conn->commit();
    $_SESSION['mpesa_flash'] = 'C2B receipt allocated successfully. Customer receipt ' . $receiptNumber . ' has been created.';
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $_SESSION['mpesa_flash'] = 'C2B allocation failed: ' . $e->getMessage();
}

header('Location: index.php');
exit;
