<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';

$pageTitle = 'Suppliers';
$errors = [];
$canCreateSupplier = hasModulePermission('suppliers', 'create');
$canEditSupplier = hasModulePermission('suppliers', 'edit');
$canDeleteSupplier = hasModulePermission('suppliers', 'delete');
$editSupplier = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $permissionAction = $action === 'create' ? 'create' : ($action === 'delete' ? 'delete' : 'edit');
    requireModulePermission('suppliers', $permissionAction);

    try {
        if ($action === 'create' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['supplier_name'] ?? ''));
            $contact = trim((string)($_POST['contact_person'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $taxPin = trim((string)($_POST['tax_pin'] ?? ''));
            $creditLimit = max(0, (float)($_POST['credit_limit'] ?? 0));
            $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($name === '') {
                throw new RuntimeException('Supplier name is required.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid supplier email.');
            }

            $sql = "SELECT id FROM suppliers WHERE supplier_name = ?" . ($action === 'update' ? " AND id <> ?" : "") . " LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute($action === 'update' ? [$name, $id] : [$name]);
            if ($stmt->fetch()) {
                throw new RuntimeException('A supplier with this name already exists.');
            }

            if ($action === 'create') {
                $stmt = $conn->prepare("INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, tax_pin, credit_limit, balance, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
                $stmt->execute([$name,$contact,$phone,$email ?: null,$address,$taxPin,$creditLimit,$status,$notes]);
                $_SESSION['flash'] = ['type'=>'success','message'=>'Supplier created successfully.'];
            } else {
                $stmt = $conn->prepare("UPDATE suppliers SET supplier_name=?, contact_person=?, phone=?, email=?, address=?, tax_pin=?, credit_limit=?, status=?, notes=? WHERE id=?");
                $stmt->execute([$name,$contact,$phone,$email ?: null,$address,$taxPin,$creditLimit,$status,$notes,$id]);
                $_SESSION['flash'] = ['type'=>'success','message'=>'Supplier updated successfully.'];
            }

            header('Location: ' . BASE_URL . '/modules/suppliers/index.php');
            exit;
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);

            $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE supplier_id = ?");
            $stmt->execute([$id]);
            $productLinks = (int)$stmt->fetchColumn();

            $stmt = $conn->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id = ?");
            $stmt->execute([$id]);
            $purchaseLinks = (int)$stmt->fetchColumn();

            if ($productLinks > 0 || $purchaseLinks > 0) {
                throw new RuntimeException('This supplier cannot be deleted because it has linked products or purchase records. Set it to Inactive instead.');
            }

            $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'Supplier deleted successfully.'];
            header('Location: ' . BASE_URL . '/modules/suppliers/index.php');
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

if (isset($_GET['edit']) && $canEditSupplier) {
    $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editSupplier = $stmt->fetch() ?: null;
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT s.*, (SELECT COUNT(*) FROM products p WHERE p.supplier_id=s.id) AS product_count, (SELECT COUNT(*) FROM purchases pu WHERE pu.supplier_id=s.id) AS purchase_count FROM suppliers s WHERE 1=1";
$params=[];
if($search!==''){
    $sql.=" AND (s.supplier_name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
    foreach([$search,$search,$search,$search] as $v){$params[]="%{$v}%";}
}
if(in_array($statusFilter,['Active','Inactive'],true)){ $sql.=" AND s.status=?"; $params[]=$statusFilter; }
$sql.=" ORDER BY s.supplier_name ASC";
$stmt=$conn->prepare($sql);$stmt->execute($params);$suppliers=$stmt->fetchAll();

$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
$summary = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(status='Active'),0) AS active, COALESCE(SUM(status='Inactive'),0) AS inactive, COALESCE(SUM(balance),0) AS outstanding FROM suppliers")->fetch();
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="<?= BASE_URL ?>/index.php">Dashboard</a><span aria-hidden="true">/</span><span class="current">Suppliers</span></nav>
<div class="master-page">
<div class="page-heading"><div><h1>Suppliers</h1><p>Manage suppliers, contacts, credit limits and purchasing relationships.</p></div><?php if($canCreateSupplier): ?><button class="btn btn-primary" onclick="document.getElementById('supplierModal').classList.add('show')"><i class="fas fa-plus"></i> Add Supplier</button><?php endif; ?></div>
<?php if($flash): ?><div class="alert success"><?=e($flash['message'])?></div><?php endif; ?>
<?php foreach($errors as $error): ?><div class="alert danger"><?=e($error)?></div><?php endforeach; ?>
<div class="cards"><div class="card"><strong><?=number_format((int)$summary['total'])?></strong><small>Total Suppliers</small></div><div class="card"><strong><?=number_format((int)$summary['active'])?></strong><small>Active Suppliers</small></div><div class="card"><strong><?=number_format((int)$summary['inactive'])?></strong><small>Inactive Suppliers</small></div><div class="card"><strong>KES <?=number_format((float)$summary['outstanding'],2)?></strong><small>Outstanding Supplier Balance</small></div></div>
<div class="toolbar"><form method="get" class="filter-form"><input type="search" name="search" value="<?=e($search)?>" placeholder="Search supplier, contact, phone..."><select name="status"><option value="">All statuses</option><option value="Active" <?=$statusFilter==='Active'?'selected':''?>>Active</option><option value="Inactive" <?=$statusFilter==='Inactive'?'selected':''?>>Inactive</option></select><button class="btn btn-secondary">Search</button><?php if($search!==''||$statusFilter!==''): ?><a class="btn btn-light" href="<?=BASE_URL?>/modules/suppliers/index.php">Clear</a><?php endif; ?></form></div>
<div class="table-card"><div class="table-wrap"><table><thead><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th>Credit Limit</th><th>Balance</th><th>Linked Products</th><th>Purchases</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if(!$suppliers): ?><tr><td colspan="9" class="empty">No suppliers found.</td></tr><?php else: foreach($suppliers as $s): ?>
<tr><td><strong><?=e($s['supplier_name'])?></strong><br><small><?=e($s['email']?:'')?></small></td><td><?=e($s['contact_person']?:'—')?></td><td><?=e($s['phone']?:'—')?></td><td>KES <?=number_format((float)$s['credit_limit'],2)?></td><td>KES <?=number_format((float)$s['balance'],2)?></td><td><span class="count-pill"><?= (int)$s['product_count']?></span></td><td><span class="count-pill"><?= (int)$s['purchase_count']?></span></td><td><span class="status <?=strtolower($s['status'])?>"><?=e($s['status'])?></span></td><td class="actions"><?php if($canEditSupplier): ?><a class="icon-btn" href="?edit=<?= (int)$s['id']?>" aria-label="Edit supplier" title="Edit supplier"><i class="fas fa-edit"></i></a><?php endif; ?><?php if($canDeleteSupplier): ?><form method="post" onsubmit="return confirm('Delete this supplier? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['id']?>"><button class="icon-btn danger-btn" type="submit" aria-label="Delete supplier" title="Delete supplier"><i class="fas fa-trash"></i></button></form><?php endif; ?><?php if(!$canEditSupplier && !$canDeleteSupplier): ?><span class="muted">—</span><?php endif; ?></td></tr>
<?php endforeach; endif; ?></tbody></table></div></div></div></main>
<?php if($canCreateSupplier || ($canEditSupplier && $editSupplier)): ?><div class="modal <?= $editSupplier?'show':'' ?>" id="supplierModal"><div class="modal-box wide"><div class="modal-head"><h2><?=$editSupplier?'Edit Supplier':'Add Supplier'?></h2><a href="<?=BASE_URL?>/modules/suppliers/index.php">&times;</a></div><form method="post"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="<?=$editSupplier?'update':'create'?>"><?php if($editSupplier): ?><input type="hidden" name="id" value="<?= (int)$editSupplier['id']?>"><?php endif; ?><div class="form-grid"><div><label>Supplier Name *</label><input name="supplier_name" required value="<?=e($editSupplier['supplier_name']??'')?>"></div><div><label>Contact Person</label><input name="contact_person" value="<?=e($editSupplier['contact_person']??'')?>"></div><div><label>Phone</label><input name="phone" value="<?=e($editSupplier['phone']??'')?>"></div><div><label>Email</label><input type="email" name="email" value="<?=e($editSupplier['email']??'')?>"></div><div><label>Tax PIN</label><input name="tax_pin" value="<?=e($editSupplier['tax_pin']??'')?>"></div><div><label>Credit Limit (KES)</label><input type="number" min="0" step="0.01" name="credit_limit" value="<?=e($editSupplier['credit_limit']??'0')?>"></div><div><label>Status</label><select name="status"><option value="Active" <?=($editSupplier['status']??'Active')==='Active'?'selected':''?>>Active</option><option value="Inactive" <?=($editSupplier['status']??'')==='Inactive'?'selected':''?>>Inactive</option></select></div><div class="full"><label>Address</label><textarea name="address" rows="2"><?=e($editSupplier['address']??'')?></textarea></div><div class="full"><label>Notes</label><textarea name="notes" rows="3"><?=e($editSupplier['notes']??'')?></textarea></div></div><div class="modal-actions"><a class="btn btn-light" href="<?=BASE_URL?>/modules/suppliers/index.php">Cancel</a><button class="btn btn-primary">Save Supplier</button></div></form></div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>