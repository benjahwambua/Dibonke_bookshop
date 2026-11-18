<?php
declare(strict_types=1);
/** PNG/JPEG only; maximum 2 MB and 2400x2400 pixels. */
function uploadProductImage(string $field = 'product_image'): ?string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $file = $_FILES[$field];
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('The product image could not be uploaded. Please try again.');
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > 2 * 1024 * 1024) throw new RuntimeException('Product images must be 2 MB or smaller.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('The uploaded product image is invalid.');
    $info = @getimagesize($tmp);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!$info || !isset($allowed[$mime]) || !in_array((int)$info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) throw new RuntimeException('Only genuine PNG and JPEG image files are allowed.');
    if ((int)$info[0] > 2400 || (int)$info[1] > 2400) throw new RuntimeException('Product images must not exceed 2400 x 2400 pixels.');
    $directory = dirname(__DIR__) . '/uploads/products';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('The product image folder could not be created. Check folder permissions.');
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, $directory . '/' . $filename)) throw new RuntimeException('The product image could not be saved. Check folder permissions.');
    return 'uploads/products/' . $filename;
}
function deleteProductImage(?string $relativePath): void
{
    if (!$relativePath || !preg_match('#^uploads/products/[a-f0-9]{32}\.(?:jpg|png)$#', $relativePath)) return;
    $fullPath = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($fullPath)) @unlink($fullPath);
}
