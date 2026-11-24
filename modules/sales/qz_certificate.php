<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$certificatePath = getenv('DIBONKE_QZ_CERTIFICATE_PATH') ?: 'C:/xampp/private/dibonke_qz/digital-certificate.txt';
if (!is_file($certificatePath) || !is_readable($certificatePath)) {
    http_response_code(503);
    echo 'QZ Tray certificate is not configured on this computer.';
    exit;
}
$certificate = file_get_contents($certificatePath);
if ($certificate === false || trim($certificate) === '') {
    http_response_code(503);
    echo 'QZ Tray certificate could not be read.';
    exit;
}
echo $certificate;
