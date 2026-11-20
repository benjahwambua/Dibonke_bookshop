<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php'; require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../config/mpesa.php';
require_once __DIR__.'/../../includes/audit.php';
require_once __DIR__.'/../../includes/accounting_posting.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: index.php');exit;} verify_csrf();
requireModulePermission('sales','create');
try{
$cart=json_decode((string)($_POST['cart_json']??''),true); if(!is_array($cart)||!$cart)throw new RuntimeException('Add at least one product.');
$customerId=null;
$method=(string)($_POST['payment_method']??'Cash');$priceListId=(int)($_POST['price_list_id']??0);if($priceListId<=0)$priceListId=(int)($conn->query("SELECT id FROM price_lists WHERE is_default=1 AND status='Active' LIMIT 1")->fetchColumn()?:0);$pl=$conn->prepare("SELECT code FROM price_lists WHERE id=? AND status='Active'");$pl->execute([$priceListId]);$priceListCode=$pl->fetchColumn();if(!$priceListCode)throw new RuntimeException('Selected price list is not active.');
$mpesaPhone=trim((string)($_POST['mpesa_phone']??''));if(!in_array($method,['Cash','M-Pesa','Bank'],true))throw new RuntimeException('Invalid payment method.');if($method==='Cash'&&hasModulePermission('cash_management','create')&&(bool)$conn->query("SHOW TABLES LIKE 'cash_shift_guard'")->fetchColumn()){$shiftQ=$conn->prepare("SELECT id FROM cash_shifts WHERE user_id=? AND status='Open' LIMIT 1");$shiftQ->execute([(int)$_SESSION['user_id']]);if(!$shiftQ->fetchColumn())throw new RuntimeException('Open your cashier shift before accepting a cash sale.');}
$discount=0.0;$vatRate=(float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_rate' LIMIT 1")->fetchColumn()?:16);$vatMode=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_mode' LIMIT 1")->fetchColumn()?:'inclusive');if(!in_array($vatMode,['exclusive','inclusive'],true))$vatMode='exclusive';$amountTendered=max(0,(float)($_POST['paid_amount']??0));$paid=$amountTendered;$notes=trim((string)($_POST['notes']??''));
$conn->beginTransaction();
$customer=null;if($customerId){$s=$conn->prepare("SELECT * FROM customers WHERE id=? AND status='Active' FOR UPDATE");$s->execute([$customerId]);$customer=$s->fetch();if(!$customer)throw new RuntimeException('Customer not found or inactive.');}
$lines=[];$subtotal=0.0;$reservedStock=[];
foreach($cart as $item){
$pid=(int)($item['id']??0);$enteredQty=(float)($item['qty']??0);$saleUnit=(string)($item['unit']??'base');
if($pid<=0||$enteredQty<=0||$enteredQty!=round($enteredQty)||!in_array($saleUnit,['base','bulk'],true))throw new RuntimeException('Invalid product quantity or selling unit.');
$s=$conn->prepare("SELECT * FROM products WHERE id=? AND status='Active' FOR UPDATE");$s->execute([$pid]);$p=$s->fetch();if(!$p)throw new RuntimeException('A selected product is unavailable.');
$factor=$saleUnit==='bulk'?max(1,(int)($p['units_per_purchase']??1)):1;
if($saleUnit==='bulk'&&($factor<=1||empty($p['purchase_unit'])))throw new RuntimeException('Bulk selling is not configured for '.$p['product_name'].'.');
$baseQty=$enteredQty*$factor;
$reservedStock[$pid]=($reservedStock[$pid]??0)+$baseQty;
if((float)$p['stock_quantity']<$reservedStock[$pid])throw new RuntimeException('Insufficient stock for '.$p['product_name'].'. Available: '.$p['stock_quantity'].' '.$p['unit'].'.');
$priceStmt=$conn->prepare("SELECT price FROM product_prices WHERE price_list_id=? AND product_id=? AND min_quantity<=? ORDER BY min_quantity DESC LIMIT 1");
$priceStmt->execute([$priceListId,$pid,(int)$baseQty]);$basePrice=$priceStmt->fetchColumn();
if($saleUnit==='bulk'&&$priceListCode==='wholesale'&&!empty($p['bulk_wholesale_price'])&&(float)$p['bulk_wholesale_price']>0){$enteredPrice=(float)$p['bulk_wholesale_price'];}
 elseif($saleUnit==='bulk'&&$priceListCode!=='wholesale'&&!empty($p['bulk_selling_price'])&&(float)$p['bulk_selling_price']>0){$enteredPrice=(float)$p['bulk_selling_price'];}
else{if($basePrice===false)throw new RuntimeException('No price is configured for '.$p['product_name'].' in the selected price list.');$basePrice=(float)$basePrice;if($basePrice<=0)throw new RuntimeException('No valid price is configured for '.$p['product_name'].'.');$enteredPrice=$basePrice*$factor;}
if($saleUnit==='base'){$enteredPrice=(float)$basePrice;$factor=1;$baseQty=$enteredQty;}
$line=round($enteredPrice*$enteredQty,2);$baseUnitPrice=round($enteredPrice/$factor,4);$subtotal+=$line;
$lines[]=['p'=>$p,'qty'=>$baseQty,'entered_qty'=>$enteredQty,'sale_unit'=>$saleUnit==='bulk'?(string)$p['purchase_unit']:(string)$p['unit'],'factor'=>$factor,'price'=>$baseUnitPrice,'entered_price'=>$enteredPrice,'subtotal'=>$line];
}
$taxable=max(0,$subtotal-$discount);$tax=round($vatMode==='inclusive'?($taxable*$vatRate/(100+$vatRate)):($taxable*$vatRate/100),2);$total=round($vatMode==='inclusive'?$taxable:($taxable+$tax),2);if($method==='Cash'){$paid=$amountTendered >= ($total-0.001) ? $total : min($amountTendered,$total);}elseif($amountTendered>$total+0.001){throw new RuntimeException('Amount paid cannot exceed the sale total.');}$balance=round(max(0,$total-$paid),2);

if($method==='M-Pesa'){if($mpesaPhone==='')throw new RuntimeException('Enter the customer M-PESA phone number.');$paid=0;$balance=$total;}if($balance>0&&!$customer&&$method!=='M-Pesa')throw new RuntimeException('Select a customer for any sale with an outstanding balance.');
if($customer&&$balance>0&&$method!=='M-Pesa'){$available=(float)$customer['credit_limit']-(float)$customer['balance'];if($balance>$available+0.001)throw new RuntimeException('Credit limit exceeded. Available credit: KES '.number_format(max(0,$available),2));}
$invoice='TMP-'.strtoupper(bin2hex(random_bytes(8)));
$uid=(int)$_SESSION['user_id'];$s=$conn->prepare("INSERT INTO sales (invoice_number,customer_id,price_list_id,user_id,sale_date,subtotal,discount,tax,total_amount,paid_amount,amount_tendered,balance,payment_method,sale_status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");$s->execute([$invoice,$customerId,$priceListId,$uid,date('Y-m-d H:i:s'),$subtotal,$discount,$tax,$total,$paid,$method==='Cash'?$amountTendered:$paid,$balance,$method,'Completed',$notes?:null]);$saleId=(int)$conn->lastInsertId();$invoice='INV-'.date('ymd').'-'.str_pad((string)$saleId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE sales SET invoice_number=? WHERE id=?")->execute([$invoice,$saleId]);
$si=$conn->prepare("INSERT INTO sale_items (sale_id,product_id,quantity,price,discount,subtotal,buying_price,entered_quantity,sale_unit,conversion_factor,entered_unit_price) VALUES (?,?,?,?,?,?,?,?,?,?,?)");$up=$conn->prepare("UPDATE products SET stock_quantity=stock_quantity-? WHERE id=?");$sm=$conn->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,notes,movement_date) VALUES (?,?,?,?,?,?,?,?,?,?)");
 $runningStock=[];foreach($lines as $l){$productId=(int)$l['p']['id'];$before=$runningStock[$productId]??(float)$l['p']['stock_quantity'];$after=$before-$l['qty'];$runningStock[$productId]=$after;$si->execute([$saleId,$l['p']['id'],$l['qty'],$l['price'],0,$l['subtotal'],$l['p']['buying_price'],$l['entered_qty'],$l['sale_unit'],$l['factor'],$l['entered_price']]);$up->execute([$l['qty'],$l['p']['id']]);$sm->execute([$l['p']['id'],$uid,'Sale',$l['qty'],$before,$after,'sale',$saleId,'Sale '.$invoice,date('Y-m-d H:i:s')]);}
if($customer&&$balance>0){$s=$conn->prepare("UPDATE customers SET balance=balance+? WHERE id=?");$s->execute([$balance,$customerId]);}
if($paid>0 && $customerId){$receipt='TMP-'.strtoupper(bin2hex(random_bytes(8)));$s=$conn->prepare("INSERT INTO customer_payments (receipt_number,customer_id,sale_id,user_id,amount,payment_method,payment_date,notes) VALUES (?,?,?,?,?,?,?,?)");$paymentMethod = $method === 'Mixed' ? 'Other' : $method;
$s->execute([$receipt,$customerId,$saleId,$uid,$paid,$paymentMethod,date('Y-m-d H:i:s'),'Payment for '.$invoice.' (Sale method: '.$method.')']);$paymentId=(int)$conn->lastInsertId();$receipt='CR-'.date('ymd').'-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$receipt,$paymentId]);$conn->prepare("INSERT INTO customer_payment_allocations(payment_id,sale_id,amount) VALUES(?,?,?)")->execute([$paymentId,$saleId,$paid]);}
if($customerId&&$paid>0&&$balance<=0){$s=$conn->prepare("UPDATE customers SET balance=GREATEST(0,balance) WHERE id=?");$s->execute([$customerId]);}
$log=$conn->prepare("INSERT INTO activity_logs (user_id,action,module,description,ip_address) VALUES (?,?,?,?,?)");$log->execute([$uid,'Create','Sales','Created sale '.$invoice.' for KES '.number_format($total,2),$_SERVER['REMOTE_ADDR']??null]);
recordAuditEvent($conn,'Create','sales','Created sale '.$invoice.' for KES '.number_format($total,2),'sale',$saleId,null,['invoice_number'=>$invoice,'total_amount'=>$total,'paid_amount'=>$paid,'balance'=>$balance,'payment_method'=>$method]);
if(dibonkeAccountingAutoPostingEnabled($conn)){
    $journalLines=[];
    if($paid>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingPaymentAccount($conn,$method),'Sale payment received', $paid,0);
    if($balance>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'accounts_receivable','Asset'),'Customer balance due',$balance,0);
    $revenue=round($total-$tax,2);
    if($revenue>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'sales_revenue','Income'),'Net sales revenue',0,$revenue);
    if($tax>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'vat_payable','Liability'),'Output VAT',0,$tax);
    $costOfGoods=0.0;
    foreach($lines as $line)$costOfGoods+=round((float)$line['p']['buying_price']*(float)$line['qty'],2);
    $costOfGoods=round($costOfGoods,2);
    if($costOfGoods>0){
        $journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'cost_of_goods_sold','Expense'),'Cost of goods sold', $costOfGoods,0);
        $journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'inventory','Asset'),'Inventory relieved at cost',0,$costOfGoods);
    }
    dibonkeAccountingPostJournal($conn,'sale',$saleId,date('Y-m-d'),'POS sale '.$invoice,$journalLines,$uid);
}
$conn->commit();
if($method==='M-Pesa'){
    try{
        $response=mpesaStkPush($total,$mpesaPhone,$invoice,'Payment '.$invoice);
        $tx=$conn->prepare("INSERT INTO mpesa_transactions(transaction_type,direction,phone_number,amount,account_reference,merchant_request_id,checkout_request_id,result_code,result_description,result_status,reference_type,reference_id,raw_response) VALUES('STK Push','Incoming',?,?,?,?,?,?,?,?,?,?,?)");
        $tx->execute([$mpesaPhone,$total,$invoice,$response['MerchantRequestID']??null,$response['CheckoutRequestID']??null,(string)($response['ResponseCode']??''),(string)($response['ResponseDescription']??''),!empty($response['CheckoutRequestID'])?'Pending':'Failed','sale',$saleId,json_encode($response)]);
        $_SESSION['sale_flash']='Sale '.$invoice.' created. M-PESA STK Push sent to '.$mpesaPhone.'. Awaiting payment confirmation.';
    }catch(Throwable $mpesaError){
        $_SESSION['sale_flash']='Sale '.$invoice.' was recorded but the M-PESA request could not be sent: '.$mpesaError->getMessage();
    }
}else{
    $_SESSION['sale_flash']='Sale '.$invoice.' completed successfully. Total KES '.number_format($total,2).'.';
}
header('Location: receipt.php?id='.$saleId.'&autoprint=1');exit;
}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$_SESSION['sale_flash']='Sale failed: '.$e->getMessage();header('Location: index.php');exit;}