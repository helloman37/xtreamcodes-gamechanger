<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/stripe_lib.php';

$pdo = db();
$orderId = (int)($_GET['order'] ?? 0);
$st = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$st->execute([$orderId]);
$order = $st->fetch();
if (!$order) die('Order not found');

if (!gc_payment_provider_is_available($pdo, 'stripe')) {
  flash_set('Stripe is not enabled or not configured yet.', 'error');
  header('Location: checkout.php?plan=' . (int)($order['plan_id'] ?? 0));
  exit;
}

$planSt = $pdo->prepare("SELECT * FROM plans WHERE id=? LIMIT 1");
$planSt->execute([(int)($order['plan_id'] ?? 0)]);
$plan = $planSt->fetch();
if (!$plan) die('Plan not found');
if (trim((string)($plan['stripe_price_id'] ?? '')) === '') die('Stripe Price ID missing on plan.');

try {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($basePath !== '' ? $basePath : '');
  $session = stripe_create_subscription_checkout_session(
    $order,
    $plan,
    $baseUrl . '/stripe_return.php?order=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}',
    $baseUrl . '/stripe_cancel.php?order=' . $orderId
  );

  $sessionId = (string)($session['id'] ?? '');
  $checkoutUrl = (string)($session['url'] ?? '');
  if ($sessionId === '' || $checkoutUrl === '') throw new Exception('Stripe checkout session failed.');

  $pdo->prepare("UPDATE orders SET provider_txn=?, billing_type='subscription', stripe_price_id=? WHERE id=?")
      ->execute([$sessionId, (string)($plan['stripe_price_id'] ?? ''), $orderId]);
  header('Location: ' . $checkoutUrl);
  exit;
} catch (Throwable $e) {
  die('Stripe error: ' . $e->getMessage());
}
