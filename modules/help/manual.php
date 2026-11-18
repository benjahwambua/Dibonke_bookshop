<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
$manualPath=__DIR__ . '/../../docs/DIBONKE_USER_MANUAL.md';
if(!is_file($manualPath)){http_response_code(404);exit('The user manual is not installed.');}
$manual=(string)file_get_contents($manualPath);
$pageTitle='User Manual';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
<style>
.manual-card{max-width:1100px;margin:auto;background:#fff;border:1px solid #e5eaf0;border-radius:14px;padding:28px;box-shadow:0 4px 18px #17324d0b}
.manual-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.manual-head h1{margin:0;color:#17324d;font-size:25px}.manual-head p{margin:5px 0;color:#64748b;font-size:13px}
.manual-head button{border:0;border-radius:8px;padding:10px 14px;background:#17324d;color:#fff;font-weight:700;cursor:pointer}
.manual-body{white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.75 Arial,sans-serif;color:#334155}
@media print{.top-header,#appSidebar,.mobile-sidebar-backdrop,footer,.manual-head button{display:none!important}.content{margin:0!important;padding:0!important;background:#fff!important}.manual-card{border:0;box-shadow:none;padding:0}.manual-body{font-size:10pt}}
</style>
<section class="manual-card">
<div class="manual-head"><div><h1><i class="fas fa-book-reader"></i> Dibonke User Manual</h1><p>Daily operations, administration and system workflows.</p></div><button type="button" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button></div>
<div class="manual-body"><?= htmlspecialchars($manual,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></div>
</section>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
