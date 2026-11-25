<?php
declare(strict_types=1);

/**
 * Accounting automation helpers.
 * All posting functions must be called inside the originating source transaction.
 */
function dibonkeAccountingText(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function dibonkeAccountingTablesReady(PDO $conn): bool
{
    $tables = array_flip($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
    return isset($tables['accounts'], $tables['journal_entries'], $tables['journal_lines'], $tables['accounting_mappings']);
}

function dibonkeAccountingAutoPostingEnabled(PDO $conn): bool
{
    if (!dibonkeAccountingTablesReady($conn)) return false;
    $q = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key='accounting_auto_posting_enabled' LIMIT 1");
    $q->execute();
    return (string)$q->fetchColumn() === '1';
}

function dibonkeAccountingAccount(PDO $conn, string $mappingKey, string $expectedType): int
{
    $q = $conn->prepare("SELECT a.id FROM accounting_mappings m JOIN accounts a ON a.id=m.account_id WHERE m.mapping_key=? AND a.status='Active' AND a.account_type=? LIMIT 1");
    $q->execute([$mappingKey, $expectedType]);
    $id = (int)$q->fetchColumn();
    if ($id <= 0) throw new RuntimeException('Accounting setup is incomplete: map "' . $mappingKey . '" to an active ' . $expectedType . ' account.');
    return $id;
}

function dibonkeAccountingExpenseAccount(PDO $conn, int $categoryId): int
{
    $q = $conn->prepare("SELECT a.id FROM expense_category_accounting m JOIN accounts a ON a.id=m.account_id WHERE m.category_id=? AND a.status='Active' AND a.account_type='Expense' LIMIT 1");
    $q->execute([$categoryId]);
    $id = (int)$q->fetchColumn();
    if ($id > 0) return $id;
    return dibonkeAccountingAccount($conn, 'default_expense', 'Expense');
}

function dibonkeAccountingPaymentAccount(PDO $conn, string $method): int
{
    $key = match ($method) {
        'Cash' => 'cash_on_hand',
        'M-Pesa' => 'mpesa_clearing',
        'Bank', 'Cheque' => 'bank_account',
        'Mixed', 'Other', 'Credit' => 'other_clearing',
        default => throw new RuntimeException('No accounting payment mapping exists for method: ' . $method),
    };
    return dibonkeAccountingAccount($conn, $key, 'Asset');
}

function dibonkeAccountingAssertPeriodOpen(PDO $conn, string $journalDate): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $journalDate)) throw new RuntimeException('Invalid accounting date.');
    if (!(bool)$conn->query("SHOW TABLES LIKE 'accounting_period_locks'")->fetchColumn()) return;
    $q=$conn->prepare("SELECT id,period_start,period_end FROM accounting_period_locks WHERE ? BETWEEN period_start AND period_end LIMIT 1".($conn->inTransaction()?' FOR UPDATE':''));
    $q->execute([$journalDate]);
    $locked=$q->fetch(PDO::FETCH_ASSOC);
    if($locked)throw new RuntimeException('Accounting period '.$locked['period_start'].' to '.$locked['period_end'].' is locked. The transaction cannot be posted into a closed period.');
}

function dibonkeAccountingPostJournal(PDO $conn, string $sourceType, int $sourceId, string $journalDate, string $description, array $lines, ?int $userId): void
{
    if (!$conn->inTransaction()) throw new LogicException('Automatic journal posting must share the source transaction.');
    if ($sourceId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $journalDate)) throw new RuntimeException('Invalid accounting source reference or date.');
    dibonkeAccountingAssertPeriodOpen($conn,$journalDate);

    $existing = $conn->prepare("SELECT id,status FROM journal_entries WHERE source_type=? AND source_id=? FOR UPDATE");
    $existing->execute([$sourceType, $sourceId]);
    $prior = $existing->fetch(PDO::FETCH_ASSOC);
    if ($prior) {
        if ($prior['status'] === 'Posted') return;
        throw new RuntimeException('An accounting journal already exists for this source and requires administrator review.');
    }

    $clean = [];
    $debit = 0.0;
    $credit = 0.0;
    foreach ($lines as $line) {
        $accountId = (int)($line['account_id'] ?? 0);
        $d = round((float)($line['debit'] ?? 0), 2);
        $c = round((float)($line['credit'] ?? 0), 2);
        if ($accountId <= 0 || $d < 0 || $c < 0 || (($d > 0) === ($c > 0))) {
            if ($d === 0.0 && $c === 0.0) continue;
            throw new RuntimeException('An automatic journal line is invalid.');
        }
        $clean[] = [$accountId, trim((string)($line['description'] ?? '')), $d, $c];
        $debit += $d;
        $credit += $c;
    }
    $debit = round($debit, 2);
    $credit = round($credit, 2);
    if (count($clean) < 2 || $debit <= 0 || abs($debit - $credit) > 0.009) {
        throw new RuntimeException('Automatic journal failed its debit/credit balance check.');
    }

    $tmp = 'TMP-' . strtoupper(bin2hex(random_bytes(8)));
    $insert = $conn->prepare("INSERT INTO journal_entries(journal_number,journal_date,description,reference,source_type,source_id,status,total_debit,total_credit,created_by,posted_by,posted_at) VALUES(?,?,?,?,?,?,'Posted',?,?,?,?,NOW())");
    $insert->execute([$tmp, $journalDate, dibonkeAccountingText($description, 255), $sourceType . ':' . $sourceId, $sourceType, $sourceId, $debit, $credit, $userId, $userId]);
    $journalId = (int)$conn->lastInsertId();
    $number = 'JV-' . date('ymd', strtotime($journalDate)) . '-' . str_pad((string)$journalId, 5, '0', STR_PAD_LEFT);
    $conn->prepare("UPDATE journal_entries SET journal_number=? WHERE id=?")->execute([$number, $journalId]);

    $insertLine = $conn->prepare("INSERT INTO journal_lines(journal_entry_id,account_id,line_description,debit,credit) VALUES(?,?,?,?,?)");
    foreach ($clean as [$accountId, $lineDescription, $d, $c]) {
        $insertLine->execute([$journalId, $accountId, $lineDescription !== '' ? dibonkeAccountingText($lineDescription, 255) : null, $d, $c]);
    }
}

function dibonkeAccountingLine(int $accountId, string $description, float $debit = 0.0, float $credit = 0.0): array
{
    return ['account_id' => $accountId, 'description' => $description, 'debit' => round($debit, 2), 'credit' => round($credit, 2)];
}
