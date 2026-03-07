<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/admin_notifications_lib.php';
require_once __DIR__ . '/email_lib.php';

function gc_storefront_load_order(PDO $pdo, int $orderId): array {
  $st = $pdo->prepare("SELECT * FROM orders WHERE id=?");
  $st->execute([$orderId]);
  $order = $st->fetch();
  if (!$order) throw new Exception('Order not found');
  return $order;
}

function gc_storefront_resolve_user_from_order(PDO $pdo, array $order): array {
  if (session_status() === PHP_SESSION_NONE) @session_start();

  $userId = !empty($order['user_id']) ? (int)$order['user_id'] : 0;
  $wantAdult = (int)($order['pending_allow_adult'] ?? 0);
  $checkout = $_SESSION['checkout_' . (int)$order['id']] ?? [];
  if (!$wantAdult && !empty($checkout['allow_adult'])) $wantAdult = 1;

  $createdUser = false;
  if ($userId < 1) {
    $email = trim((string)($order['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

    $username = trim((string)($order['pending_username'] ?? ''));
    if ($username === '') $username = trim((string)($checkout['username'] ?? ''));
    $passwordHash = trim((string)($order['pending_password_hash'] ?? ''));
    if ($passwordHash === '') $passwordHash = trim((string)($checkout['password_hash'] ?? ''));
    $passwordEnc = (string)($order['pending_password_enc'] ?? '');
    if ($passwordEnc === '' && !empty($checkout['password_enc'])) $passwordEnc = (string)$checkout['password_enc'];

    if ($email !== '') {
      $st = $pdo->prepare("SELECT id, allow_adult FROM users WHERE email=? LIMIT 1");
      $st->execute([$email]);
      $existing = $st->fetch();
      if ($existing) {
        $userId = (int)$existing['id'];
      }
    }

    if ($userId < 1 && $username !== '') {
      $st = $pdo->prepare("SELECT id, allow_adult FROM users WHERE username=? LIMIT 1");
      $st->execute([$username]);
      $existing = $st->fetch();
      if ($existing) $userId = (int)$existing['id'];
    }

    if ($userId < 1) {
      if ($username === '' || $passwordHash === '') throw new Exception('Checkout account payload missing for storefront order.');
      $uSt = $pdo->prepare("INSERT INTO users (username,email,password_hash,password_enc,status,allow_adult,reseller_id) VALUES (?,?,?,?,'active', ?, NULL)");
      $uSt->execute([$username, $email !== '' ? $email : null, $passwordHash, $passwordEnc, $wantAdult ? 1 : 0]);
      $userId = (int)$pdo->lastInsertId();
      $createdUser = true;
      $pdo->prepare("UPDATE orders SET user_id=? WHERE id=?")->execute([$userId, (int)$order['id']]);
    }
  }

  if ($wantAdult && $userId > 0) {
    $pdo->prepare("UPDATE users SET allow_adult=1 WHERE id=?")->execute([$userId]);
  }

  return ['user_id' => $userId, 'created_user' => $createdUser, 'want_adult' => $wantAdult, 'checkout' => $checkout];
}

function gc_notify_storefront_success(PDO $pdo, int $orderId, int $userId, array $plan, array $order, bool $createdUser, array $checkout = []): void {
  try {
    if ($createdUser) {
      $uname = (string)($checkout['username'] ?? $order['pending_username'] ?? 'new user');
      admin_notifications_broadcast($pdo, 'user', 'New user joined', $uname . ' created an account during checkout.', '/admin/user_accounts.php?edit=' . (int)$userId, 'newuser:' . (int)$userId);
    }
    $planNameN = (string)($plan['name'] ?? 'Plan');
    $amt = (string)($order['amount'] ?? '0.00');
    $provider = (string)($order['provider'] ?? 'provider');
    $title = 'Payment succeeded';
    $msg = 'Order #' . (int)$orderId . ' paid (' . $provider . ') — ' . $planNameN . ' — $' . $amt . ' — user_id ' . (int)$userId . '.';
    admin_notifications_broadcast($pdo, 'payment', $title, $msg, '/admin/billing_reports.php', 'payok:' . (int)$orderId);
  } catch (Throwable $t) {}

  try {
    if ($createdUser) {
      gc_email_send_welcome($pdo, (int)$userId);
      gc_email_send_verification($pdo, (int)$userId);
    }
    if (!empty($plan['name']) && !empty($order['paid_at'])) {
      gc_email_send_subscription($pdo, (int)$userId, (string)$plan['name'], (string)($order['paid_at'] ?? ''));
    }
  } catch (Throwable $e) {}
}

function gc_upsert_subscription(PDO $pdo, array $payload): int {
  $userId = (int)($payload['user_id'] ?? 0);
  $planId = (int)($payload['plan_id'] ?? 0);
  $orderId = (int)($payload['order_id'] ?? 0);
  $startsAt = (string)($payload['starts_at'] ?? date('Y-m-d H:i:s'));
  $endsAt = (string)($payload['ends_at'] ?? date('Y-m-d H:i:s'));
  $status = (string)($payload['status'] ?? 'active');
  $provider = (string)($payload['payment_provider'] ?? '');
  $customerId = (string)($payload['external_customer_id'] ?? '');
  $subscriptionId = (string)($payload['external_subscription_id'] ?? '');
  $priceId = (string)($payload['external_price_id'] ?? '');
  $autoRenew = !empty($payload['auto_renew']) ? 1 : 0;
  $renewsAt = (string)($payload['renews_at'] ?? $endsAt);

  if ($userId < 1 || $planId < 1) throw new Exception('Missing subscription payload.');

  $existing = null;
  if ($subscriptionId !== '') {
    $st = $pdo->prepare("SELECT * FROM subscriptions WHERE external_subscription_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$subscriptionId]);
    $existing = $st->fetch();
  }
  if (!$existing && $orderId > 0) {
    $st = $pdo->prepare("SELECT * FROM subscriptions WHERE order_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$orderId]);
    $existing = $st->fetch();
  }

  if ($existing) {
    $subId = (int)$existing['id'];
    $startsAt = (string)($existing['starts_at'] ?: $startsAt);
    $pdo->prepare("UPDATE subscriptions SET plan_id=?, starts_at=?, ends_at=?, status=?, order_id=?, source='storefront', payment_provider=?, external_customer_id=?, external_subscription_id=?, external_price_id=?, auto_renew=?, renews_at=? WHERE id=?")
        ->execute([$planId, $startsAt, $endsAt, $status, $orderId > 0 ? $orderId : ($existing['order_id'] ?? null), $provider !== '' ? $provider : ($existing['payment_provider'] ?? null), $customerId !== '' ? $customerId : ($existing['external_customer_id'] ?? null), $subscriptionId !== '' ? $subscriptionId : ($existing['external_subscription_id'] ?? null), $priceId !== '' ? $priceId : ($existing['external_price_id'] ?? null), $autoRenew, $renewsAt !== '' ? $renewsAt : null, $subId]);
  } else {
    $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status, order_id, source, payment_provider, external_customer_id, external_subscription_id, external_price_id, auto_renew, renews_at) VALUES (?,?,?,?,?,?,'storefront',?,?,?,?,?,?)")
        ->execute([$userId, $planId, $startsAt, $endsAt, $status, $orderId > 0 ? $orderId : null, $provider !== '' ? $provider : null, $customerId !== '' ? $customerId : null, $subscriptionId !== '' ? $subscriptionId : null, $priceId !== '' ? $priceId : null, $autoRenew, $renewsAt !== '' ? $renewsAt : null]);
    $subId = (int)$pdo->lastInsertId();
  }

  if ($status === 'active') iptv_cancel_other_active_subscriptions($pdo, $userId, $subId);
  return $subId;
}

function provision_storefront_order($orderId, $providerTxn){
  $pdo=db();
  $order = gc_storefront_load_order($pdo, (int)$orderId);
  if ($order['status']==='paid' && !empty($order['user_id'])) return (int)$order['user_id'];

  $resolved = gc_storefront_resolve_user_from_order($pdo, $order);
  $userId = (int)$resolved['user_id'];
  $createdUser = !empty($resolved['created_user']);
  $checkout = (array)($resolved['checkout'] ?? []);

  $planSt=$pdo->prepare("SELECT * FROM plans WHERE id=?");
  $planSt->execute([$order['plan_id']]);
  $plan=$planSt->fetch();
  if(!$plan) throw new Exception("Plan missing");

  try {
    $pdo->beginTransaction();
    $baseEnd = '';
    try {
      $stE = $pdo->prepare("SELECT MAX(ends_at) FROM subscriptions WHERE user_id=? AND status='active' AND ends_at>NOW()");
      $stE->execute([$userId]);
      $baseEnd = (string)($stE->fetchColumn() ?? '');
    } catch (Throwable $e) { $baseEnd = ''; }
    if ($baseEnd === '') $baseEnd = date('Y-m-d H:i:s');
    if (str_starts_with($baseEnd, '9999-')) throw new Exception('Account already has an unlimited subscription.');
    $durDays = (int)($plan['duration_days'] ?? 0);
    if ($durDays < 1) $durDays = 30;
    $expires = date('Y-m-d H:i:s', strtotime($baseEnd . " +{$durDays} days"));

    gc_upsert_subscription($pdo, [
      'user_id' => $userId,
      'plan_id' => (int)$order['plan_id'],
      'order_id' => (int)$orderId,
      'starts_at' => date('Y-m-d H:i:s'),
      'ends_at' => $expires,
      'status' => 'active',
      'payment_provider' => (string)($order['provider'] ?? ''),
      'auto_renew' => false,
      'renews_at' => $expires,
    ]);

    $pdo->prepare("UPDATE orders SET status='paid', provider_txn=?, paid_at=NOW(), user_id=? WHERE id=?")
        ->execute([$providerTxn,$userId,$orderId]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  unset($_SESSION['checkout_'.$orderId]);
  return $userId;
}

function gc_sync_stripe_subscription_from_order(PDO $pdo, int $orderId, array $stripeSubscription, ?string $invoiceId = null): int {
  $order = gc_storefront_load_order($pdo, $orderId);
  $resolved = gc_storefront_resolve_user_from_order($pdo, $order);
  $userId = (int)$resolved['user_id'];
  $createdUser = !empty($resolved['created_user']);
  $checkout = (array)($resolved['checkout'] ?? []);

  $planSt = $pdo->prepare("SELECT * FROM plans WHERE id=? LIMIT 1");
  $planSt->execute([(int)$order['plan_id']]);
  $plan = $planSt->fetch();
  if (!$plan) throw new Exception('Plan missing');

  $subId = (string)($stripeSubscription['id'] ?? '');
  $customerId = (string)($stripeSubscription['customer'] ?? '');
  $statusRaw = strtolower((string)($stripeSubscription['status'] ?? 'active'));
  $item = $stripeSubscription['items']['data'][0] ?? [];
  $priceId = (string)($item['price']['id'] ?? $order['stripe_price_id'] ?? '');
  $periodStartTs = (int)($stripeSubscription['current_period_start'] ?? time());
  $periodEndTs = (int)($stripeSubscription['current_period_end'] ?? time());
  if ($periodEndTs < 1) $periodEndTs = time() + ((int)($plan['duration_days'] ?? 30) * 86400);
  $startsAt = date('Y-m-d H:i:s', $periodStartTs > 0 ? $periodStartTs : time());
  $endsAt = date('Y-m-d H:i:s', $periodEndTs);
  $localStatus = in_array($statusRaw, ['canceled', 'unpaid', 'incomplete_expired'], true) ? 'cancelled' : 'active';
  $autoRenew = in_array($statusRaw, ['active', 'trialing', 'past_due', 'unpaid'], true) && empty($stripeSubscription['cancel_at_period_end']);

  try {
    $pdo->beginTransaction();
    gc_upsert_subscription($pdo, [
      'user_id' => $userId,
      'plan_id' => (int)$order['plan_id'],
      'order_id' => $orderId,
      'starts_at' => $startsAt,
      'ends_at' => $endsAt,
      'status' => $localStatus,
      'payment_provider' => 'stripe',
      'external_customer_id' => $customerId,
      'external_subscription_id' => $subId,
      'external_price_id' => $priceId,
      'auto_renew' => $autoRenew,
      'renews_at' => $endsAt,
    ]);

    $pdo->prepare("UPDATE orders SET user_id=?, status='paid', paid_at=COALESCE(paid_at, NOW()), provider_txn=?, billing_type='subscription', stripe_customer_id=?, stripe_subscription_id=?, stripe_invoice_id=COALESCE(?, stripe_invoice_id), stripe_price_id=? WHERE id=?")
        ->execute([$userId, $subId !== '' ? $subId : (string)($order['provider_txn'] ?? ''), $customerId !== '' ? $customerId : null, $subId !== '' ? $subId : null, $invoiceId, $priceId !== '' ? $priceId : null, $orderId]);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  unset($_SESSION['checkout_'.$orderId]);
  gc_notify_storefront_success($pdo, $orderId, $userId, $plan, gc_storefront_load_order($pdo, $orderId), $createdUser, $checkout);
  try { gc_email_send_subscription($pdo, $userId, (string)($plan['name'] ?? 'Plan'), $endsAt); } catch (Throwable $e) {}
  return $userId;
}

function gc_record_stripe_renewal_order(PDO $pdo, int $baseOrderId, int $userId, int $planId, string $invoiceId, string $subscriptionId, string $customerId, string $priceId, float $amount, string $currency): int {
  $st = $pdo->prepare("SELECT id FROM orders WHERE provider='stripe' AND provider_txn=? LIMIT 1");
  $st->execute([$invoiceId]);
  $existingId = (int)($st->fetchColumn() ?: 0);
  if ($existingId > 0) return $existingId;

  $base = gc_storefront_load_order($pdo, $baseOrderId);
  $st = $pdo->prepare("INSERT INTO orders (user_id, email, plan_id, amount, currency, provider, provider_txn, status, billing_type, stripe_customer_id, stripe_subscription_id, stripe_invoice_id, stripe_price_id, pending_allow_adult, pending_username, pending_password_hash, pending_password_enc, paid_at) VALUES (?,?,?,?,?,'stripe',?,'paid','subscription',?,?,?,?,?,?,?,?,NOW())");
  $st->execute([$userId, (string)($base['email'] ?? ''), $planId, $amount, strtoupper($currency ?: 'USD'), $invoiceId, $customerId !== '' ? $customerId : null, $subscriptionId !== '' ? $subscriptionId : null, $invoiceId, $priceId !== '' ? $priceId : null, (int)($base['pending_allow_adult'] ?? 0), (string)($base['pending_username'] ?? null), (string)($base['pending_password_hash'] ?? null), (string)($base['pending_password_enc'] ?? null)]);
  return (int)$pdo->lastInsertId();
}
