<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location:index.php');exit;}
verify_csrf();
requireModulePermission('supply_chain','create');
try{
 $supplierId=(int)($_POST['supplier_id']??0);$poDate=(string)($_POST['po_date']??date('Y-m-d'));$expected=trim((string)($_POST['expected_date']??''));$notes=trim((string)($_POST['notes']??''));$items=$_POST['items']??[];
 if($supplierId<=0||!is_array($items)||!$items)throw new RuntimeException('Select a supplier and add at least one item.');
 $clean=[];$subtotal=0;
 foreach($items as $x){$pid=(int)($x['product_id']??0);$qty=(int)($x['quantity']??0);$cost=(float)($x['unit_cost']??0);$mode=(string)($x['unit_mode']??'base');if($pid<=0||$qty<=0||$cost<0||!in_array($mode,['base','bulk'],true))throw new RuntimeException('Every PO item must have a valid product, quantity and cost.');$clean[]=['product_id'=>$pid,'entered_quantity'=>$qty,'entered_unit_cost'=>$cost,'unit_mode'=>$mode];$subtotal+=round($qty*$cost,2);}
 $conn->beginTransaction();
 $s=$conn->prepare("SELECT id FROM suppliers WHERE id=? AND status='Active' FOR UPDATE");$s->execute([$supplierId]);if(!$s->fetch())throw new RuntimeException('Supplier not found or inactive.');
 foreach($clean as &$x){$p=$conn->prepare("SELECT id,unit,purchase_unit,units_per_purchase FROM products WHERE id=? AND status='Active' FOR UPDATE");$p->execute([$x['product_id']]);$product=$p->fetch();if(!$product)throw new RuntimeException('A selected product is missing or inactive.');$factor=$x['unit_mode']==='bulk'?max(1,(int)$product['units_per_purchase']):1;if($x['unit_mode']==='bulk'&&($factor<=1||empty($product['purchase_unit'])))throw new RuntimeException('Bulk purchasing is not configured for this product.');$x['quantity']=$x['entered_quantity']*$factor;$x['unit_cost']=round($x['entered_unit_cost']/$factor,4);$x['purchase_unit']=$x['unit_mode']==='bulk'?(string)$product['purchase_unit']:(string)$product['unit'];$x['conversion_factor']=$factor;}unset($x);
 $po='TMP-'.strtoupper(bin2hex(random_bytes(8)));
 $st=$conn->prepare("INSERT INTO purchase_orders(po_number,supplier_id,user_id,po_date,expected_date,subtotal,total_amount,status,notes) VALUES(?,?,?,?,?,?,?,'Draft',?)");$st->execute([$po,$supplierId,$_SESSION['user_id'],$poDate,$expected?:null,$subtotal,$subtotal,$notes?:null]);$id=(int)$conn->lastInsertId();$po='PO-'.date('ymd').'-'.str_pad((string)$id,5,'0',STR_PAD_LEFT);$conn->prepare("UPDATE purchase_orders SET po_number=? WHERE id=?")->execute([$po,$id]);
 $it=$conn->prepare("INSERT INTO purchase_order_items(purchase_order_id,product_id,ordered_quantity,unit_cost,line_total,entered_quantity,purchase_unit,conversion_factor,entered_unit_cost) VALUES(?,?,?,?,?,?,?,?,?)");foreach($clean as $x)$it->execute([$id,$x['product_id'],$x['quantity'],$x['unit_cost'],round($x['entered_quantity']*$x['entered_unit_cost'],2),$x['entered_quantity'],$x['purchase_unit'],$x['conversion_factor'],$x['entered_unit_cost']]);
 $conn->commit();$_SESSION['supply_flash']="Purchase Order {$po} created as Draft. Approve it before receiving goods.";
}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();error_log('PO error: '.$e->getMessage());$_SESSION['supply_flash']='PO could not be created: '.$e->getMessage();}
header('Location:index.php');exit;