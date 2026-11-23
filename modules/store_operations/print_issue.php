<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireModuleAccess('store_operations');

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT ds.id,ds.business_date,ds.responsible_person,s.store_name,
           t.transfer_number,t.transfer_date,u.full_name issued_by
    FROM store_daily_sessions ds
    INNER JOIN stores s ON s.id=ds.store_id
    LEFT JOIN store_transfers t ON t.id=ds.issue_transfer_id
    LEFT JOIN users u ON u.id=t.created_by
    WHERE ds.id=? LIMIT 1
");
$stmt->execute([$id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) { http_response_code(404); exit('Stock issue checklist not found.'); }

$itemsStmt = $conn->prepare("
    SELECT p.product_name,p.sku,p.unit,di.quantity_issued
    FROM store_daily_items di
    INNER JOIN products p ON p.id=di.product_id
    WHERE di.session_id=? AND di.quantity_issued>0
    ORDER BY p.product_name
");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

function checklistEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stock Issue Checklist - <?= checklistEscape($session['store_name']) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;color:#172033;margin:0;background:#f1f5f9}
.sheet{max-width:900px;margin:24px auto;background:#fff;padding:34px}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:2px solid #1e3a5f;padding-bottom:16px}
h1{font-size:23px;margin:0 0 6px;color:#17324d}.muted{color:#64748b;font-size:12px}
.reference{text-align:right;font-size:12px;line-height:1.7}
.details{display:grid;grid-template-columns:1fr 1fr;gap:10px 24px;margin:20px 0}
.detail{border-bottom:1px solid #dbe3ec;padding:8px 0;font-size:13px}
table{width:100%;border-collapse:collapse;margin-top:18px}
th{background:#edf2f7;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.03em}
th,td{border:1px solid #cbd5e1;padding:10px 9px;font-size:12px}
td.qty{text-align:right;font-weight:700;width:120px}
td.check{width:90px;text-align:center}
.tick{display:inline-block;width:17px;height:17px;border:1px solid #64748b}
.signatures{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:44px}
.signature{border-top:1px solid #334155;padding-top:8px;font-size:12px}
.note{margin-top:24px;font-size:11px;color:#64748b}
.actions{display:flex;justify-content:flex-end;gap:8px;margin:0 auto 14px;max-width:900px}
button,a{border:0;border-radius:6px;padding:10px 15px;background:#174a7e;color:#fff;text-decoration:none;font-size:13px;cursor:pointer}
a{background:#e2e8f0;color:#243449}
@media print{
 @page{size:A4;margin:12mm}
 body{background:#fff}
 .sheet{max-width:none;margin:0;padding:0}
 .actions{display:none}
 th{background:#edf2f7!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
 tr{page-break-inside:avoid}
}
@media(max-width:650px){.sheet{margin:0;padding:18px}.top{display:block}.reference{text-align:left;margin-top:12px}.details{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="actions">
<a href="view.php?id=<?= (int)$session['id'] ?>">Back to daily record</a>
<button type="button" onclick="window.print()">Print Checklist</button>
</div>
<main class="sheet">
<div class="top">
 <div><h1>STOCK ISSUE CHECKLIST</h1><div class="muted">Dibonke Bookshop · Main Store to Selling Store</div></div>
 <div class="reference"><strong><?= checklistEscape($session['transfer_number'] ?: 'ISSUE') ?></strong><br>Issued: <?= checklistEscape(date('d M Y, h:i A',strtotime((string)$session['transfer_date']))) ?></div>
</div>
<div class="details">
 <div class="detail"><strong>Selling store:</strong> <?= checklistEscape($session['store_name']) ?></div>
 <div class="detail"><strong>Business date:</strong> <?= checklistEscape(date('d M Y',strtotime((string)$session['business_date']))) ?></div>
 <div class="detail"><strong>Person taking charge:</strong> <?= checklistEscape($session['responsible_person']) ?></div>
 <div class="detail"><strong>Issued by:</strong> <?= checklistEscape($session['issued_by'] ?: '________________________') ?></div>
</div>
<table>
<thead><tr><th style="width:42px">No.</th><th>Product description</th><th>SKU</th><th style="width:125px;text-align:right">Qty issued</th><th style="width:90px;text-align:center">Checked</th></tr></thead>
<tbody>
<?php foreach ($items as $i=>$item): ?>
<tr>
<td><?= $i+1 ?></td>
<td><strong><?= checklistEscape($item['product_name']) ?></strong></td>
<td><?= checklistEscape($item['sku'] ?: '—') ?></td>
<td class="qty"><?= number_format((float)$item['quantity_issued'],3) ?> <?= checklistEscape($item['unit'] ?: 'units') ?></td>
<td class="check"><span class="tick"></span></td>
</tr>
<?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="5">No stock items were included in this issue.</td></tr><?php endif; ?>
</tbody>
</table>
<div class="signatures">
 <div class="signature">Issued by (name &amp; signature):</div>
 <div class="signature">Received by (name &amp; signature):</div>
</div>
<div class="signatures" style="margin-top:34px">
 <div class="signature">Date &amp; time issued:</div>
 <div class="signature">Date &amp; time received:</div>
</div>
<p class="note">Please physically count and tick each item before signing. Any difference must be corrected and agreed before the stock leaves Main Store. Keep this checklist for end-of-day reconciliation.</p>
</main>
</body>
</html>
