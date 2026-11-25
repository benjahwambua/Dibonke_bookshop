<?php
declare(strict_types=1);

/**
 * Create (or reset) the first Super User from the command line.
 *
 * A fresh database has no users, and the Users screen needs a Super User to
 * log in with first, so this script covers that bootstrap step.
 *
 *   php database/create_super_admin.php
 *   php database/create_super_admin.php "Full Name" username
 *
 * The password is prompted for (it is never stored in the repository or passed
 * on the command line). Run it AFTER importing base_schema.sql.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

function ask(string $label, ?string $default = null, bool $hidden = false): string
{
    $suffix = $default !== null && $default !== '' ? " [{$default}]" : '';
    fwrite(STDOUT, "{$label}{$suffix}: ");

    $canHide = $hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
    if ($canHide) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $line = fgets(STDIN);
    if ($canHide) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    $value = trim((string)$line);
    return $value === '' && $default !== null ? $default : $value;
}

try {
    $fullName = $argv[1] ?? ask('Full name');
    $username = strtolower($argv[2] ?? ask('Username', 'admin'));

    if ($fullName === '') {
        throw new RuntimeException('Full name is required.');
    }
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        throw new RuntimeException('Username must be 3-60 characters: letters, numbers, dot, dash or underscore.');
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        fwrite(STDOUT, "Note: on Windows the password is visible while you type it.\n");
    }
    $password = ask('Password (min 8 characters)', null, true);
    $confirm = ask('Confirm password', null, true);

    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new RuntimeException('Passwords do not match.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $find = $conn->prepare('SELECT id, is_super FROM users WHERE username = ? LIMIT 1');
    $find->execute([$username]);
    $existing = $find->fetch();

    if ($existing) {
        $answer = strtolower(ask("User '{$username}' already exists. Reset its password and make it a Super User? (yes/no)", 'no'));
        if ($answer !== 'yes') {
            fwrite(STDOUT, "No changes made.\n");
            exit(0);
        }
        $upd = $conn->prepare("UPDATE users SET full_name = ?, password = ?, role = 'Admin', is_super = 1, status = 'Active' WHERE id = ?");
        $upd->execute([$fullName, $hash, $existing['id']]);
        fwrite(STDOUT, "Updated Super User '{$username}'.\n");
    } else {
        $ins = $conn->prepare("INSERT INTO users (full_name, username, email, phone, password, role, is_super, status) VALUES (?, ?, NULL, NULL, ?, 'Admin', 1, 'Active')");
        $ins->execute([$fullName, $username, $hash]);
        fwrite(STDOUT, "Created Super User '{$username}'. Log in at /dibonke_bookshop/auth/login.php\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
