<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/audit.php';

if (!isAdministrator()) { http_response_code(403); exit('Access denied.'); }
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
    $roleId=(int)($_POST['role_id']??0);$staffId=(int)($_POST['staff_id']??0);$role=null;if(in_array($action,['create','update'],true)){$roleStmt=$conn->prepare("SELECT role_name FROM roles WHERE id=? AND status='Active'");$roleStmt->execute([$roleId]);$role=$roleStmt->fetchColumn();}

    if (in_array($action,['create','update'],true) && $name === '') throw new RuntimeException('Full name is required.');
    if (in_array($action,['create','update'],true) && !$role) throw new RuntimeException('Select an active role.');

    if ($action === 'set_status') {
        $id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
        if($id<1||$id===(int)$_SESSION['user_id'])throw new RuntimeException('You cannot change the status of your own account.');
        if(!in_array($status,['Active','Inactive'],true))throw new RuntimeException('Invalid account status.');
        $q=$conn->prepare("SELECT is_super FROM users WHERE id=?");$q->execute([$id]);$target=$q->fetch();
        if(!$target||(int)$target['is_super']===1)throw new RuntimeException('Super User accounts are protected.');
        $conn->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status,$id]);
        recordAuditEvent($conn,'Update','users','Set user ID '.$id.' status to '.$status,'user',$id,null,['status'=>$status]);
        userFlash('success','User account '.$status.'.');
    }

    if ($action === 'delete') {
        $id=(int)($_POST['id']??0);
        if($id<1||$id===(int)$_SESSION['user_id'])throw new RuntimeException('You cannot delete your own account.');
        $q=$conn->prepare("SELECT is_super,full_name,status FROM users WHERE id=? FOR UPDATE");
        $conn->beginTransaction();$q->execute([$id]);$target=$q->fetch();
        if(!$target||(int)$target['is_super']===1)throw new RuntimeException('Super User accounts are protected.');
        $checks=['sales'=>'user_id','expenses'=>'user_id','purchases'=>'user_id','customer_payments'=>'user_id','supplier_payments'=>'user_id','audit_events'=>'user_id','activity_logs'=>'user_id','stock_movements'=>'user_id','cash_shifts'=>'user_id','cash_shift_movements'=>'user_id','staff'=>'user_id','purchase_orders'=>'user_id','goods_receipts'=>'user_id','journal_entries'=>'created_by'];
        foreach($checks as $table=>$column){$exists=$conn->query("SHOW TABLES LIKE ".$conn->quote($table))->fetchColumn();if($exists){$col=$conn->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");$col->execute([$table,$column]);if((int)$col->fetchColumn()>0){$q=$conn->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('This account has linked business or audit records. Deactivate it instead to preserve history.');}}}
        $conn->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        $conn->commit();userFlash('success','New user account deleted.');
    }

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