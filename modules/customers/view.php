<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../includes/report_tools.php';
require_once __DIR__.'/../../includes/accounting_posting.php';
require_once __DIR__.'/../../includes/audit.php';
requireModuleAccess('customers');
$id=(int)($_GET['id']??$_POST['customer_id']??0);
$creditFlash=$_SESSION['customer_credit_flash']??'';unset($_SESSION['customer_credit_flash']);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $action=(string)($_POST['credit_action']??'');
    try{
        if(!in_array($action,['apply','refund'],true))throw new RuntimeException('Invalid customer credit action.');
        requireModulePermission('customer_payments',$action==='apply'?'create':'edit');
        foreach(['customer_credit_ledger','customer_credit_applications','customer_credit_refunds','customer_payment_allocations'] as $table){
            if(!(bool)$conn->query("SHOW TABLES LIKE ".$conn->quote($table))->fetchColumn())throw new RuntimeException('Customer credit workflow migration is incomplete. Apply database/dibonke_customer_credit_workflows.sql.');
        }
        $conn->beginTransaction();
        $customerLock=$conn->prepare("SELECT id,customer_name,balance FROM customers WHERE id=? AND status='Active' FOR UPDATE");
        $customerLock->execute([$id]);$lockedCustomer=$customerLock->fetch(PDO::FETCH_ASSOC);
        if(!$lockedCustomer)throw new RuntimeException('Customer not found or inactive.');
        $sources=$conn->prepare("SELECT cr.id,cr.payment_id,cr.amount-COALESCE((SELECT SUM(db.amount) FROM customer_credit_ledger db WHERE db.payment_id=cr.payment_id AND db.customer_id=cr.customer_id AND db.entry_type='Debit'),0) remaining FROM customer_credit_ledger cr WHERE cr.customer_id=? AND cr.entry_type='Credit' ORDER BY cr.created_at,cr.id FOR UPDATE");
        $sources->execute([$id]);$creditSources=$sources->fetchAll(PDO::FETCH_ASSOC);
        $availableCredit=0.0;foreach($creditSources as $source)$availableCredit+=max(0,(float)$source['remaining']);
        $amount=round((float)($_POST['amount']??0),2);
        if($amount<=0||$amount>$availableCredit+0.009)throw new RuntimeException('Enter an amount within the available customer credit of KES '.number_format($availableCredit,2).'.');
        $remaining=$amount;$uid=(int)$_SESSION['user_id'];$debitIds=[];$lastRecordId=0;
        if($action==='apply'){
            $saleId=(int)($_POST['sale_id']??0);
            $invoice=$conn->prepare("SELECT id,invoice_number,balance FROM sales WHERE id=? AND customer_id=? AND sale_status='Completed' AND balance>0.009 FOR UPDATE");
            $invoice->execute([$saleId,$id]);$sale=$invoice->fetch(PDO::FETCH_ASSOC);
            if(!$sale)throw new RuntimeException('Select an outstanding invoice for this customer.');
            if($amount>(float)$sale['balance']+0.009)throw new RuntimeException('The credit amount cannot exceed the invoice balance of KES '.number_format((float)$sale['balance'],2).'.');
            $debitInsert=$conn->prepare("INSERT INTO customer_credit_ledger(customer_id,payment_id,entry_type,amount,notes,created_by) VALUES(?,?,'Debit',?,'Applied to invoice',?)");
            $appInsert=$conn->prepare("INSERT INTO customer_credit_applications(customer_id,credit_entry_id,debit_entry_id,sale_id,amount,created_by) VALUES(?,?,?,?,?,?)");
            foreach($creditSources as $source){
                if($remaining<=0.009)break;$use=round(min($remaining,max(0,(float)$source['remaining'])),2);if($use<=0)continue;
                $debitInsert->execute([$id,(int)$source['payment_id'],$use,$uid]);$debitId=(int)$conn->lastInsertId();
                $appInsert->execute([$id,(int)$source['id'],$debitId,$saleId,$use,$uid]);$lastRecordId=(int)$conn->lastInsertId();$debitIds[]=$debitId;$remaining=round($remaining-$use,2);
            }
            if($remaining>0.009)throw new RuntimeException('Customer credit could not be fully allocated.');
            $conn->prepare("UPDATE sales SET paid_amount=LEAST(total_amount,paid_amount+?),balance=GREATEST(0,balance-?) WHERE id=?")->execute([$amount,$amount,$saleId]);
            $conn->prepare("UPDATE customers SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$amount,$id]);
            if(dibonkeAccountingAutoPostingEnabled($conn)){
                $lines=[dibonkeAccountingLine(dibonkeAccountingAccount($conn,'customer_credit','Liability'),'Customer wallet credit applied', $amount,0),dibonkeAccountingLine(dibonkeAccountingAccount($conn,'accounts_receivable','Asset'),'Customer invoice '.$sale['invoice_number'].' settled from wallet',0,$amount)];
                dibonkeAccountingPostJournal($conn,'customer_credit_application',$lastRecordId,date('Y-m-d'),'Customer credit applied to '.$sale['invoice_number'],$lines,$uid);
            }
            recordAuditEvent($conn,'Apply Credit','customers','Applied KES '.number_format($amount,2).' customer credit to invoice '.$sale['invoice_number'],'customer_credit_application',$lastRecordId,null,['customer_id'=>$id,'sale_id'=>$saleId,'amount'=>$amount,'debit_entries'=>$debitIds]);
            $creditFlash='KES '.number_format($amount,2).' customer credit applied to invoice '.$sale['invoice_number'].'.';
        }else{
            requireModulePermission('customer_payments','edit');
            $method=(string)($_POST['payment_method']??'');
            if(!in_array($method,['Cash','Bank','M-Pesa'],true))throw new RuntimeException('Choose Cash, Bank or M-PESA for the refund.');
            if($method==='Cash'&&hasModulePermission('cash_management','create')&&(bool)$conn->query("SHOW TABLES LIKE 'cash_shift_guard'")->fetchColumn()){
                $shift=$conn->prepare("SELECT id FROM cash_shifts WHERE user_id=? AND status='Open' LIMIT 1 FOR UPDATE");$shift->execute([$uid]);if(!$shift->fetchColumn())throw new RuntimeException('Open your cashier shift before refunding customer credit in cash.');
            }
            $reference=trim((string)($_POST['transaction_reference']??''));
            $debitInsert=$conn->prepare("INSERT INTO customer_credit_ledger(customer_id,payment_id,entry_type,amount,notes,created_by) VALUES(?,?,'Debit',?,'Customer credit refunded',?)");
            $refundInsert=$conn->prepare("INSERT INTO customer_credit_refunds(customer_id,credit_entry_id,debit_entry_id,amount,payment_method,transaction_reference,created_by) VALUES(?,?,?,?,?,?,?)");
            foreach($creditSources as $source){
                if($remaining<=0.009)break;$use=round(min($remaining,max(0,(float)$source['remaining'])),2);if($use<=0)continue;
                $debitInsert->execute([$id,(int)$source['payment_id'],$use,$uid]);$debitId=(int)$conn->lastInsertId();
                $refundInsert->execute([$id,(int)$source['id'],$debitId,$use,$method,$reference?:null,$uid]);$lastRecordId=(int)$conn->lastInsertId();$debitIds[]=$debitId;$remaining=round($remaining-$use,2);
            }
            if($remaining>0.009)throw new RuntimeException('Customer credit could not be fully refunded.');
            if(dibonkeAccountingAutoPostingEnabled($conn)){
                $lines=[dibonkeAccountingLine(dibonkeAccountingAccount($conn,'customer_credit','Liability'),'Customer credit refund', $amount,0),dibonkeAccountingLine(dibonkeAccountingPaymentAccount($conn,$method),'Refund paid to customer',0,$amount)];
                dibonkeAccountingPostJournal($conn,'customer_credit_refund',$lastRecordId,date('Y-m-d'),'Customer credit refund '.$lockedCustomer['customer_name'],$lines,$uid);
            }
            recordAuditEvent($conn,'Refund Credit','customers','Refunded KES '.number_format($amount,2).' customer credit via '.$method,'customer_credit_refund',$lastRecordId,null,['customer_id'=>$id,'amount'=>$amount,'payment_method'=>$method,'reference'=>$reference?:null,'debit_entries'=>$debitIds]);
            $creditFlash='KES '.number_format($amount,2).' customer credit refunded via '.$method.'.';
        }
        $conn->commit();
    }catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$creditFlash='Customer credit action failed: '.$e->getMessage();}
    $_SESSION['customer_credit_flash']=$creditFlash;
    header('Location: view.php?id='.$id);exit;
}
$st=$conn->prepare("SELECT * FROM customers WHERE id=?");$st->execute([$id]);$customer=$st->fetch(PDO::FETCH_ASSOC);
if(!$customer){http_response_code(404);exit('Customer not found.');}
$from=(string)($_GET['from']??date('Y-01-01'));$to=(string)($_GET['to']??date('Y-m-d'));$valid='/^\\d{4}-\\d{2}-\\d{2}$/';
if(!preg_match($valid,$from))$from=date('Y-01-01');if(!preg_match($valid,$to))$to=date('Y-m-d');if($from>$to){[$from,$to]=[$to,$from];}
$hasPaymentAllocations=(bool)$conn->query("SHOW TABLES LIKE 'customer_payment_allocations'")->fetchColumn();
$hasCustomerCreditLedger=(bool)$conn->query("SHOW TABLES LIKE 'customer_credit_ledger'")->fetchColumn();
$paymentJoins='';
if($hasPaymentAllocations)$paymentJoins.=" LEFT JOIN (SELECT payment_id,SUM(amount) applied_amount FROM customer_payment_allocations GROUP BY payment_id) pa ON pa.payment_id=cp.id";
if($hasCustomerCreditLedger)$paymentJoins.=" LEFT JOIN (SELECT DISTINCT payment_id FROM customer_credit_ledger) pcl ON pcl.payment_id=cp.id";
if($hasPaymentAllocations){
    $paymentApplied="CASE WHEN pa.payment_id IS NOT NULL THEN pa.applied_amount ".($hasCustomerCreditLedger?"WHEN pcl.payment_id IS NOT NULL THEN 0 ":"")."ELSE cp.amount END";
}elseif($hasCustomerCreditLedger){
    $paymentApplied="CASE WHEN pcl.payment_id IS NOT NULL THEN 0 ELSE cp.amount END";
}else{
    $paymentApplied="cp.amount";
}
$openingQ=$conn->prepare("SELECT COALESCE((SELECT SUM(total_amount) FROM sales WHERE customer_id=? AND sale_status='Completed' AND DATE(sale_date)<?),0)-COALESCE((SELECT SUM({$paymentApplied}) FROM customer_payments cp {$paymentJoins} WHERE cp.customer_id=? AND DATE(cp.payment_date)<?),0)");
$openingQ->execute([$id,$from,$id,$from]);$opening=(float)$openingQ->fetchColumn();
$statementSql="SELECT * FROM (
 SELECT s.sale_date transaction_date,'Invoice' transaction_type,s.invoice_number reference,CONCAT('Sale invoice ',s.invoice_number) description,s.total_amount debit,0 credit,s.id sale_id,0 receipt_amount,0 customer_credit_amount
 FROM sales s WHERE s.customer_id=? AND s.sale_status='Completed' AND DATE(s.sale_date) BETWEEN ? AND ?
 UNION ALL
 SELECT cp.payment_date,'Payment',cp.receipt_number,CONCAT('Customer payment ',COALESCE(cp.transaction_reference,''),' ',COALESCE(cp.notes,'')),0,{$paymentApplied},cp.sale_id,cp.amount,GREATEST(0,cp.amount-({$paymentApplied}))
 FROM customer_payments cp {$paymentJoins} WHERE cp.customer_id=? AND DATE(cp.payment_date) BETWEEN ? AND ?
) statement_rows ORDER BY transaction_date ASC,transaction_type ASC,reference ASC";
$q=$conn->prepare($statementSql);$q->execute([$id,$from,$to,$id,$from,$to]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
$customerCreditBalance=0.0;
if($hasCustomerCreditLedger){
    $creditQ=$conn->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='Credit' THEN amount ELSE -amount END),0) FROM customer_credit_ledger WHERE customer_id=?");
    $creditQ->execute([$id]);$customerCreditBalance=(float)$creditQ->fetchColumn();
}
$running=$opening;$debitTotal=0.0;$creditTotal=0.0;foreach($rows as &$row){$debitTotal+=(float)$row['debit'];$creditTotal+=(float)$row['credit'];$running+=(float)$row['debit']-(float)$row['credit'];$row['running']=$running;}unset($row);
$invoiceQ=$conn->prepare("SELECT id,invoice_number,sale_date,total_amount,paid_amount,balance,payment_method FROM sales WHERE customer_id=? AND sale_status='Completed' ORDER BY sale_date DESC,id DESC");
$invoiceQ->execute([$id]);$invoices=$invoiceQ->fetchAll(PDO::FETCH_ASSOC);
$currency=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='currency' LIMIT 1")->fetchColumn()?:'KES');
foreach($rows as &$row){
    if($row['transaction_type']==='Payment'){
        $row['description'].=' — received '.$currency.' '.number_format((float)$row['receipt_amount'],2).'; applied to invoices '.$currency.' '.number_format((float)$row['credit'],2);
        if((float)$row['customer_credit_amount']>0.009)$row['description'].='; retained as customer credit '.$currency.' '.number_format((float)$row['customer_credit_amount'],2);
    }
}
unset($row);
$exportRows=[];foreach($rows as $r){$exportRows[]=[$r['transaction_date'],$r['transaction_type'],$r['reference'],$r['description'],$r['debit'],$r['credit'],$r['running']];}
dibonkeReportExport('customer_statement_'.$id.'_'.$from.'_to_'.$to,['Date','Type','Reference','Description','Debit','Credit','Running Balance'],$exportRows);
require_once __DIR__.'/../../includes/header.php';require_once __DIR__.'/../../includes/sidebar.php';
?>
<main class="content"><?php dibonkeReportPrintStyles(); ?>
<style>.statement-card{background:#fff;border:1px solid #e5eaf0;border-radius:12px;padding:18px;margin:14px 0}.statement-filter{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.statement-filter label{font-size:12px;font-weight:700}.statement-filter input{display:block;padding:9px;border:1px solid #cbd5e1;border-radius:7px}.statement-table{width:100%;border-collapse:collapse}.statement-table th,.statement-table td{padding:10px;border-bottom:1px solid #e5eaf0;text-align:left;font-size:12px}.statement-table th{background:#f1f5f9}.num{text-align:right!important;white-space:nowrap}.muted{color:#64748b}.statement-btn{padding:9px 14px;border:0;border-radius:7px;background:#174a7e;color:#fff;text-decoration:none;font-weight:700}</style>
<h1>Customer Account</h1><p class="muted"><a href="index.php">Customers</a> / <?=e($customer['customer_name'])?></p>
<section class="statement-card"><h2><?=e($customer['customer_name'])?></h2><p><?=e($customer['phone']??'')?> <?=!empty($customer['email'])?' · '.e($customer['email']):''?></p><p><?=e($customer['address']??'')?></p><strong>Current outstanding: <?=e($currency)?> <?=number_format((float)$customer['balance'],2)?></strong><br><strong>Available customer credit: <?=e($currency)?> <?=number_format(max(0,$customerCreditBalance),2)?></strong><p class="muted">Customer credit is held separately from outstanding invoices and is not treated as sales revenue.</p></section>
<section class="statement-card"><h3>Prepare Customer Statement</h3><form class="statement-filter" method="get"><input type="hidden" name="id" value="<?=$id?>"><label>From<input type="date" name="from" value="<?=e($from)?>"></label><label>To<input type="date" name="to" value="<?=e($to)?>"></label><button class="statement-btn">Prepare Statement</button><a href="view.php?id=<?=$id?>">Reset</a></form>
<?php dibonkeReportActions('customer_statement'); ?>
<div style="overflow:auto"><table class="statement-table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
<tr><td colspan="6"><strong>Opening Balance</strong></td><td class="num"><strong><?=number_format($opening,2)?></strong></td></tr>
<?php foreach($rows as $r):?><tr><td><?=e($r['transaction_date'])?></td><td><?=e($r['transaction_type'])?></td><td><?php if($r['transaction_type']==='Invoice'&&$r['sale_id']):?><a href="<?=BASE_URL?>/modules/sales/receipt.php?id=<?=(int)$r['sale_id']?>"><?=e($r['reference'])?></a><?php else:?><?=e($r['reference'])?><?php endif;?></td><td><?=e($r['description'])?></td><td class="num"><?=number_format((float)$r['debit'],2)?></td><td class="num"><?=number_format((float)$r['credit'],2)?></td><td class="num"><?=number_format((float)$r['running'],2)?></td></tr><?php endforeach;?>
<tr><th colspan="4">Period Totals</th><th class="num"><?=number_format($debitTotal,2)?></th><th class="num"><?=number_format($creditTotal,2)?></th><th class="num"><?=number_format($running,2)?></th></tr>
<?php if(!$rows):?><tr><td colspan="7" class="muted">No transactions in this statement period.</td></tr><?php endif;?>
</tbody></table></div></section>
<section class="statement-card"><h3>All Customer Invoices</h3><div style="overflow:auto"><table class="statement-table"><thead><tr><th>Invoice</th><th>Date</th><th>Payment Method</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody><?php foreach($invoices as $inv):?><tr><td><a href="<?=BASE_URL?>/modules/sales/receipt.php?id=<?=(int)$inv['id']?>"><?=e($inv['invoice_number'])?></a></td><td><?=e($inv['sale_date'])?></td><td><?=e($inv['payment_method'])?></td><td class="num"><?=number_format((float)$inv['total_amount'],2)?></td><td class="num"><?=number_format((float)$inv['paid_amount'],2)?></td><td class="num"><?=number_format((float)$inv['balance'],2)?></td></tr><?php endforeach;?><?php if(!$invoices):?><tr><td colspan="6">No invoices in this period.</td></tr><?php endif;?></tbody></table></div></section>
</main><?php require_once __DIR__.'/../../includes/footer.php';?>