<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/audit.php';

if (!isSuperUser()) { http_response_code(403); exit('Access denied.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
verify_csrf();

function userFlash(string $type, string $message): void {
    $_SESSION['user_flash'] = ['type'=>$type, 'message'=>$message];
    header('Location: index.php');
    exit;
}

try {
    $action = (string)($_POST['action'] ?? 'create');
    $name = trim((string)($_POST['full_name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $roleId=(int)($_POST['role_id']??0);$staffId=(int)($_POST['staff_id']??0);$roleStmt=$conn->prepare("SELECT role_name FROM roles WHERE id=? AND status='Active'");$roleStmt->execute([$roleId]);$role=$roleStmt->fetchColumn();

    if ($name === '') throw new RuntimeException('Full name is required.');
    if (!$role) throw new RuntimeException('Select an active role.');

    if ($action === 'create') {
        if (strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');

        $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '.', $name), '.'));
        if ($base === '') $base = 'user';
        $username = $base;
        $n = 2;
        $check = $conn->prepare("SELECT COUNT(*) FROM users WHERE username=?");
        while (true) {
            $check->execute([$username]);
            if ((int)$check->fetchColumn() === 0) break;
            $username = $base . $n;
            $n++;
        }

        $conn->beginTransaction();
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, email, phone, password, role, role_id, is_super, status) VALUES (?, ?, NULL, NULL, ?, ?, ?, 0, 'Active')");
        $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $role, $roleId]);
        $newUserId=(int)$conn->lastInsertId();
        if($staffId>0){$link=$conn->prepare("UPDATE staff SET user_id=? WHERE id=? AND user_id IS NULL AND status='Active'");$link->execute([$newUserId,$staffId]);if($link->rowCount()!==1)throw new RuntimeException('Selected staff profile is already linked or inactive.');}
        recordAuditEvent($conn,'Create','users','Created user '.$username,'user',$newUserId,null,['full_name'=>$name,'role'=>$role,'status'=>'Active']);
        $conn->commit();

        userFlash('success', "User created successfully. Username: {$username}");
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) throw new RuntimeException('Invalid user.');

        $stmt = $conn->prepare("SELECT id, is_super, full_name, role, status FROM users WHERE id=?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) throw new RuntimeException('User not found.');
        if ((int)$existing['is_super'] === 1) throw new RuntimeException('Super User accounts cannot be modified from this screen.');

        $status = (string)($_POST['status'] ?? 'Active');
        if (!in_array($status, ['Active','Inactive'], true)) throw new RuntimeException('Invalid status.');

        if($staffId>0){$linkCheck=$conn->prepare("SELECT id FROM staff WHERE id=? AND status='Active' AND (user_id IS NULL OR user_id=?)");$linkCheck->execute([$staffId,$id]);if(!$linkCheck->fetch())throw new RuntimeException('Selected staff profile is already linked or inactive.');}
        $conn->beginTransaction();
        $conn->prepare("UPDATE staff SET user_id=NULL WHERE user_id=?")->execute([$id]);
        if($staffId>0){$link=$conn->prepare("UPDATE staff SET user_id=? WHERE id=?");$link->execute([$id,$staffId]);}
        if ($password !== '') {
            if (strlen($password) < 8) throw new RuntimeException('Password must be at least 8 characters.');
            $stmt = $conn->prepare("UPDATE users SET full_name=?, role=?, role_id=?, status=?, password=? WHERE id=?");
            $stmt->execute([$name, $role, $roleId, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, role=?, role_id=?, status=? WHERE id=?");
            $stmt->execute([$name, $role, $roleId, $status, $id]);
        }

        recordAuditEvent($conn,'Update','users','Updated user account ID '.$id,'user',$id,['full_name'=>$existing['full_name']??null],['full_name'=>$name,'role'=>$role,'status'=>$status]);
        $conn->commit();
        userFlash('success', 'User details updated successfully.');
    }

    userFlash('error', 'Invalid action.');
} catch (Throwable $e) {
    if($conn->inTransaction())$conn->rollBack();
    userFlash('error', 'User management failed: ' . $e->getMessage());
}