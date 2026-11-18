<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/report_tools.php';
requireModuleAccess('expenses');

// Ensure the expense category master exists and default categories are active.
$conn->exec("CREATE TABLE IF NOT EXISTS expense_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_expense_category_name (category_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$defaultCategories=['Rent','Utilities','Salaries & Wages','Transport & Travel','Stationery & Office Supplies','Repairs & Maintenance','Internet & Communication','Marketing & Advertising','Licences & Permits','Bank Charges','Cleaning & Sanitation','Security','Packaging & Shopping Bags','Delivery & Courier','General & Miscellaneous'];
$findCategory=$conn->prepare("SELECT id FROM expense_categories WHERE LOWER(category_name)=LOWER(?) LIMIT 1");
$insertCategory=$conn->prepare("INSERT INTO expense_categories(category_name,status) VALUES(?,'Active')");
foreach($defaultCategories as $category){
    $findCategory->execute([$category]);
    if(!$findCategory->fetchColumn()) $insertCategory->execute([$category]);
}
$cats=$conn->query("SELECT id,category_name FROM expense_categories WHERE status='Active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

$from=(string)($_GET['from']??date('Y-m-01'));$to=(string)($_GET['to']??date('Y-m-d'));
$valid='/^\\d{4}-\\d{2}-\\d{2}$/';if(!preg_match($valid,$from))$from=date('Y-m-01');if(!preg_match($valid,$to))$to=date('Y-m-d');if($from>$to){[$from,$to]=[$to,$from];}
$categoryId=(int)($_GET['category_id']??0);$search=trim((string)($_GET['q']??''));
$where=['e.expense_date BETWEEN ? AND ?'];$params=[$from,$to];
if($categoryId>0){$where[]='e.category_id=?';$params[]=$categoryId;}
if($search!==''){$where[]='(e.expense_title LIKE ? OR e.expense_number LIKE ? OR e.description LIKE ?)';$like='%'.$search.'%';array_push($params,$like,$like,$like);}
$sql="SELECT e.*,ec.category_name,u.full_name FROM expenses e LEFT JOIN expense_categories ec ON ec.id=e.category_id LEFT JOIN users u ON u.id=e.user_id WHERE ".implode(' AND ',$where)." ORDER BY e.expense_date DESC,e.id DESC";
$q=$conn->prepare($sql);$q->execute($params);$expenses=$q->fetchAll(PDO::FETCH_ASSOC);
$total=array_sum(array_map(static fn($r)=>(float)$r['amount'],$expenses));
$exportRows=[];foreach($expenses as $e){$exportRows[]=[$e['expense_number'],$e['expense_date'],$e['expense_title'],$e['category_name']??'',$e['amount'],$e['payment_method'],$e['full_name']??'',$e['description']??''];}
dibonkeReportExport('dibonke_expenses_'.$from.'_to_'.$to,['Expense No.','Date','Title','Category','Amount','Payment Method','Recorded By','Description'],$exportRows);
$month=(float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE())")->fetchColumn();
$today=(float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date=CURDATE()")->fetchColumn();
$flash=$_SESSION['expense_flash']??null;unset($_SESSION['expense_flash']);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content">
<?php dibonkeReportPrintStyles(); ?>
<style>
.finance-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0 20px;padding:8px;background:#eef2f7;border-radius:11px}.finance-tabs a{padding:10px 14px;border-radius:8px;text-decoration:none;color:#40546a;font-size:12px;font-weight:800}.finance-tabs a.active{background:#17324d;color:#fff}
.grid{display:grid;grid-template-columns:340px 1fr;gap:18px}.panel{background:#fff;padding:20px;border-radius:12px;box-shadow:0 1px 4px #0001}.stats{display:flex;gap:15px;margin:15px 0}.stat{background:#fff;padding:15px;border-radius:10px;flex:1}.stat strong{display:block;font-size:22px}.field{margin:10px 0}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}.field input,.field select{width:100%;padding:10px;box-sizing:border-box;border:1px solid #dbe3ec;border-radius:7px}.btn{border:0;border-radius:7px;padding:11px 15px;background:#004a99;color:#fff;font-weight:700;cursor:pointer}.flash{padding:12px;background:#ecfdf5;color:#166534;border-radius:8px;margin-bottom:12px}.table{width:100%;border-collapse:collapse}.table th{background:#004a99;color:#fff;padding:10px;text-align:left}.table td{padding:10px;border-bottom:1px solid #e5e7eb;font-size:13px}.filters{display:flex;gap:9px;flex-wrap:wrap;align-items:end;margin:12px 0}.filters label{font-size:11px;font-weight:700}.filters input,.filters select{display:block;padding:9px;border:1px solid #dbe3ec;border-radius:7px}@media(max-width:1000px){.grid{grid-template-columns:1fr}}@media(max-width:900px){.stats{flex-direction:column}}
</style>
<nav class="finance-tabs"><a href="<?=BASE_URL?>/modules/finance/index.php">Overview</a><a href="<?=BASE_URL?>/modules/finance/ledger.php">Ledger</a><a href="<?=BASE_URL?>/modules/finance/control.php">Financial Control</a><a class="active" href="<?=BASE_URL?>/modules/expenses/index.php">Expenses</a></nav>
<h1>Expenses</h1><p>Record and monitor business operating expenses.</p>
<?php if($flash):?><div class="flash"><?=e($flash)?></div><?php endif;?>
<div class="stats"><div class="stat"><strong>KES <?=number_format($today,2)?></strong><small>Today</small></div><div class="stat"><strong>KES <?=number_format($month,2)?></strong><small>This Month</small></div><div class="stat"><strong>KES <?=number_format($total,2)?></strong><small>Filtered Total</small></div></div>
<div class="grid">
<div class="panel"><h3>Record Expense</h3><form method="post" action="store.php"><input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
<div class="field"><label>Expense Title</label><input name="expense_title" required maxlength="150"></div>
<div class="field"><label>Category <a href="<?=BASE_URL?>/modules/expenses/categories.php" style="float:right;font-size:11px">Manage categories</a></label><select name="category_id" required><?php if(!$cats):?><option value="">No active categories available</option><?php else:foreach($cats as $index=>$c):?><option value="<?=(int)$c['id']?>" <?=$index===0?'selected':''?>><?=e($c['category_name'])?></option><?php endforeach;endif;?></select></div>
<div class="field"><label>Amount</label><input type="number" name="amount" min=".01" step=".01" required></div>
<div class="field"><label>Payment Method</label><select name="payment_method"><option>Cash</option><option>M-Pesa</option><option>Bank</option><option>Cheque</option><option>Other</option></select></div>
<div class="field"><label>Date</label><input type="date" name="expense_date" value="<?=date('Y-m-d')?>" required></div>
<div class="field"><label>Description</label><input name="description" maxlength="500"></div><button class="btn" <?=$cats?'':'disabled'?>>Save Expense</button></form></div>
<div class="panel"><h3>Expense Register</h3>
<form class="filters" method="get"><label>From<input type="date" name="from" value="<?=e($from)?>"></label><label>To<input type="date" name="to" value="<?=e($to)?>"></label><label>Category<select name="category_id"><option value="0">All categories</option><?php foreach($cats as $c):?><option value="<?=(int)$c['id']?>" <?=$categoryId===(int)$c['id']?'selected':''?>><?=e($c['category_name'])?></option><?php endforeach;?></select></label><label>Search<input name="q" value="<?=e($search)?>" placeholder="Title or reference"></label><button class="btn">Filter</button><a href="index.php">Reset</a></form>
<?php dibonkeReportActions('expenses'); ?>
<div style="overflow:auto"><table class="table"><thead><tr><th>Number</th><th>Date</th><th>Title</th><th>Category</th><th>Amount</th><th>Method</th><th>Recorded By</th></tr></thead><tbody><?php foreach($expenses as $e):?><tr><td><?=e($e['expense_number'])?></td><td><?=e($e['expense_date'])?></td><td><?=e($e['expense_title'])?></td><td><?=e($e['category_name']??'')?></td><td>KES <?=number_format((float)$e['amount'],2)?></td><td><?=e($e['payment_method'])?></td><td><?=e($e['full_name']??'')?></td></tr><?php endforeach;?><?php if(!$expenses):?><tr><td colspan="7">No expenses match the selected filters.</td></tr><?php endif;?></tbody></table></div></div></div></main>
<?php require_once __DIR__.'/../../includes/footer.php';?>
