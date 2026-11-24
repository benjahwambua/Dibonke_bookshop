<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/accounting_posting.php';
require_once __DIR__ . '/../../includes/payment_allocation.php';

header('Content-Type: application/json');
$raw=file_get_contents('php://input')?:'';
$data=json_decode($raw,true);
try{
 $cb=$data['Body']['stkCallback']??null;
 if(!$cb)throw new RuntimeException('Invalid callback payload.');
 $merchant=(string)($cb['MerchantRequestID']??'');$checkout=(string)($cb['CheckoutRequestID']??'');if($merchant===''||$checkout==='')throw new RuntimeException('Callback is missing its Daraja request identifiers.');$code=(int)($cb['ResultCode']??-1);$desc=(string)($cb['ResultDesc']??'');
 $status=$code===0?'Success':'Failed';$amount=0;$phone='';$receipt='';$date=null;
 $conn->beginTransaction();
 $priorLookup=$conn->prepare("SELECT id,result_status,merchant_request_id FROM mpesa_transactions WHERE checkout_request_id=? LIMIT 1 FOR UPDATE");
 $priorLookup->execute([$checkout]);$priorCallback=$priorLookup->fetch(PDO::FETCH_ASSOC);
  if(!$priorCallback)throw new RuntimeException('Callback does not match a stored Dibonke STK request.');
  if((string)$priorCallback['merchant_request_id']!==$merchant)throw new RuntimeException('Callback merchant request ID does not match the stored STK request.');
 $alreadyProcessed=$priorCallback['result_status']==='Success';
 foreach(($cb['CallbackMetadata']['Item']??[]) as $item){$name=$item['Name']??'';$value=$item['Value']??null;if($name==='Amount')$amount=(float)$value;elseif($name==='MpesaReceiptNumber')$receipt=(string)$value;elseif($name==='PhoneNumber')$phone=(string)$value;elseif($name==='TransactionDate'&&$value)$date=DateTime::createFromFormat('YmdHis',(string)$value)?->format('Y-m-d H:i:s');}
  if($alreadyProcessed){$conn->commit();echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Duplicate confirmation already processed']);exit;}
 $appliedAmount=0.0;$unallocatedAmount=0.0;
  if($code===0 && ($amount<=0 || $receipt===''))throw new RuntimeException('Successful STK callback is missing a valid amount or M-PESA receipt number.');
 $st=$conn->prepare("INSERT INTO mpesa_transactions(transaction_type,direction,phone_number,amount,transaction_reference,merchant_request_id,checkout_request_id,result_code,result_description,result_status,raw_response,transaction_date) VALUES('STK Push','Incoming',?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE result_code=VALUES(result_code),result_description=VALUES(result_description),result_status=VALUES(result_status),transaction_reference=VALUES(transaction_reference),phone_number=VALUES(phone_number),amount=VALUES(amount),raw_response=VALUES(raw_response),transaction_date=VALUES(transaction_date)");
 $st->execute([$phone,$amount,$receipt,$merchant,$checkout,(string)$code,$desc,$status,$raw,$date]);
 if($code===0 && !$alreadyProcessed){
   $lookup=$conn->prepare("SELECT id,reference_type,reference_id FROM mpesa_transactions WHERE checkout_request_id=? LIMIT 1");
   $lookup->execute([$checkout]);$tx=$lookup->fetch();
   if($tx && $tx['reference_type']==='customer_payment' && (int)$tx['reference_id']>0){
      $customerId=(int)$tx['reference_id'];
      $custQ=$conn->prepare("SELECT * FROM customers WHERE id=? FOR UPDATE");$custQ->execute([$customerId]);$customer=$custQ->fetch();
      if(!$customer)throw new RuntimeException('The customer linked to this STK payment no longer exists. The confirmation was not acknowledged.');
      if($amount>0){
         $payAmount=round($amount,2);
         if($payAmount>0){
            $rc='TMP-'.strtoupper(bin2hex(random_bytes(8)));
            $exists=$conn->prepare("SELECT COUNT(*) FROM customer_payments WHERE transaction_reference=?");$exists->execute([$receipt]);
            if((int)$exists->fetchColumn()>0)throw new RuntimeException('This M-PESA receipt number has already been recorded against another payment. Manual review is required.');
            {
               $ins=$conn->prepare("INSERT INTO customer_payments(receipt_number,customer_id,sale_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES(?,?,?,?,?,?,?,?,?)");
               $ins->execute([$rc,$customerId,null,(int)($conn->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn() ?: 1),$payAmount,'M-Pesa',$receipt,date('Y-m-d H:i:s'),'Automatic M-PESA credit payment']);$paymentId=(int)$conn->lastInsertId();$rc='CR-'.date('ymd').'-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$rc,$paymentId]);
               $allocation=dibonkeAllocateCustomerPayment($conn,$customerId,$paymentId,$payAmount,(int)($conn->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn() ?: 1));
               $appliedAmount=(float)$allocation['allocated'];$unallocatedAmount=(float)$allocation['credit'];
               if(dibonkeAccountingAutoPostingEnabled($conn)){
                  $journalLines=[dibonkeAccountingLine(dibonkeAccountingAccount($conn,'mpesa_clearing','Asset'),'Confirmed M-Pesa customer collection',$payAmount,0)];
                  if($allocation['allocated']>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'accounts_receivable','Asset'),'Customer invoices settled',0,$allocation['allocated']);
                  if($allocation['credit']>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'customer_credit','Liability'),'Excess retained as customer credit',0,$allocation['credit']);
                  dibonkeAccountingPostJournal($conn,'mpesa_customer_collection',(int)$tx['id'],date('Y-m-d'), 'M-Pesa customer collection '.$receipt,$journalLines,null);
               }
               recordAuditEvent($conn,'Receive','customer_payments','Confirmed M-PESA customer credit payment '.$receipt,'customer_payment',$paymentId,null,['customer_id'=>$customerId,'amount'=>$payAmount,'allocated'=>$allocation['allocated'],'customer_credit'=>$allocation['credit'],'payment_method'=>'M-Pesa','reference'=>$receipt]);
            }
         }
      }
   } elseif($tx && $tx['reference_type']==='sale' && (int)$tx['reference_id']>0){
      $saleId=(int)$tx['reference_id'];
      $saleQ=$conn->prepare("SELECT * FROM sales WHERE id=? FOR UPDATE");$saleQ->execute([$saleId]);$sale=$saleQ->fetch();
      if(!$sale)throw new RuntimeException('The sale linked to this STK payment could not be found. The confirmation was not acknowledged.');
      $already=$conn->prepare("SELECT COUNT(*) FROM customer_payments WHERE transaction_reference=?");
      $already->execute([$receipt]);
      if((int)$already->fetchColumn()>0)throw new RuntimeException('This M-PESA receipt number has already been recorded against another payment. Manual review is required.');
      if($sale && $amount>0){
         $openBalance=max(0,round((float)$sale['total_amount']-(float)$sale['paid_amount'],2));
         $payAmount=round(min($amount,$openBalance),2);
         $excessAmount=round(max(0,$amount-$payAmount),2);
         $appliedAmount=$payAmount;$unallocatedAmount=$excessAmount;
         if($payAmount>0){
            $upd=$conn->prepare("UPDATE sales SET paid_amount=LEAST(total_amount,paid_amount+?), balance=GREATEST(0,total_amount-LEAST(total_amount,paid_amount+?)) WHERE id=?");
            $upd->execute([$payAmount,$payAmount,$saleId]);
         }
         if(!empty($sale['customer_id'])){
            $customerId=(int)$sale['customer_id'];
            if($payAmount>0)$conn->prepare("UPDATE customers SET balance=GREATEST(0,balance-?) WHERE id=?")->execute([$payAmount,$customerId]);
            $rc='TMP-'.strtoupper(bin2hex(random_bytes(8)));
            $ins=$conn->prepare("INSERT INTO customer_payments(receipt_number,customer_id,sale_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES(?,?,?,?,?,?,?,?,?)");
            $ins->execute([$rc,$customerId,$saleId,(int)$sale['user_id'],$amount,'M-Pesa',$receipt,date('Y-m-d H:i:s'),'Automatic M-PESA payment for '.$sale['invoice_number']]);
            $paymentId=(int)$conn->lastInsertId();
            $rc='CR-'.date('ymd').'-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT);
            $conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$rc,$paymentId]);
            if($payAmount>0)$conn->prepare("INSERT INTO customer_payment_allocations(payment_id,sale_id,amount) VALUES(?,?,?)")->execute([$paymentId,$saleId,$payAmount]);
            if($excessAmount>0)$conn->prepare("INSERT INTO customer_credit_ledger(customer_id,payment_id,entry_type,amount,notes,created_by) VALUES(?,?,'Credit',?,'M-Pesa STK overpayment retained as customer credit',?)")->execute([$customerId,$paymentId,$excessAmount,(int)$sale['user_id']]);
         }
         if(dibonkeAccountingAutoPostingEnabled($conn)){
            $journalLines=[dibonkeAccountingLine(dibonkeAccountingAccount($conn,'mpesa_clearing','Asset'),'Confirmed M-Pesa sale collection',$amount,0)];
            if($payAmount>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'accounts_receivable','Asset'),'Sale receivable settled',0,$payAmount);
            if($excessAmount>0 && !empty($sale['customer_id']))$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'customer_credit','Liability'),'M-Pesa overpayment retained as customer credit',0,$excessAmount);
            elseif($excessAmount>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'unallocated_receipts','Liability'),'M-Pesa overpayment awaiting allocation',0,$excessAmount);
            dibonkeAccountingPostJournal($conn,'mpesa_sale_collection',(int)$tx['id'],date('Y-m-d'),'M-Pesa sale collection '.$receipt,$journalLines,(int)$sale['user_id']);
         }
         recordAuditEvent($conn,'Receive','sales','Confirmed M-PESA payment for invoice '.$sale['invoice_number'],'sale',$saleId,['paid_amount'=>(float)$sale['paid_amount'],'balance'=>(float)$sale['balance']],['paid_amount'=>(float)$sale['paid_amount']+$payAmount,'balance'=>max(0,(float)$sale['total_amount']-(float)$sale['paid_amount']-$payAmount),'payment_amount'=>$amount,'customer_credit'=>$excessAmount,'payment_method'=>'M-Pesa','reference'=>$receipt]);
      }
   } else {
      throw new RuntimeException('The STK transaction is not linked to a supported sale or customer payment.');
   }
 }
 if($code===0){$saveAmounts=$conn->prepare("UPDATE mpesa_transactions SET amount_applied=?,amount_unallocated=? WHERE checkout_request_id=?");$saveAmounts->execute([$appliedAmount,$unallocatedAmount,$checkout]);}
 $conn->commit();
 echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']);
}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();error_log('M-PESA callback: '.$e->getMessage());http_response_code(500);echo json_encode(['ResultCode'=>1,'ResultDesc'=>'Processing failed; confirmation was not acknowledged.']);}