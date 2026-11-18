<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/audit.php';

header('Content-Type: application/json');
$raw=file_get_contents('php://input')?:'';
$data=json_decode($raw,true);
try{
 $cb=$data['Body']['stkCallback']??null;
 if(!$cb)throw new RuntimeException('Invalid callback payload.');
 $merchant=(string)($cb['MerchantRequestID']??'');$checkout=(string)($cb['CheckoutRequestID']??'');$code=(int)($cb['ResultCode']??-1);$desc=(string)($cb['ResultDesc']??'');
 $status=$code===0?'Success':'Failed';$amount=0;$phone='';$receipt='';$date=null;
 foreach(($cb['CallbackMetadata']['Item']??[]) as $item){$name=$item['Name']??'';$value=$item['Value']??null;if($name==='Amount')$amount=(float)$value;elseif($name==='MpesaReceiptNumber')$receipt=(string)$value;elseif($name==='PhoneNumber')$phone=(string)$value;elseif($name==='TransactionDate'&&$value)$date=DateTime::createFromFormat('YmdHis',(string)$value)?->format('Y-m-d H:i:s');}
 $st=$conn->prepare("INSERT INTO mpesa_transactions(transaction_type,direction,phone_number,amount,transaction_reference,merchant_request_id,checkout_request_id,result_code,result_description,result_status,raw_response,transaction_date) VALUES('STK Push','Incoming',?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE result_code=VALUES(result_code),result_description=VALUES(result_description),result_status=VALUES(result_status),transaction_reference=VALUES(transaction_reference),phone_number=VALUES(phone_number),amount=VALUES(amount),raw_response=VALUES(raw_response),transaction_date=VALUES(transaction_date)");
 $st->execute([$phone,$amount,$receipt,$merchant,$checkout,(string)$code,$desc,$status,$raw,$date]);
 if($code===0){
   $lookup=$conn->prepare("SELECT reference_type,reference_id FROM mpesa_transactions WHERE checkout_request_id=? LIMIT 1");
   $lookup->execute([$checkout]);$tx=$lookup->fetch();
   if($tx && $tx['reference_type']==='customer_payment' && (int)$tx['reference_id']>0){
      $customerId=(int)$tx['reference_id'];
      $conn->beginTransaction();
      $custQ=$conn->prepare("SELECT * FROM customers WHERE id=? FOR UPDATE");$custQ->execute([$customerId]);$customer=$custQ->fetch();
      if($customer && $amount>0){
         $payAmount=min($amount,(float)$customer['balance']);
         if($payAmount>0){
            $rc='TMP-'.strtoupper(bin2hex(random_bytes(8)));
            $exists=$conn->prepare("SELECT COUNT(*) FROM customer_payments WHERE customer_id=? AND transaction_reference=?");$exists->execute([$customerId,$receipt]);
            if((int)$exists->fetchColumn()===0){
               $ins=$conn->prepare("INSERT INTO customer_payments(receipt_number,customer_id,sale_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES(?,?,?,?,?,?,?,?,?)");
               $ins->execute([$rc,$customerId,null,(int)($conn->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn() ?: 1),$payAmount,'M-Pesa',$receipt,date('Y-m-d H:i:s'),'Automatic M-PESA credit payment']);$paymentId=(int)$conn->lastInsertId();$rc='RCP-'.date('ymd').'-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$rc,$paymentId]);
               $upd=$conn->prepare("UPDATE customers SET balance=GREATEST(0,balance-?) WHERE id=?");$upd->execute([$payAmount,$customerId]);
               recordAuditEvent($conn,'Receive','customer_payments','Confirmed M-PESA customer credit payment '.$receipt,'customer_payment',$paymentId,null,['customer_id'=>$customerId,'amount'=>$payAmount,'payment_method'=>'M-Pesa','reference'=>$receipt]);
            }
         }
      }
      $conn->commit();
   } elseif($tx && $tx['reference_type']==='sale' && (int)$tx['reference_id']>0){
      $saleId=(int)$tx['reference_id'];
      $conn->beginTransaction();
      $saleQ=$conn->prepare("SELECT * FROM sales WHERE id=? FOR UPDATE");$saleQ->execute([$saleId]);$sale=$saleQ->fetch();
      $already=$conn->prepare("SELECT COUNT(*) FROM customer_payments WHERE sale_id=? AND transaction_reference=?");
      $already->execute([$saleId,$receipt]);
      if($sale && (int)$already->fetchColumn()===0 && (float)$sale['paid_amount'] < (float)$sale['total_amount']){
         $payAmount=min($amount,(float)$sale['total_amount']-(float)$sale['paid_amount']);
         $upd=$conn->prepare("UPDATE sales SET paid_amount=paid_amount+?, balance=GREATEST(0,total_amount-paid_amount-?) WHERE id=?");
         $upd->execute([$payAmount,$payAmount,$saleId]);
         if($sale['customer_id'] && $payAmount>0){
            $cp=$conn->prepare("UPDATE customers SET balance=GREATEST(0,balance-?) WHERE id=?");$cp->execute([$payAmount,(int)$sale['customer_id']]);
            $exists=$conn->prepare("SELECT COUNT(*) FROM customer_payments WHERE sale_id=? AND transaction_reference=?");$exists->execute([$saleId,$receipt]); 
            if((int)$exists->fetchColumn()===0){
               $rc='TMP-'.strtoupper(bin2hex(random_bytes(8)));
               $ins=$conn->prepare("INSERT INTO customer_payments(receipt_number,customer_id,sale_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES(?,?,?,?,?,?,?,?,?)");
               $ins->execute([$rc,(int)$sale['customer_id'],$saleId,(int)$sale['user_id'],$payAmount,'M-Pesa',$receipt,date('Y-m-d H:i:s'),'Automatic M-PESA payment for '.$sale['invoice_number']]);$paymentId=(int)$conn->lastInsertId();$rc='RCP-'.date('ymd').'-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$rc,$paymentId]);
            }
         }
         recordAuditEvent($conn,'Receive','sales','Confirmed M-PESA payment for invoice '.$sale['invoice_number'],'sale',$saleId,['paid_amount'=>(float)$sale['paid_amount'],'balance'=>(float)$sale['balance']],['paid_amount'=>(float)$sale['paid_amount']+$payAmount,'balance'=>max(0,(float)$sale['total_amount']-(float)$sale['paid_amount']-$payAmount),'payment_method'=>'M-Pesa','reference'=>$receipt]);
      }
      $conn->commit();
   }
 }
 echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']);
}catch(Throwable $e){error_log('M-PESA callback: '.$e->getMessage());echo json_encode(['ResultCode'=>0,'ResultDesc'=>'Accepted']);}