<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}
$request = $_GET['request'] ?? null;
if (!is_string($request) || $request === '' || strlen($request) > 100000) {
    http_response_code(400);
    echo 'Invalid signing request.';
    exit;
}

$keyPath = getenv('DIBONKE_QZ_PRIVATE_KEY_PATH') ?: 'C:/xampp/private/dibonke_qz/private-key.pem';
if (!is_file($keyPath) || !is_readable($keyPath)) {
    error_log('Dibonke QZ signing key is missing or unreadable.');
    http_response_code(503);
    echo 'QZ Tray signing key is not configured on this computer.';
    exit;
}
$keyContents = file_get_contents($keyPath);
$privateKey = $keyContents !== false ? openssl_pkey_get_private($keyContents) : false;
if ($privateKey === false) {
    error_log('Dibonke QZ signing key could not be opened.');
    http_response_code(503);
    echo 'QZ Tray signing key could not be opened.';
    exit;
}

$signature = '';
if (!openssl_sign($request, $signature, $privateKey, OPENSSL_ALGO_SHA512)) {
    openssl_free_key($privateKey);
    error_log('Dibonke QZ request signing failed.');
    http_response_code(500);
    echo 'QZ Tray request signing failed.';
    exit;
}
openssl_free_key($privateKey);
echo base64_encode($signature);
