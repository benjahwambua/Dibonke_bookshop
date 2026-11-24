<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/product_helpers.php';

$passed = 0;
$failed = 0;

function checkProductHelper(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$message}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$message}\n";
}

function expectProductHelperException(callable $callback, string $message): void
{
    try {
        $callback();
        checkProductHelper(false, $message);
    } catch (RuntimeException $exception) {
        checkProductHelper(true, $message);
    }
}

checkProductHelper(normalizeProductIsbn('0306406152') === '0306406152', 'accepts a valid ISBN-10');
checkProductHelper(normalizeProductIsbn('9780306406157') === '9780306406157', 'accepts a valid ISBN-13');
checkProductHelper(normalizeProductIsbn('978-0-306-40615-7') === '9780306406157', 'normalizes ISBN separators');
checkProductHelper(normalizeProductIsbn('') === null, 'allows a blank optional ISBN');
checkProductHelper(normalizeProductIsbnForType('', 'Stationery') === null, 'allows stationery without an ISBN');
checkProductHelper(normalizeProductIsbnForType('9780306406157', 'Book') === '9780306406157', 'allows a valid ISBN for a book');
expectProductHelperException(
    static function (): void { normalizeProductIsbn('9780306406158'); },
    'rejects an ISBN-13 with an invalid check digit'
);
expectProductHelperException(
    static function (): void { normalizeProductIsbn('12345'); },
    'rejects a malformed ISBN'
);
expectProductHelperException(
    static function (): void { normalizeProductIsbnForType('9780306406157', 'General'); },
    'rejects ISBN on general merchandise'
);
expectProductHelperException(
    static function (): void { normalizeProductIsbnForType('0306406152', 'Stationery'); },
    'rejects ISBN on stationery'
);
checkProductHelper(normalizeProductType('Book') === 'Book', 'accepts the Book product type');
checkProductHelper(normalizeProductType('Stationery') === 'Stationery', 'accepts the Stationery product type');
checkProductHelper(normalizeProductType('General') === 'General', 'accepts the General product type');
expectProductHelperException(
    static function (): void { normalizeProductType('Alcohol'); },
    'rejects a product type outside the retail catalogue'
);

echo "\nProduct helper tests: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
