<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
requireModuleAccess('launch_controls');
requireModulePermission('stocktaking', 'view');

function scrH($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$countId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$countId || $countId < 1) {
    http_response_code(400);
    exit('A valid stock count reference is required.');
}

$head = $conn->prepare(
    "SELECT sc.*, creator.full_name AS creator_name, approver.full_name AS approver_name
     FROM stock_counts sc
     LEFT JOIN users creator ON creator.id = sc.created_by
     LEFT JOIN users approver ON approver.id = sc.approved_by
     WHERE sc.id = ? LIMIT 1"
);
$head->execute([$countId]);
$count = $head->fetch(PDO::FETCH_ASSOC);
if (!$count) {
    http_response_code(404);
    exit('Stock count not found.');
}

$itemsQuery = $conn->prepare(
    "SELECT sci.*, p.product_name, p.sku, p.barcode, p.unit,
            counter.full_name AS counted_by_name
     FROM stock_count_items sci
     LEFT JOIN products p ON p.id = sci.product_id
     LEFT JOIN users counter ON counter.id = sci.counted_by
     WHERE sci.stock_count_id = ?
     ORDER BY p.product_name, sci.id"
);
$itemsQuery->execute([$countId]);
$items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

$totalItems = count($items);
$countedItems = 0;
$shortageItems = 0;
$surplusItems = 0;
$shortageUnits = 0;
$surplusUnits = 0;
foreach ($items as $item) {
    if ($item['counted_quantity'] === null) {
        continue;
    }
    $countedItems++;
    $variance = (int)$item['counted_quantity'] - (int)$item['system_quantity'];
    if ($variance < 0) {
        $shortageItems++;
        $shortageUnits += abs($variance);
    } elseif ($variance > 0) {
        $surplusItems++;
        $surplusUnits += $variance;
    }
}
$netVariance = $surplusUnits - $shortageUnits;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= scrH($count['count_number']) ?> · Stock Count Report · Dibonke</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#172b3d;margin:24px;font-size:13px}
header{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;border-bottom:2px solid #17324d;padding-bottom:14px}
h1{font-size:22px;margin:0 0 6px}h2{font-size:15px;margin:20px 0 8px}
.muted{color:#64748b}.meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:16px 0}
.meta div,.metric{border:1px solid #dce4ec;border-radius:7px;padding:10px}.meta span,.metric span{display:block;color:#64748b;font-size:11px;margin-bottom:5px}
.metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin:14px 0}.metric strong{font-size:17px}
table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #dce4ec;padding:7px;text-align:left;font-size:11px}
th{background:#f1f5f9}.num{text-align:right}.sign{min-width:140px}
.actions{display:flex;gap:8px;margin-bottom:16px}.actions a,.actions button{border:0;border-radius:6px;background:#17324d;color:white;padding:9px 13px;text-decoration:none;font-weight:bold;cursor:pointer}
.note{margin-top:16px;padding:10px;background:#f7f9fb;border:1px solid #e2e8f0;border-radius:6px}
@media(max-width:700px){body{margin:12px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.meta{grid-template-columns:1fr 1fr}}
@media print{body{margin:10mm;font-size:11px}.actions{display:none}header{break-inside:avoid}.metrics{grid-template-columns:repeat(5,minmax(0,1fr))}tr{break-inside:avoid}a{color:inherit;text-decoration:none}}
</style>
</head>
<body>
<div class="actions">
<button type="button" onclick="window.print()">Print / Save as PDF</button>
<a href="index.php?tab=stocktaking">Back to Stocktaking</a>
</div>
<header>
<div><h1>Dibonke Bookshop</h1><strong>Physical Stock Count</strong><div class="muted"><?= scrH($count['title']) ?></div></div>
<div style="text-align:right"><strong><?= scrH($count['count_number']) ?></strong><div><?= scrH($count['status']) ?></div><div class="muted"><?= scrH($count['count_date']) ?></div></div>
</header>
<div class="meta">
<div><span>Prepared by</span><strong><?= scrH($count['creator_name'] ?: '—') ?></strong></div>
<div><span>Approved by</span><strong><?= scrH($count['approver_name'] ?: 'Not approved') ?></strong></div>
<div><span>Approval date</span><strong><?= scrH($count['approved_at'] ?: '—') ?></strong></div>
</div>
<div class="metrics">
<div class="metric"><span>Products on count</span><strong><?= number_format($totalItems) ?></strong></div>
<div class="metric"><span>Counted</span><strong><?= number_format($countedItems) ?>/<?= number_format($totalItems) ?></strong></div>
<div class="metric"><span>Shortage items / units</span><strong><?= number_format($shortageItems) ?> / <?= number_format($shortageUnits) ?></strong></div>
<div class="metric"><span>Surplus items / units</span><strong><?= number_format($surplusItems) ?> / <?= number_format($surplusUnits) ?></strong></div>
<div class="metric"><span>Net variance (units)</span><strong><?= $netVariance > 0 ? '+' : '' ?><?= number_format($netVariance) ?></strong></div>
</div>
<p class="muted">Physical count is compared with the system quantity captured when this count session was opened. Blank physical quantities mean the item has not yet been counted. Enter zero where no stock is physically present.</p>
<table>
<thead><tr><th>#</th><th>Product</th><th>SKU / Barcode</th><th>Unit</th><th class="num">System quantity</th><th class="num">Physical quantity</th><th class="num">Variance</th><th>Counted by</th></tr></thead>
<tbody>
<?php foreach ($items as $index => $item):
    $variance = $item['counted_quantity'] === null ? null : (int)$item['counted_quantity'] - (int)$item['system_quantity'];
?>
<tr>
<td><?= $index + 1 ?></td>
<td><?= scrH($item['product_name'] ?: 'Product record unavailable') ?></td>
<td><?= scrH($item['sku'] ?: ($item['barcode'] ?: '—')) ?></td>
<td><?= scrH($item['unit'] ?: '—') ?></td>
<td class="num"><?= number_format((int)$item['system_quantity']) ?></td>
<td class="num"><?= $item['counted_quantity'] === null ? '' : number_format((int)$item['counted_quantity']) ?></td>
<td class="num"><?= $variance === null ? '' : (($variance > 0 ? '+' : '') . number_format($variance)) ?></td>
<td><?= scrH($item['counted_by_name'] ?: '—') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="note">
<strong>Control note:</strong> A count only changes inventory after an authorised user approves it. Approval updates product stock quantities and records each difference in Stock Movements, linked to this stock count reference. Cancelled counts do not change inventory.
</div>
<p class="muted">Generated from Dibonke Bookshop · <?= date('Y-m-d H:i') ?></p>
</body>
</html>
