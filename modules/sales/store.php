<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php'; require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../config/mpesa.php';
require_once __DIR__.'/../../includes/audit.php';
require_once __DIR__.'/../../includes/accounting_posting.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: index.php');exit;} verify_csrf();
requireModulePermission('sales','create');
try{
$storeId=(int)($_POST['store_id']??0);
$mainStoreId=(int)$conn->query("SELECT id FROM stores WHERE store_code='MAIN' AND status='Active' LIMIT 1")->fetchColumn();
if($mainStoreId<=0)throw new RuntimeException('Main Store is not configured. Run the Store Operations database migration.');
if($storeId<=0)$storeId=$mainStoreId;
$storeCheck=$conn->prepare("SELECT id FROM stores WHERE id=? AND status='Active' LIMIT 1");
$storeCheck->execute([$storeId]);
$selectedStore=$storeCheck->fetchColumn();
if(!$selectedStore)throw new RuntimeException('Select a valid active store for this sale.');
if($storeId!==$mainStoreId){
    $openStoreDay=$conn->prepare("SELECT id FROM store_daily_sessions WHERE store_id=? AND business_date=? AND status='Open' LIMIT 1 FOR UPDATE");
    $openStoreDay->execute([$storeId,date('Y-m-d')]);
    if(!$openStoreDay->fetchColumn())throw new RuntimeException('This selling store has no open daily stock issue for today. Complete the morning stock issue before recording sales.');
}
$cart=json_decode((string)($_POST['cart_json']??''),true); if(!is_array($cart)||!$cart)throw new RuntimeException('Add at least one product.');
$customerId=(int)($_POST['customer_id']??0);if($customerId<=0)$customerId=null;
$method=(string)($_POST['payment_method']??'Cash');$priceListId=(int)($_POST['price_list_id']??0);if($priceListId<=0)$priceListId=(int)($conn->query("SELECT id FROM price_lists WHERE is_default=1 AND status='Active' LIMIT 1")->fetchColumn()?:0);$pl=$conn->prepare("SELECT code FROM price_lists WHERE id=? AND status='Active'");$pl->execute([$priceListId]);$priceListCode=$pl->fetchColumn();if(!$priceListCode)throw new RuntimeException('Selected price list is not active.');
$mpesaPhone=trim((string)($_POST['mpesa_phone']??''));$cashTendered=max(0,(float)($_POST['cash_tendered']??$_POST['paid_amount']??0));$bankTendered=max(0,(float)($_POST['bank_amount']??0));$mpesaRequested=max(0,(float)($_POST['mpesa_amount']??0));$hasSalePaymentLines=(bool)$conn->query("SHOW TABLES LIKE 'sale_payment_lines'")->fetchColumn();if(!$hasSalePaymentLines)throw new RuntimeException('POS payment tracking is not installed. Import database/dibonke_sale_payment_lines.sql first.');if($mpesaRequested>0&&$mpesaPhone==='')throw new RuntimeException('Enter the customer M-PESA phone number.');if($cashTendered>0&&hasModulePermission('cash_management','create')&&(bool)$conn->query("SHOW TABLES LIKE 'cash_shift_guard'")->fetchColumn()){$shiftQ=$conn->prepare("SELECT id FROM cash_shifts WHERE user_id=? AND status='Open' LIMIT 1");$shiftQ->execute([(int)$_SESSION['user_id']]);if(!$shiftQ->fetchColumn())throw new RuntimeException('Open your cashier shift before accepting a cash sale.');}
$discount=0.0;$vatRate=(float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_rate' LIMIT 1")->fetchColumn()?:16);$vatMode=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_mode' LIMIT 1")->fetchColumn()?:'inclusive');if(!in_array($vatMode,['exclusive','inclusive'],true))$vatMode='exclusive';$amountTendered=$cashTendered;$paid=0.0;$notes=trim((string)($_POST['notes']??''));
$conn->beginTransaction();
$customer=null;if($customerId){$s=$conn->prepare("SELECT * FROM customers WHERE id=? AND status='Active' FOR UPDATE");$s->execute([$customerId]);$customer=$s->fetch();if(!$customer)throw new RuntimeException('Customer not found or inactive.');}
$lines=[];$subtotal=0.0;$reservedStock=[];$coverRuleStmt=$conn->prepare("SELECT book_product_id,charge_per_book FROM book_covering_service_rules WHERE book_product_id=? AND status='Active' FOR UPDATE");
foreach($cart as $item){
$pid=(int)($item['id']??0);$enteredQty=(float)($item['qty']??0);$saleUnit=(string)($item['unit']??'base');
if($pid<=0||$enteredQty<=0||$enteredQty!=round($enteredQty)||!in_array($saleUnit,['base','bulk'],true))throw new RuntimeException('Invalid product quantity or selling unit.');
$s=$conn->prepare("SELECT * FROM products WHERE id=? AND status='Active' FOR UPDATE");$s->execute([$pid]);$p=$s->fetch();if(!$p)throw new RuntimeException('A selected product is unavailable.');
$availableStock=(float)$p['stock_quantity'];
if($storeId!==$mainStoreId){
    $storeStockStmt=$conn->prepare("SELECT quantity FROM store_stock WHERE store_id=? AND product_id=? FOR UPDATE");
    $storeStockStmt->execute([$storeId,$pid]);
    $storeStockValue=$storeStockStmt->fetchColumn();
    $availableStock=$storeStockValue===false?0.0:(float)$storeStockValue;
}
$factor=$saleUnit==='bulk'?max(1,(int)($p['units_per_purchase']??1)):1;
if($saleUnit==='bulk'&&($factor<=1||empty($p['purchase_unit'])))throw new RuntimeException('Bulk selling is not configured for '.$p['product_name'].'.');
$baseQty=$enteredQty*$factor;
$reservedStock[$pid]=($reservedStock[$pid]??0)+$baseQty;
if($availableStock<$reservedStock[$pid])throw new RuntimeException('Insufficient stock for '.$p['product_name'].' at the selected store. Available: '.$availableStock.' '.$p['unit'].'.');
$priceStmt=$conn->prepare("SELECT price FROM product_prices WHERE price_list_id=? AND product_id=? AND min_quantity<=? ORDER BY min_quantity DESC LIMIT 1");
$priceStmt->execute([$priceListId,$pid,(int)$baseQty]);$basePrice=$priceStmt->fetchColumn();
if($saleUnit==='bulk'&&$priceListCode==='wholesale'&&!empty($p['bulk_wholesale_price'])&&(float)$p['bulk_wholesale_price']>0){$enteredPrice=(float)$p['bulk_wholesale_price'];}
 elseif($saleUnit==='bulk'&&$priceListCode!=='wholesale'&&!empty($p['bulk_selling_price'])&&(float)$p['bulk_selling_price']>0){$enteredPrice=(float)$p['bulk_selling_price'];}
else{if($basePrice===false)throw new RuntimeException('No price is configured for '.$p['product_name'].' in the selected price list.');$basePrice=(float)$basePrice;if($basePrice<=0)throw new RuntimeException('No valid price is configured for '.$p['product_name'].'.');$enteredPrice=$basePrice*$factor;}
if($saleUnit==='base'){$enteredPrice=(float)$basePrice;$factor=1;$baseQty=$enteredQty;}
$coverQuantity=0;$coverMaterialId=null;$coverCmPerBook=0;$coverChargePerBook=0.0;$coverTotalCm=0;$coverMaterialCost=0.0;
if(!empty($item['cover'])){
    $coverRuleStmt->execute([$pid]);$rule=$coverRuleStmt->fetch(PDO::FETCH_ASSOC);
    if(!$rule)throw new RuntimeException('Book covering is no longer configured for '.$p['product_name'].'. Refresh the POS and try again.');
    $coverQuantity=(int)$baseQty;
    $coverChargePerBook=(float)$rule['charge_per_book'];
}
$line=round(($enteredPrice+$coverChargePerBook*$factor)*$enteredQty,2);$baseUnitPrice=round(($enteredPrice+$coverChargePerBook*$factor)/$factor,4);$subtotal+=$line;
$lines[]=['p'=>$p,'available_stock'=>$availableStock,'qty'=>$baseQty,'entered_qty'=>$enteredQty,'sale_unit'=>$saleUnit==='bulk'?(string)$p['purchase_unit']:(string)$p['unit'],'factor'=>$factor,'price'=>$baseUnitPrice,'entered_price'=>$enteredPrice+$coverChargePerBook*$factor,'subtotal'=>$line,'cover_quantity'=>$coverQuantity,'cover_material_id'=>$coverMaterialId,'cover_cm_per_book'=>$coverCmPerBook,'cover_charge_per_book'=>$coverChargePerBook,'cover_total_cm'=>$coverTotalCm,'cover_material_cost'=>$coverMaterialCost];
}
$taxable=max(0,$subtotal-$discount);$tax=round($vatMode==='inclusive'?($taxable*$vatRate/(100+$vatRate)):($taxable*$vatRate/100),2);$total=round($vatMode==='inclusive'?$taxable:($taxable+$tax),2);$cashApplied=round(min($cashTendered,$total),2);$bankPaid=round($bankTendered,2);$tenderedTotal=round($cashApplied+$bankPaid+$mpesaRequested,2);if($tenderedTotal>$total+0.001)throw new RuntimeException('Payment amounts exceed the sale total. Reduce the cash, bank or M-PESA amount.');$paid=round($cashApplied+$bankPaid,2);$balance=round(max(0,$total-$paid),2);$activeMethods=[];if($cashApplied>0)$activeMethods[]='Cash';if($bankPaid>0)$activeMethods[]='Bank';if($mpesaRequested>0)$activeMethods[]='M-Pesa';$method=count($activeMethods)>1?'Mixed':($activeMethods[0]??'Cash');$uncoveredBalance=round(max(0,$balance-$mpesaRequested),2);if($uncoveredBalance>0.009&&!$customer)throw new RuntimeException('Select a customer for any balance not covered by a pending M-PESA request.');
if($customer&&$uncoveredBalance>0.009){$available=(float)$customer['credit_limit']-(float)$customer['balance'];if($uncoveredBalance>$available+0.001)throw new RuntimeException('Credit limit exceeded. Available credit: KES '.number_format(max(0,$available),2));}
$invoice='TMP-'.strtoupper(bin2hex(random_bytes(8)));
$uid=(int)$_SESSION['user_id'];$s=$conn->prepare("INSERT INTO sales (invoice_number,customer_id,price_list_id,user_id,store_id,sale_date,subtotal,discount,tax,total_amount,paid_amount,amount_tendered,balance,payment_method,sale_status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");$s->execute([$invoice,$customerId,$priceListId,$uid,$storeId,date('Y-m-d H:i:s'),$subtotal,$discount,$tax,$total,$paid,$cashTendered>0?$cashTendered:$paid,$balance,$method,'Completed',$notes?:null]);$saleId=(int)$conn->lastInsertId();$invoice='INV-'.date('ymd').'-'.str_pad((string)$saleId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE sales SET invoice_number=? WHERE id=?")->execute([$invoice,$saleId]);
$si=$conn->prepare("INSERT INTO sale_items (sale_id,product_id,quantity,price,discount,subtotal,buying_price,entered_quantity,sale_unit,conversion_factor,entered_unit_price,covering_quantity,covering_material_product_id,covering_cm_per_book,covering_charge_per_book,covering_total_material_cm) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$up=$conn->prepare("UPDATE products SET stock_quantity=stock_quantity-? WHERE id=?");
$storeStockDown=$conn->prepare("UPDATE store_stock SET quantity=quantity-? WHERE store_id=? AND product_id=? AND quantity>=?");
$hasMovementStoreId=(bool)$conn->query("SHOW COLUMNS FROM stock_movements LIKE 'store_id'")->fetchColumn();
$sm=$hasMovementStoreId
    ? $conn->prepare("INSERT INTO stock_movements (product_id,store_id,user_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,notes,movement_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    : $conn->prepare("INSERT INTO stock_movements (product_id,user_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,notes,movement_date) VALUES (?,?,?,?,?,?,?,?,?,?)");
$runningStock=[];
foreach($lines as $l){
    $productId=(int)$l['p']['id'];
    $before=$runningStock[$productId]??(float)$l['available_stock'];
    $after=$before-$l['qty'];
    if($after < -0.0001)throw new RuntimeException('Insufficient stock at the selected store for '.$l['p']['product_name'].'.');
    $runningStock[$productId]=$after;
    $si->execute([$saleId,$productId,$l['qty'],$l['price'],0,$l['subtotal'],$l['p']['buying_price'],$l['entered_qty'],$l['sale_unit'],$l['factor'],$l['entered_price'],$l['cover_quantity'],$l['cover_material_id'],$l['cover_cm_per_book'],$l['cover_charge_per_book'],$l['cover_total_cm']]);
    if($storeId===$mainStoreId){$up->execute([$l['qty'],$productId]);}
    else{$storeStockDown->execute([$l['qty'],$storeId,$productId,$l['qty']]);if($storeStockDown->rowCount()!==1)throw new RuntimeException('Store stock changed before the sale could be completed. Refresh the POS and try again.');}
    if($hasMovementStoreId){
        $sm->execute([$productId,$storeId,$uid,'Sale',$l['qty'],$before,$after,'sale',$saleId,'Sale '.$invoice.' at store '.$storeId,date('Y-m-d H:i:s')]);
    }else{
        $sm->execute([$productId,$uid,'Sale',$l['qty'],$before,$after,'sale',$saleId,'Sale '.$invoice.' at store '.$storeId,date('Y-m-d H:i:s')]);
    }
}

if($customer&&$balance>0){$s=$conn->prepare("UPDATE customers SET balance=balance+? WHERE id=?");$s->execute([$balance,$customerId]);}
$saleTenderLines=[];if($cashApplied>0)$saleTenderLines[]=['method'=>'Cash','amount'=>$cashApplied,'status'=>'Confirmed'];if($bankPaid>0)$saleTenderLines[]=['method'=>'Bank','amount'=>$bankPaid,'status'=>'Confirmed'];if($mpesaRequested>0)$saleTenderLines[]=['method'=>'M-Pesa','amount'=>$mpesaRequested,'status'=>'Pending'];$lineInsert=$conn->prepare("INSERT INTO sale_payment_lines(sale_id,payment_method,amount,status,created_by,created_at,confirmed_at) VALUES(?,?,?,?,?,NOW(),?)");foreach($saleTenderLines as $tender){if($tender['status']==='Pending')continue;$lineInsert->execute([$saleId,$tender['method'],$tender['amount'],'Confirmed',$uid,date('Y-m-d H:i:s')]);}
if($customerId&&$paid>0){foreach($saleTenderLines as $tender){if($tender['status']!=='Confirmed')continue;$receipt='TMP-'.strtoupper(bin2hex(random_bytes(8)));$s=$conn->prepare("INSERT INTO customer_payments (receipt_number,customer_id,sale_id,user_id,amount,payment_method,payment_date,notes) VALUES (?,?,?,?,?,?,?,?)");$s->execute([$receipt,$customerId,$saleId,$uid,$tender['amount'],$tender['method'],date('Y-m-d H:i:s'),'Payment for '.$invoice.' ('.$tender['method'].')']);$paymentId=(int)$conn->lastInsertId();$receipt='CR-'.date('ymd').'-'.str_pad((string)$paymentId,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE customer_payments SET receipt_number=? WHERE id=?")->execute([$receipt,$paymentId]);$conn->prepare("INSERT INTO customer_payment_allocations(payment_id,sale_id,amount) VALUES(?,?,?)")->execute([$paymentId,$saleId,$tender['amount']]);}}
if($customerId&&$paid>0&&$balance<=0){$s=$conn->prepare("UPDATE customers SET balance=GREATEST(0,balance) WHERE id=?");$s->execute([$customerId]);}
$log=$conn->prepare("INSERT INTO activity_logs (user_id,action,module,description,ip_address) VALUES (?,?,?,?,?)");$log->execute([$uid,'Create','Sales','Created sale '.$invoice.' for KES '.number_format($total,2),$_SERVER['REMOTE_ADDR']??null]);
recordAuditEvent($conn,'Create','sales','Created sale '.$invoice.' for KES '.number_format($total,2),'sale',$saleId,null,['invoice_number'=>$invoice,'total_amount'=>$total,'paid_amount'=>$paid,'balance'=>$balance,'payment_method'=>$method]);
if(dibonkeAccountingAutoPostingEnabled($conn)){
    $journalLines=[];
    if($cashApplied>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingPaymentAccount($conn,'Cash'),'Cash sale tender received',$cashApplied,0);
    if($bankPaid>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingPaymentAccount($conn,'Bank'),'Bank sale tender received',$bankPaid,0);
    if($balance>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'accounts_receivable','Asset'),'Customer balance due, including pending M-PESA',$balance,0);
    $revenue=round($total-$tax,2);
    if($revenue>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'sales_revenue','Income'),'Net sales revenue',0,$revenue);
    if($tax>0)$journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'vat_payable','Liability'),'Output VAT',0,$tax);
    $costOfGoods=0.0;
    foreach($lines as $line){$costOfGoods+=round((float)$line['p']['buying_price']*(float)$line['qty'],2);}
    $costOfGoods=round($costOfGoods,2);
    if($costOfGoods>0){
        $journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'cost_of_goods_sold','Expense'),'Cost of goods sold', $costOfGoods,0);
        $journalLines[]=dibonkeAccountingLine(dibonkeAccountingAccount($conn,'inventory','Asset'),'Inventory relieved at cost',0,$costOfGoods);
    }
    dibonkeAccountingPostJournal($conn,'sale',$saleId,date('Y-m-d'),'POS sale '.$invoice,$journalLines,$uid);
}
$conn->commit();
if($cashApplied>0){$_SESSION['cash_drawer_sale_id']=$saleId;}else{unset($_SESSION['cash_drawer_sale_id']);}
if($mpesaRequested>0){
    try{
        $response=mpesaStkPush($mpesaRequested,$mpesaPhone,$invoice,'Payment '.$invoice);
        $checkoutId=$response['CheckoutRequestID']??null;
        $tx=$conn->prepare("INSERT INTO mpesa_transactions(transaction_type,direction,phone_number,amount,account_reference,merchant_request_id,checkout_request_id,result_code,result_description,result_status,reference_type,reference_id,raw_response) VALUES('STK Push','Incoming',?,?,?,?,?,?,?,?,?,?,?)");
        $tx->execute([$mpesaPhone,$mpesaRequested,$invoice,$response['MerchantRequestID']??null,$checkoutId,(string)($response['ResponseCode']??''),(string)($response['ResponseDescription']??''),!empty($checkoutId)?'Pending':'Failed','sale',$saleId,json_encode($response)]);
        $mpesaTxId=(int)$conn->lastInsertId();
        $lineStatus=!empty($checkoutId)?'Pending':'Failed';
        $conn->prepare("INSERT INTO sale_payment_lines(sale_id,payment_method,amount,status,phone_number,checkout_request_id,mpesa_transaction_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())")->execute([$saleId,'M-Pesa',$mpesaRequested,$lineStatus,$mpesaPhone,$checkoutId,$mpesaTxId,$uid]);
        $_SESSION['sale_flash']='Sale '.$invoice.' recorded. M-PESA request for KES '.number_format($mpesaRequested,2).' sent to '.$mpesaPhone.'. The amount will be applied after confirmation.';
    }catch(Throwable $mpesaError){
        $conn->prepare("INSERT INTO sale_payment_lines(sale_id,payment_method,amount,status,phone_number,created_by,created_at) VALUES(?,?,?,'Failed',?,?,NOW())")->execute([$saleId,'M-Pesa',$mpesaRequested,$mpesaPhone,$uid]);
        $_SESSION['sale_flash']='Sale '.$invoice.' was recorded, but the M-PESA request could not be sent: '.$mpesaError->getMessage();
    }
}else{
    $_SESSION['sale_flash']='Sale '.$invoice.' completed successfully. Total KES '.number_format($total,2).'.';
}
header('Location: index.php?print_receipt='.$saleId);exit;
}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$_SESSION['sale_flash']='Sale failed: '.$e->getMessage();header('Location: index.php');exit;}