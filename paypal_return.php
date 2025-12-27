<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/paypal_lib.php';
require_once __DIR__ . '/provision_storefront.php';

$pdo=db();
$orderId=(int)($_GET['order'] ?? 0);

$st=$pdo->prepare("SELECT * FROM orders WHERE id=?");
$st->execute([$orderId]);
$order=$st->fetch();
if(!$order) die("Order not found");

try{
  $capture = paypal_capture($order['provider_txn']);
  if(($capture['status'] ?? '')!=='COMPLETED'){
    throw new Exception("Capture not completed");
  }
  $userId = provision_storefront_order($orderId, $order['provider_txn']);

  session_start();
  $_SESSION['store_user']=$userId;
  header("Location: success.php?order=".$orderId); exit;
}catch(Exception $e){
  die("PayPal capture error: ".$e->getMessage());
}
