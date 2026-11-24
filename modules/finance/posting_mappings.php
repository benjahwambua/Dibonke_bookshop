<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/accounting_posting.php';
requireModuleAccess('accounting_mappings');

$tables = array_flip($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
$ready = isset($tables['accounts'], $tables['accounting_mappings'], $tables['expense_category_accounting'], $tables['expense_categories'], $tables['journal_entries'], $tables['journal_lines']);
$flash = $_SESSION['accounting_mapping_flash'] ?? '';
unset($_SESSION['accounting_mapping_flash']);

$definitions = [
 'cash_on_hand'=>['Cash on hand','Asset'],
 'mpesa_clearing'=>['M-Pesa clearing','Asset'],
 'bank_account'=>['Bank account','Asset'],
 'other_clearing'=>['Other / mixed payment clearing','Asset'],
 'accounts_receivable'=>['Accounts receivable','Asset'],
 'inventory'=>['Inventory','Asset'],
 'accounts_payable'=>['Accounts payable','Liability'],
 'vat_payable'=>['Output VAT payable','Liability'],
 'sales_revenue'=>['Sales revenue','Income'],
 'sales_returns'=>['Sales returns and refunds','Income'],
 'refund_payable'=>['Refunds payable to customers','Liability'],
 'cost_of_goods_sold'=>['Cost of goods sold','Expense'],
 'input_vat'=>['Input VAT recoverable','Asset'],
 'unallocated_receipts'=>['Unallocated M-Pesa receipts','Liability'],
 'default_expense'=>['Default operating expense','Expense'],
];

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    requireModulePermission('accounting_mappings','edit');
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save') {
            $conn->beginTransaction();
            $upsert = $conn->prepare("INSERT INTO accounting_mappings(mapping_key,account_id,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),updated_by=VALUES(updated_by)");
            foreach ($definitions as $key=>$meta) {
                $accountId = (int)($_POST['mapping'][$key] ?? 0);
                $check = $conn->prepare("SELECT id FROM accounts WHERE id=? AND account_type=? AND status='Active' FOR UPDATE");
                $check->execute([$accountId,$meta[1]]);
                if (!$check->fetchColumn()) throw new RuntimeException('Select an active '.$meta[1].' account for '.$meta[0].'.');
                $upsert->execute([$key,$accountId,(int)$_SESSION['user_id']]);
            }
            $categoryUpsert = $conn->prepare("INSERT INTO expense_category_accounting(category_id,account_id,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE account_id=VALUES(account_id),updated_by=VALUES(updated_by)");
            $categoryDelete = $conn->prepare("DELETE FROM expense_category_accounting WHERE category_id=?");
            $categoryCheck = $conn->prepare("SELECT id FROM accounts WHERE id=? AND account_type='Expense' AND status='Active'");
            foreach (($_POST['expense_account'] ?? []) as $categoryId=>$accountId) {
                $categoryId=(int)$categoryId; $accountId=(int)$accountId;
                if($categoryId<=0) continue;
                if($accountId<=0){$categoryDelete->execute([$categoryId]);continue;}
                $categoryCheck->execute([$accountId]);
                if(!$categoryCheck->fetchColumn()) throw new RuntimeException('Each expense category must map to an active Expense account.');
                $categoryUpsert->execute([$categoryId,$accountId,(int)$_SESSION['user_id']]);
            }
            $conn->commit();
            $flash='Accounting mappings saved. Review them before enabling automatic posting.';
        } elseif ($action === 'toggle') {
            $enabled = (string)($_POST['enabled'] ?? '0') === '1';
            if ($enabled) {
                foreach($definitions as $key=>$meta) dibonkeAccountingAccount($conn,$key,$meta[1]);
                $conn->beginTransaction();
                $conn->prepare("UPDATE settings SET setting_value='1' WHERE setting_key='accounting_auto_posting_enabled'")->execute();
                $conn->commit();
                $flash='Automatic double-entry posting is enabled for new transactions.';
            } else {
                $conn->beginTransaction();
                $conn->prepare("UPDATE settings SET setting_value='0' WHERE setting_key='accounting_auto_posting_enabled'")->execute();
                $conn->commit();
                $flash='Automatic double-entry posting is disabled. Existing posted journals are unchanged.';
            }
        } else throw new RuntimeException('Invalid accounting mapping action.');
    } catch(Throwable $e) {
        if($conn->inTransaction()) $conn->rollBack();
        $flash='Accounting settings could not be saved: '.$e->getMessage();
    }
    $_SESSION['accounting_mapping_flash']=$flash;
    header('Location: posting_mappings.php'); exit;
}

$enabled = $ready && (string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='accounting_auto_posting_enabled' LIMIT 1")->fetchColumn() ?: '0') === '1';
$accounts = $ready ? $conn->query("SELECT id,account_code,account_name,account_type FROM accounts WHERE status='Active' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC) : [];
$mapped = [];
$categoryMapped = [];
if($ready){
 foreach($conn->query("SELECT mapping_key,account_id FROM accounting_mappings")->fetchAll(PDO::FETCH_ASSOC) as $r) $mapped[$r['mapping_key']]=(int)$r['account_id'];
 foreach($conn->query("SELECT category_id,account_id FROM expense_category_accounting")->fetchAll(PDO::FETCH_ASSOC) as $r) $categoryMapped[(int)$r['category_id']]=(int)$r['account_id'];
}
$categories = $ready ? $conn->query("SELECT id,category_name FROM expense_categories WHERE status='Active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC) : [];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<main class="content">
<style>
.map-panel{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:18px;margin:14px 0}.map-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px}.map-field{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:#475569}.map-field select{padding:10px;border:1px solid #cbd5e1;border-radius:7px;background:#fff}.map-table{width:100%;border-collapse:collapse}.map-table th,.map-table td{text-align:left;padding:9px;border-bottom:1px solid #e5eaf0;font-size:12px}.map-btn{border:0;border-radius:7px;padding:10px 14px;background:#174a7e;color:#fff;font-weight:800;cursor:pointer}.map-note{color:#64748b;font-size:12px;line-height:1.5}.map-status{padding:12px;border-radius:8px;background:#eff6ff;color:#17324d;margin:12px 0}@media(max-width:700px){.map-grid{grid-template-columns:1fr}}
</style>
<h1>Automatic Posting Mappings</h1>
<p class="map-note">Choose the ledger accounts used when the system automatically posts sales, inventory purchases, supplier receipts and operating expenses. New journals are posted once and remain traceable to their source transaction.</p>
<nav style="display:flex;gap:8px;flex-wrap:wrap;margin:14px 0"><a href="chart_of_accounts.php">Chart of Accounts</a><a href="journals.php">Journal Entries</a><a href="general_ledger.php">General Ledger</a><a href="trial_balance.php">Trial Balance</a></nav>
<?php if($flash):?><div class="map-status"><?=e($flash)?></div><?php endif;?>
<?php if(!$ready):?><section class="map-panel"><h2>Database setup required</h2><p>Import the double-entry accounting and automatic posting migrations first.</p></section><?php else:?>
<form method="post" class="map-panel"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><h2>Core account mappings</h2><div class="map-grid">
<?php foreach($definitions as $key=>$meta):?><label class="map-field"><?=e($meta[0])?> <select name="mapping[<?=e($key)?>]" required><option value="">Select <?=e($meta[1])?> account</option><?php foreach($accounts as $a):if($a['account_type']!==$meta[1])continue;?><option value="<?=(int)$a['id']?>" <?=($mapped[$key]??0)===(int)$a['id']?'selected':''?>><?=e($a['account_code'].' — '.$a['account_name'])?></option><?php endforeach;?></select></label><?php endforeach;?>
</div><h2 style="margin-top:22px">Expense category mappings</h2><p class="map-note">Categories without a specific mapping use the Default operating expense account.</p><div style="overflow:auto"><table class="map-table"><thead><tr><th>Expense category</th><th>Expense account</th></tr></thead><tbody><?php foreach($categories as $category):?><tr><td><?=e($category['category_name'])?></td><td><select name="expense_account[<?=(int)$category['id']?>]" required><option value="0">Use default account</option><?php foreach($accounts as $a):if($a['account_type']!=='Expense')continue;?><option value="<?=(int)$a['id']?>" <?=($categoryMapped[(int)$category['id']]??0)===(int)$a['id']?'selected':''?>><?=e($a['account_code'].' — '.$a['account_name'])?></option><?php endforeach;?></select></td></tr><?php endforeach;?></tbody></table></div><button class="map-btn" style="margin-top:14px">Save mappings</button></form>
<section class="map-panel"><h2>Automatic posting control</h2><p>Posting is currently <strong><?=$enabled?'ENABLED':'DISABLED'?></strong>. Enable only after reviewing every account mapping. This affects new transactions; it does not backfill older records.</p><form method="post" onsubmit="return confirm('Change automatic accounting posting status?')"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="enabled" value="<?=$enabled?'0':'1'?>"><button class="map-btn"><?=$enabled?'Disable automatic posting':'Enable automatic posting'?></button></form></section>
<?php endif;?>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php';?>
