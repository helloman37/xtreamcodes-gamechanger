<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/admin_notifications_lib.php';
require_once __DIR__ . '/email_lib.php';

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

  $createdUser = false;
  if(!$userId){
    $checkout=$_SESSION['checkout_'.$orderId] ?? null;
    if(!$checkout) throw new Exception("Checkout session missing");

    // create user
    $email = trim((string)($checkout['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
    $uSt=$pdo->prepare("INSERT INTO users (username,email,password_hash,password_enc,status,allow_adult,reseller_id)
                        VALUES (?,?,?,?,'active', ?, NULL)");
    $uSt->execute([$checkout['username'],$email,$checkout['password_hash'],$checkout['password_enc'] ?? '', $want_adult]);
    $userId=$pdo->lastInsertId();
    $createdUser = true;
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

  try {
    $pdo->beginTransaction();

    $expires=date("Y-m-d H:i:s", time()+((int)$plan['duration_days']*86400));

  // Enforce: one active subscription at a time.
  // If the user already has active time left, we carry it forward by basing the new expiry
  // on the latest active ends_at (renew without overlapping active subscriptions).
  $now = date("Y-m-d H:i:s");
  $hasUnlimited = false;
  try {
    $stU = $pdo->prepare("SELECT 1 FROM subscriptions WHERE user_id=? AND status='active' AND ends_at IS NULL LIMIT 1");
    $stU->execute([$userId]);
    $hasUnlimited = (bool)$stU->fetchColumn();
  } catch (Throwable $e) {}
  if ($hasUnlimited) throw new Exception("Account already has an unlimited subscription.");

  $baseEnd = null;
  try {
    $stE = $pdo->prepare("SELECT MAX(ends_at) FROM subscriptions WHERE user_id=? AND status='active' AND ends_at>NOW()");
    $stE->execute([$userId]);
    $baseEnd = (string)($stE->fetchColumn() ?? '');
  } catch (Throwable $e) {
    $baseEnd = '';
  }
  if ($baseEnd === '') $baseEnd = $now;

  // Guard: treat far-future sentinel as unlimited.
  if (str_starts_with($baseEnd, '9999-')) {
    throw new Exception("Account already has an unlimited subscription.");
  }

  $durDays = (int)($plan['duration_days'] ?? 0);
  if ($durDays < 1) $durDays = 30;
  $expires = date("Y-m-d H:i:s", strtotime($baseEnd . " +{$durDays} days"));

  // Cancel any current active subs (if any) so there is never more than one active subscription row.
  iptv_cancel_other_active_subscriptions($pdo, (int)$userId);

  $subSt=$pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status, order_id, source)
                        VALUES (?,?, NOW(), ?, 'active', ?, 'storefront')");
  $subSt->execute([$userId,$order['plan_id'],$expires,$orderId]);

  // mark paid
  $pdo->prepare("UPDATE orders SET status='paid', provider_txn=?, paid_at=NOW(), user_id=? WHERE id=?")
      ->execute([$providerTxn,$userId,$orderId]);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  // Admin notifications (deduped)
  try {
    if ($createdUser) {
      $uname = (string)($checkout['username'] ?? 'new user');
      admin_notifications_broadcast($pdo, 'user', 'New user joined', $uname . ' created an account during checkout.', '/admin/user_accounts.php?edit=' . (int)$userId, 'newuser:' . (int)$userId);
    }
    $planNameN = (string)($plan['name'] ?? 'Plan');
    $amt = (string)($order['amount'] ?? '0.00');
    $provider = (string)($order['provider'] ?? 'provider');
    $title = 'Payment succeeded';
    $msg = 'Order #' . (int)$orderId . ' paid (' . $provider . ') — ' . $planNameN . ' — $' . $amt . ' — user_id ' . (int)$userId . '.';
    admin_notifications_broadcast($pdo, 'payment', $title, $msg, '/admin/billing_reports.php', 'payok:' . (int)$orderId);
  } catch (Throwable $t) {}

  unset($_SESSION['checkout_'.$orderId]);

  // Email notifications
  try {
    if ($createdUser) {
      gc_email_send_welcome($pdo, (int)$userId);
      gc_email_send_verification($pdo, (int)$userId);
    }
    $planNameE = (string)($plan['name'] ?? 'Plan');
    gc_email_send_subscription($pdo, (int)$userId, $planNameE, (string)$expires);
  } catch (Throwable $e) {}

  return $userId;
}
