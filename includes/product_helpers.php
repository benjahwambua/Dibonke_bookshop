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
