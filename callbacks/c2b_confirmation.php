<?php
declare(strict_types=1);
require_once __DIR__.'/../config/app.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/accounting_posting.php';
require_once __DIR__.'/../includes/audit.php';
header('Content-Type: application/json');
$raw=file_get_contents('php://input')?:'';
$data=json_decode($raw,true);
try{
 if(!is_array($data))throw new RuntimeException('Invalid callback payload.');
 $shortcode=trim((string)($data['BusinessShortCode']??''));
 $configured=$conn->prepare("SELECT setting_value FROM settings WHERE setting_key='mpesa_shortcode' LIMIT 1");$configured->execute();$expected=(string)($configured->fetchColumn()?:'');
 if($expected===''||$shortcode===''||$shortcode!==$expected)throw new RuntimeException('M-PESA shortcode is missing or does not match the configured business shortcode.');
 $transId=trim((string)($data['TransID']??''));$amount=(float)($data['TransAmount']??0);$billRef=trim((string)($data['BillRefNumber']??''));$phone=preg_replace('/\D+/','',(string)($data['MSISDN']??''))??'';
 if($transId===''||$amount<=0)throw new RuntimeException('Missing transaction ID or amount.');
 $when=DateTime::createFromFormat('YmdHis',(string)($data['TransTime']??''));$transactionDate=$when?$when->format('Y-m-d H:i:s'):date('Y-m-d H:i:s');
 $conn->beginTransaction();
 $exists=$conn->prepare("SELECT id FROM mpesa_transactions WHERE transaction_type='C2B' AND transaction_reference=? LIMIT 1 FOR UPDATE");$exists->execute([$transId]);
 if($exists->fetchColumn()){$conn->commit();echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Already received']);exit;}
 $sale=null;$allocated=0.0;$customerCredit=0.0;$matchReason='';
 if($billRef!==''){
  $q=$conn->prepare("SELECT * FROM sales WHERE invoice_number=? AND sale_status='Completed' LIMIT 1 FOR UPDATE");$q->execute([$billRef]);$candidate=$q->fetch();
  if($candidate&&(float)$candidate['balance']>0&&(!empty($candidate['customer_id'])||(float)$candidate['balance']+0.001>=$amount)){$sale=$candidate;$matchReason='Matched by invoice reference';}
 }
 if(!$sale&&$phone!==''){
  $q=$conn->query("SELECT s.*,c.phone AS customer_phone FROM sales s JOIN customers c ON c.id=s.customer_id WHERE s.sale_status='Completed' AND s.balance>0 ORDER BY s.sale_date DESC LIMIT 500");
  $matches=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate){
   $customerPhone=preg_replace('/\D+/','',(string)$candidate['customer_phone'])??'';
   if(str_starts_with($customerPhone,'0'))$customerPhone='254'.substr($customerPhone,1);
   if(str_starts_with($customerPhone,'7'))$customerPhone='254'.$customerPhone;
   if($customerPhone===$phone&&abs((float)$candidate['balance']-$amount)<0.01)$matches[]=$candidate;
  }
  if(count($matches)===1){$sale=$matches[0];$matchReason='Unique exact-balance match by customer phone';}
 }
 $referenceType=null;$referenceId=null;$description='Received till/Paybill payment; awaiting invoice allocation.';
 if($sale){
  $lock=$conn->prepare("SELECT * FROM sales WHERE id=? AND sale_status='Completed' FOR UPDATE");$lock->execute([(int)$sale['id']]);$sale=$lock->fetch();
  if(!$sale||(float)$sale['balance']<=0||(empty($sale['customer_id'])&&(float)$sale['balance']+0.001<$amount))$sale=null;
 }
 if($sale){
  $allocated=round(min($amount,(float)$sale['balance']),2);
  $customerCredit=round(max(0,$amount-$allocated),2);
  $referenceId=(int)$sale['id'];
  $upd=$conn->prepare("UPDATE sales SET paid_amount=LEAST(total_amount,paid_amount+?),balance=GREATEST(0,balance-?) WHERE id=?");$upd->execute([$allocated,$allocated,$referenceId]);
  if(!empty($sale['customer_id'])){
   $tmp='TMP-'.strtoupper(bin2hex(random_bytes(8)));
   $ins=$conn->prepare("INSERT INTO customer_payments(receipt_number,customer_id,sale_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES(?,?,?,?,?,?,?,?,?)");
   $ins->execute([$tmp,(int)$sale['customer_id'],$referenceId,(int)$sale['user_id'],$amount,'M-Pesa',$transId,$transactionDate,'Automatic C2B till payment: '.$matchReason]);
   $paymentId=(int)$conn->lastInsertId();$receipt='CR-'.date('ymd').'-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$receipt,$paymentId]);
   if($allocated>0)$conn->prepare("INSERT INTO customer_payment_allocations(payment_id,sale_id,amount) VALUES(?,?,?)")->execute([$paymentId,$referenceId,$allocated]);
   if($customerCredit>0)$conn->prepare("INSERT INTO customer_credit_ledger(customer_id,payment_id,entry_type,amount,notes,created_by) VALUES(?,?,'Credit',?,'C2B invoice overpayment retained as customer credit',?)")->execute([(int)$sale['customer_id'],$paymentId,$customerCredit,(int)$sale['user_id']]);
   $conn->prepare("UPDATE customers SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$allocated,(int)$sale['customer_id']]);
   $referenceType='customer_payment';$referenceId=$paymentId;
  }else{
   $referenceType='sale';
  }
  $description=$matchReason.' for invoice '.$sale['invoice_number'];
 }
 $ins=$conn->prepare("INSERT INTO mpesa_transactions(transaction_type,direction,phone_number,amount,account_reference,transaction_reference,result_code,result_description,result_status,reference_type,reference_id,raw_response,transaction_date) VALUES('C2B','Incoming',?,?,?,?,? ,?,'Success',?,?,?,?)");
 $ins->execute([$phone,$amount,$billRef,$transId,'0',$description,$referenceType,$referenceId,$raw,$transactionDate]);
 if(dibonkeAccountingAutoPostingEnabled($conn)){
  $transactionId=(int)$conn->lastInsertId();
  $journalLines=[dibonkeAccountingLine(dibonkeAccountingAccount($conn,'mpesa_clearing','Asset'),$allocated>0?'C2B payment received':'Unallocated C2B payment received',$amount,0)];
  if($allocated>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'accounts_receivable','Asset'),'Invoice receivable settled',0,$allocated);
  if($customerCredit>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'customer_credit','Liability'),'C2B invoice overpayment retained as customer credit',0,$customerCredit);
  if($allocated<=0 && $customerCredit<=0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'unallocated_receipts','Liability'),'Customer receipt awaiting allocation',0,$amount);
  dibonkeAccountingPostJournal($conn,'mpesa_c2b_receipt',$transactionId,date('Y-m-d',strtotime($transactionDate)),'M-Pesa C2B receipt '.$transId,$journalLines,null);
 }
 $transactionId=(int)$conn->lastInsertId();
 recordAuditEvent($conn,'Receive','mpesa_transactions','Received C2B M-PESA transaction '.$transId,'mpesa_transaction',$transactionId,null,['amount'=>$amount,'reference_type'=>$referenceType,'reference_id'=>$referenceId,'allocated_amount'=>$allocated,'customer_credit'=>$customerCredit,'bill_reference'=>$billRef]);
 $conn->commit();
 echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']);
}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();error_log('Dibonke C2B confirmation: '.$e->getMessage());http_response_code(500);echo json_encode(['ResultCode'=>1,'ResultDesc'=>'Processing failed; confirmation was not acknowledged.']);}
