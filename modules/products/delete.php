<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/audit.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location:index.php');exit;}
verify_csrf();
requireModulePermission('products','delete');
$id=(int)($_POST['id']??0);
if($id>0){
 try{
  $check=$conn->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id=:id");$check->execute([':id'=>$id]);
  if((int)$check->fetchColumn()>0) throw new RuntimeException('This product has sales history and cannot be deleted. Set it to Inactive instead.');
  $check=$conn->prepare("SELECT COUNT(*) FROM purchase_items WHERE product_id=:id");$check->execute([':id'=>$id]);
  if((int)$check->fetchColumn()>0) throw new RuntimeException('This product has purchase history and cannot be deleted. Set it to Inactive instead.');
  $check=$conn->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id=:id");$check->execute([':id'=>$id]);
  if((int)$check->fetchColumn()>0) throw new RuntimeException('This product has stock history and cannot be deleted. Set it to Inactive instead.');
  $productQ=$conn->prepare("SELECT product_name,sku,barcode FROM products WHERE id=?");$productQ->execute([$id]);$deletedProduct=$productQ->fetch();$d=$conn->prepare("DELETE FROM products WHERE id=:id");$d->execute([':id'=>$id]);
  if($d->rowCount()===1)recordAuditEvent($conn,'Delete','products','Deleted product '.($deletedProduct['product_name']??('#'.$id)),'product',$id,$deletedProduct?:null,null);
  $_SESSION['product_flash']='Product deleted successfully.';
 }catch(Throwable $e){$_SESSION['product_flash']=$e->getMessage();}
}
header('Location:index.php');exit;