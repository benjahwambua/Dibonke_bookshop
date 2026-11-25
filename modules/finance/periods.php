<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/audit.php';
requireModuleAccess('finance');
$ready=(bool)$conn->query("SHOW TABLES LIKE 'accounting_period_locks'")->fetchColumn();
$flash=$_SESSION['accounting_period_flash']??'';unset($_SESSION['accounting_period_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    requireModulePermission('finance','edit');
    try{
        if(!$ready)throw new RuntimeException('Import database/dibonke_accounting_period_locks.sql first.');
        $start=(string)($_POST['period_start']??'');$end=(string)($_POST['period_end']??'');$reason=trim((string)($_POST['reason']??''));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$start>$end)throw new RuntimeException('Enter a valid period start and end date.');
        if($reason===''||strlen($reason)>255)throw new RuntimeException('Enter a reason for closing this period (maximum 255 characters).');
        $conn->beginTransaction();
        $overlap=$conn->prepare("SELECT id FROM accounting_period_locks WHERE period_start<=? AND period_end>=? LIMIT 1 FOR UPDATE");
        $overlap->execute([$end,$start]);
        if($overlap->fetchColumn())throw new RuntimeException('This date range overlaps an existing locked accounting period.');
        $conn->prepare("INSERT INTO accounting_period_locks(period_start,period_end,reason,locked_by,locked_at) VALUES(?,?,?,?,NOW())")->execute([$start,$end,$reason,(int)$_SESSION['user_id']]);
        $lockId=(int)$conn->lastInsertId();
        recordAuditEvent($conn,'Lock Period','finance','Locked accounting period '.$start.' to '.$end.': '.$reason,'accounting_period_lock',$lockId);
        $conn->commit();$flash='Accounting period locked. New journals and automatic postings dated within this range will be rejected.';
    }catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$flash='Period could not be locked: '.$e->getMessage();}
    $_SESSION['accounting_period_flash']=$flash;header('Location:periods.php');exit;
}
$locks=$ready?$conn->query("SELECT l.*,u.full_name locked_by_name FROM accounting_period_locks l LEFT JOIN users u ON u.id=l.locked_by ORDER BY l.period_start DESC")->fetchAll(PDO::FETCH_ASSOC):[];
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<style>
.period-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:18px;margin:14px 0}.period-form{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.period-form label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:800}.period-form input{padding:10px;border:1px solid #cbd5e1;border-radius:7px}.period-btn{border:0;border-radius:7px;padding:10px 15px;background:#174a7e;color:#fff;font-weight:800}.period-table{width:100%;border-collapse:collapse}.period-table th,.period-table td{padding:10px;border-bottom:1px solid #edf1f5;text-align:left;font-size:12px}.period-table th{background:#f3f6fa}.muted{color:#64748b}
</style>
<h1>Accounting Period Controls</h1><p class="muted">Lock completed accounting periods to prevent new or backdated journals from changing closed balances.</p>
<nav style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0"><a href="index.php">Finance Overview</a><a href="posting_mappings.php">Posting Mappings</a><a href="journals.php">Journals</a><a href="general_ledger.php">General Ledger</a><a href="trial_balance.php">Trial Balance</a><a href="periods.php">Period Controls</a></nav>
<?php if($flash):?><div class="period-panel"><?=e($flash)?></div><?php endif;?>
<?php if(!$ready):?><section class="period-panel"><p>Database setup required. Import <code>database/dibonke_accounting_period_locks.sql</code>.</p></section><?php else:?>
<?php if(hasModulePermission('finance','edit')):?><section class="period-panel"><h2>Lock a completed period</h2><p class="muted">Locks cannot be removed from this screen. Review all reconciliations and reports before closing a period.</p><form method="post" class="period-form"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><label>Period start<input type="date" name="period_start" required></label><label>Period end<input type="date" name="period_end" required></label><label style="min-width:260px;flex:1">Reason<input name="reason" maxlength="255" required placeholder="e.g. November 2026 month-end closed"></label><button class="period-btn">Lock period</button></form></section><?php endif;?>
<section class="period-panel"><h2>Locked periods</h2><div style="overflow:auto"><table class="period-table"><thead><tr><th>Start</th><th>End</th><th>Reason</th><th>Locked by</th><th>Locked at</th></tr></thead><tbody><?php foreach($locks as $lock):?><tr><td><?=e($lock['period_start'])?></td><td><?=e($lock['period_end'])?></td><td><?=e($lock['reason'])?></td><td><?=e($lock['locked_by_name']??'System')?></td><td><?=e($lock['locked_at'])?></td></tr><?php endforeach;?><?php if(!$locks):?><tr><td colspan="5" class="muted">No accounting periods have been locked.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>
