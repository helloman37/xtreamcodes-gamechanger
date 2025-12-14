<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function provision_storefront_order($orderId, $providerTxn){
  $pdo=db();

  $st=$pdo->prepare("SELECT * FROM orders WHERE id=?");
  $st->execute([$orderId]);
  $order=$st->fetch();
  if(!$order) throw new Exception("Order not found");
  if($order['status']==='paid') return $order['user_id'];

  session_start();

  $userId = $order['user_id'] ? (int)$order['user_id'] : null;

  $want_adult = 0;
  if(!empty($_SESSION['checkout_'.$orderId]['allow_adult'])){
    $want_adult = 1;
  }

  if(!$userId){
    $checkout=$_SESSION['checkout_'.$orderId] ?? null;
    if(!$checkout) throw new Exception("Checkout session missing");

    // create user
    $uSt=$pdo->prepare("INSERT INTO users (username,password_hash,password_enc,status,allow_adult,reseller_id)
                        VALUES (?,?,?, 'active', ?, NULL)");
    $uSt->execute([$checkout['username'],$checkout['password_hash'],$checkout['password_enc'] ?? '', $want_adult]);
    $userId=$pdo->lastInsertId();
  }

    // if customer opted into adult, enable it (never auto-disable)
  if($want_adult){
    $pdo->prepare("UPDATE users SET allow_adult=1 WHERE id=?")->execute([$userId]);
  }

  // create subscription
  $planSt=$pdo->prepare("SELECT * FROM plans WHERE id=?");
  $planSt->execute([$order['plan_id']]);
  $plan=$planSt->fetch();
  if(!$plan) throw new Exception("Plan missing");

  $expires=date("Y-m-d H:i:s", time()+((int)$plan['duration_days']*86400));

  $subSt=$pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status, order_id, source)
                        VALUES (?,?, NOW(), ?, 'active', ?, 'storefront')");
  $subSt->execute([$userId,$order['plan_id'],$expires,$orderId]);

  // mark paid
  $pdo->prepare("UPDATE orders SET status='paid', provider_txn=?, paid_at=NOW(), user_id=? WHERE id=?")
      ->execute([$providerTxn,$userId,$orderId]);

  unset($_SESSION['checkout_'.$orderId]);
  return $userId;
}
