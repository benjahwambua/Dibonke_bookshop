<?php
declare(strict_types=1);

/**
 * Normalize and validate ISBN-10 / ISBN-13 values for book records.
 * Stored values omit spaces and hyphens so the unique index treats
 * differently formatted versions of the same ISBN as one identifier.
 */
function normalizeProductIsbn($value): ?string
{
    $isbn = strtoupper((string)preg_replace('/[\s-]+/u', '', trim((string)$value)));
    if ($isbn === '') {
        return null;
    }

    if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = $isbn[$i] === 'X' ? 10 : (int)$isbn[$i];
            $sum += (10 - $i) * $digit;
        }
        if ($sum % 11 !== 0) {
            throw new RuntimeException('The ISBN-10 check digit is invalid.');
        }
        return $isbn;
    }

    if (preg_match('/^97[89]\d{10}$/', $isbn)) {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        if ($checkDigit !== (int)$isbn[12]) {
            throw new RuntimeException('The ISBN-13 check digit is invalid.');
        }
        return $isbn;
    }

    throw new RuntimeException('Enter a valid ISBN-10 or ISBN-13, or leave the ISBN blank.');
}

/**
 * Product types describe the broad retail item class. Detailed grouping
 * remains data-driven through the Categories module.
 */
function normalizeProductType($value): string
{
    $type = trim((string)$value);
    if (!in_array($type, ['Book', 'Stationery', 'General'], true)) {
        throw new RuntimeException('Select Book, Stationery or General as the product type.');
    }
    return $type;
}


/**
 * Generate a readable, unique SKU when staff leave the SKU field blank.
 * Existing/manual SKUs are never rewritten by this helper.
 */
function generateProductSku(PDO $conn, string $productType, string $productName, int $excludeProductId = 0): string
{
    $typePrefix = [
        'Book' => 'BOOK',
        'Stationery' => 'STAT',
        'General' => 'GEN',
    ][$productType] ?? 'GEN';

    $words = preg_split('/[^A-Za-z0-9]+/', strtoupper($productName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $namePrefix = '';
    foreach ($words as $word) {
        $namePrefix .= substr($word, 0, 1);
        if (strlen($namePrefix) >= 3) {
            break;
        }
    }
    if (strlen($namePrefix) < 3) {
        $compact = preg_replace('/[^A-Z0-9]/', '', strtoupper($productName));
        $namePrefix = str_pad(substr((string)$compact, 0, 3), 3, 'X');
    }
    $namePrefix = substr($namePrefix, 0, 3);
    $base = $typePrefix . '-' . $namePrefix;

    $check = $conn->prepare("SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1");
    for ($number = 1; $number <= 999999; $number++) {
        $candidate = $base . '-' . str_pad((string)$number, 3, '0', STR_PAD_LEFT);
        $check->execute([$candidate, $excludeProductId]);
        if (!$check->fetchColumn()) {
            return $candidate;
        }
    }

    throw new RuntimeException('A unique SKU could not be generated. Enter a SKU manually or contact an administrator.');
}
