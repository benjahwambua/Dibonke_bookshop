<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';

$isAdmin = function_exists('isAdministrator') && isAdministrator();
$requestedManual = (string)($_GET['manual'] ?? 'user');
if (!in_array($requestedManual, ['user', 'admin'], true)) {
    $requestedManual = 'user';
}
if ($requestedManual === 'admin' && !$isAdmin) {
    http_response_code(403);
    exit('You are not authorised to view the Administrator Manual.');
}

$manualFiles = [
    'user' => __DIR__ . '/../../docs/DIBONKE_USER_MANUAL.md',
    'admin' => __DIR__ . '/../../docs/DIBONKE_ADMIN_MANUAL.md',
];
$manualPath = $manualFiles[$requestedManual];
if (!is_file($manualPath)) {
    http_response_code(404);
    exit('The selected manual is not installed.');
}
$manual = (string)file_get_contents($manualPath);
$pageTitle = $requestedManual === 'admin' ? 'Administrator Manual' : 'User Manual';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
<style>
.manual-card{max-width:1100px;margin:auto;background:#fff;border:1px solid #e5eaf0;border-radius:14px;padding:28px;box-shadow:0 4px 18px #17324d0b}
.manual-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}
.manual-head h1{margin:0;color:#17324d;font-size:25px}.manual-head p{margin:5px 0;color:#64748b;font-size:13px}
.manual-actions{display:flex;gap:8px;flex-wrap:wrap}
.manual-actions a,.manual-actions button{display:inline-flex;align-items:center;gap:7px;border:1px solid #dbe3eb;border-radius:8px;padding:10px 13px;background:#fff;color:#17324d;text-decoration:none;font-weight:700;cursor:pointer}
.manual-actions a.current{background:#17324d;border-color:#17324d;color:#fff}
.manual-actions button{background:#d6a84f;border-color:#d6a84f;color:#17324d}
.manual-body{white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.75 Arial,sans-serif;color:#334155}
@media print{.top-header,#appSidebar,.mobile-sidebar-backdrop,footer,.manual-actions{display:none!important}.content{margin:0!important;padding:0!important;background:#fff!important}.manual-card{border:0;box-shadow:none;padding:0}.manual-body{font-size:10pt}}
</style>
<section class="manual-card">
<div class="manual-head">
 <div><h1><i class="fas fa-book-reader"></i> <?= $requestedManual === 'admin' ? 'Administrator Manual' : 'General User Manual' ?></h1>
 <p><?= $requestedManual === 'admin' ? 'User administration, access control and business oversight.' : 'Guidance for everyday sales, stock, customer and staff tasks.' ?></p></div>
 <div class="manual-actions">
  <a href="<?= BASE_URL ?>/modules/help/manual.php?manual=user" class="<?= $requestedManual === 'user' ? 'current' : '' ?>">General User</a>
  <?php if ($isAdmin): ?><a href="<?= BASE_URL ?>/modules/help/manual.php?manual=admin" class="<?= $requestedManual === 'admin' ? 'current' : '' ?>">Admin Manual</a><?php endif; ?>
  <button type="button" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
 </div>
</div>
<div class="manual-body"><?= htmlspecialchars($manual, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
</section>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
