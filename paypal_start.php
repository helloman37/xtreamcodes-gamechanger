<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/paypal_lib.php';

$pdo=db();
$orderId=(int)($_GET['order'] ?? 0);
$st=$pdo->prepare("SELECT * FROM orders WHERE id=?");
$st->execute([$orderId]);
$order=$st->fetch();
if(!$order) die("Order not found");

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$baseUrl = $scheme."://".$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']);

try{
  $ppOrder = paypal_create_order($order['amount'],$order['currency'],
      $baseUrl."/paypal_return.php?order=".$orderId,
      $baseUrl."/paypal_cancel.php?order=".$orderId
  );
  $ppId=$ppOrder['id'] ?? null;
  if(!$ppId) throw new Exception("PayPal order failed");

  $pdo->prepare("UPDATE orders SET provider_txn=? WHERE id=?")->execute([$ppId,$orderId]);

  $approve=null;
  foreach(($ppOrder['links'] ?? []) as $ln){
    if(($ln['rel'] ?? '')==='approve'){ $approve=$ln['href']; break; }
  }
  if(!$approve) throw new Exception("No approve link");
  header("Location: ".$approve); exit;
}catch(Exception $e){
  die("PayPal error: ".$e->getMessage());
}
