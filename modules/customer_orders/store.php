<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../config/db.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: index.php');exit;}verify_csrf();requireModulePermission('customer_orders','create');
try{
 $customerId=(int)($_POST['customer_id']??0);$listId=(int)($_POST['price_list_id']??0);$ids=$_POST['product_id']??[];$qtys=$_POST['quantity']??[];$modes=$_POST['unit_mode']??[];$prices=$_POST['unit_price']??[];$notes=trim((string)($_POST['notes']??''));
 if(!$customerId||!is_array($ids)||!count($ids))throw new RuntimeException('Select a customer and add at least one product.');
 $q=$conn->prepare("SELECT id FROM customers WHERE id=? AND status='Active'");$q->execute([$customerId]);if(!$q->fetchColumn())throw new RuntimeException('Select an active customer.');
 $q=$conn->prepare("SELECT id FROM price_lists WHERE id=? AND status='Active'");$q->execute([$listId]);if(!$q->fetchColumn())throw new RuntimeException('Select an active price list.');
 $conn->beginTransaction();$items=[];$subtotal=0;
 foreach($ids as $i=>$raw){$pid=(int)$raw;$qty=(int)($qtys[$i]??0);$mode=(string)($modes[$i]??'base');if($pid<=0||$qty<=0||!in_array($mode,['base','bulk'],true))throw new RuntimeException('Check each product and quantity.');
  $q=$conn->prepare("SELECT * FROM products WHERE id=? AND status='Active' FOR UPDATE");$q->execute([$pid]);$p=$q->fetch();if(!$p)throw new RuntimeException('A selected product is unavailable.');
  $factor=$mode==='bulk'?max(1,(int)($p['units_per_purchase']??1)):1;if($mode==='bulk'&&($factor<=1||empty($p['purchase_unit'])))throw new RuntimeException('Bulk unit is not configured for '.$p['product_name'].'.');
  $basePriceQ=$conn->prepare("SELECT price FROM product_prices WHERE price_list_id=? AND product_id=? AND min_quantity<=? ORDER BY min_quantity DESC LIMIT 1");$basePriceQ->execute([$listId,$pid,$qty*$factor]);$basePrice=$basePriceQ->fetchColumn();if($basePrice===false)$basePrice=(float)($p['selling_price']??0);$enteredPrice=$mode==='bulk'&&!empty($p['bulk_selling_price'])?(float)$p['bulk_selling_price']:((float)$basePrice*$factor);if($mode==='base')$enteredPrice=(float)$basePrice;
  if($enteredPrice<=0)throw new RuntimeException('No valid selling price for '.$p['product_name'].'.');
  $line=round($qty*$enteredPrice,2);$subtotal+=$line;$items[]=['p'=>$p,'qty'=>$qty*$factor,'entered'=>$qty,'unit'=>$mode==='bulk'?$p['purchase_unit']:$p['unit'],'factor'=>$factor,'price'=>$enteredPrice/$factor,'entered_price'=>$enteredPrice,'subtotal'=>$line];
 }
 $vatRate=(float)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_rate' LIMIT 1")->fetchColumn()?:16);$vatMode=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='vat_mode' LIMIT 1")->fetchColumn()?:'inclusive');$tax=round($vatMode==='inclusive'?$subtotal*$vatRate/(100+$vatRate):$subtotal*$vatRate/100,2);$total=round($vatMode==='inclusive'?$subtotal:$subtotal+$tax,2);
 $number='SO-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));$q=$conn->prepare("INSERT INTO customer_sales_orders(order_number,customer_id,price_list_id,created_by,order_date,subtotal,tax,total_amount,notes,status) VALUES(?,?,?,?,?,?,?,?,?,'Draft')");$q->execute([$number,$customerId,$listId,(int)$_SESSION['user_id'],date('Y-m-d H:i:s'),$subtotal,$tax,$total,$notes?:null]);$orderId=(int)$conn->lastInsertId();
 $ins=$conn->prepare("INSERT INTO customer_sales_order_items(order_id,product_id,quantity,unit_price,subtotal,entered_quantity,sale_unit,conversion_factor,entered_unit_price) VALUES(?,?,?,?,?,?,?,?,?)");
 foreach($items as $it)$ins->execute([$orderId,$it['p']['id'],$it['qty'],$it['price'], $it['subtotal'],$it['entered'],$it['unit'],$it['factor'],$it['entered_price']]);
 $conn->commit();$_SESSION['customer_order_flash']='Order '.$number.' saved as Draft. Validate it when ready.';header('Location: index.php');exit;
}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$_SESSION['customer_order_flash']='Order not saved: '.$e->getMessage();header('Location: index.php');exit;}
