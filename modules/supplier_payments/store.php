<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/auth.php';require_once __DIR__.'/../../config/db.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location:index.php');exit;}verify_csrf();
try{$sid=(int)($_POST['supplier_id']??0);$amount=(float)($_POST['amount']??0);$method=(string)($_POST['payment_method']??'Cash');$ref=trim((string)($_POST['transaction_reference']??''));$notes=trim((string)($_POST['notes']??''));
if(!in_array($method,['Cash','M-Pesa','Bank','Cheque','Other'],true))throw new RuntimeException('Invalid payment method.');
if($method==='M-Pesa'&&$ref==='')throw new RuntimeException('Enter the M-PESA transaction reference after making the payment.');
$conn->beginTransaction();
$s=$conn->prepare("SELECT * FROM suppliers WHERE id=? AND status='Active' FOR UPDATE");$s->execute([$sid]);$sup=$s->fetch();if(!$sup)throw new RuntimeException('Supplier not found or inactive.');if($amount<=0||$amount>(float)$sup['balance']+.001)throw new RuntimeException('Payment exceeds supplier payable balance.');
$prefix=(string)($conn->query("SELECT setting_value FROM settings WHERE setting_key='receipt_prefix' LIMIT 1")->fetchColumn()?:'SS-RCP');$receipt=$prefix.'-SUP-'.date('YmdHis').'-'.strtoupper(bin2hex(random_bytes(2)));
$s=$conn->prepare("INSERT INTO supplier_payments(receipt_number,supplier_id,user_id,amount,payment_method,transaction_reference,payment_date,notes) VALUES(?,?,?,?,?,?,?,?)");$s->execute([$receipt,$sid,$_SESSION['user_id'],$amount,$method,$ref?:null,date('Y-m-d H:i:s'),$notes?:null]);$s=$conn->prepare("UPDATE suppliers SET balance=GREATEST(0,balance-?) WHERE id=?");$s->execute([$amount,$sid]);$conn->commit();$_SESSION['supplier_payment_flash']='Supplier payment '.$receipt.' recorded successfully.';}catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();$_SESSION['supplier_payment_flash']='Payment failed: '.$e->getMessage();}header('Location:index.php');exit;