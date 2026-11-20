<?php
declare(strict_types=1);

/**
 * Best-effort append-only audit event writer.
 * Audit failures are logged but never interrupt the business transaction.
 * Do not pass passwords, API secrets, tokens or full payment payloads here.
 */
function recordAuditEvent(
    PDO $db,
    string $action,
    string $module,
    string $description,
    ?string $recordType = null,
    ?int $recordId = null,
    ?array $before = null,
    ?array $after = null
): void {
    try {
        $stmt = $db->prepare(
            "INSERT INTO audit_events
                (user_id, action, module_key, record_type, record_id, description,
                 before_data, after_data, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            substr($action, 0, 80),
            substr($module, 0, 80),
            $recordType !== null ? substr($recordType, 0, 80) : null,
            $recordId,
            substr($description, 0, 500),
            $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)
        ]);
    } catch (Throwable $e) {
        error_log('Dibonke audit event could not be recorded: ' . $e->getMessage());
    }
}
