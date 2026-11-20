<?php
declare(strict_types=1);

/**
 * Isolated Daraja sandbox STK callback.
 *
 * This endpoint deliberately does not write to mpesa_transactions, sales,
 * customer balances, customer_payments, invoices, or accounting journals.
 * It only acknowledges a valid-shaped Safaricom STK callback so sandbox
 * tests cannot be mistaken for bookshop collections.
 */
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'POST only']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
$callback = $data['Body']['stkCallback'] ?? null;

if (!is_array($callback) || empty($callback['CheckoutRequestID'])) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid sandbox callback payload']);
    exit;
}

// Deliberately acknowledge without processing or persisting payment data.
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Sandbox test callback accepted; no Dibonke records changed']);
