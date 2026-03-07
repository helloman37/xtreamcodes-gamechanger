<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/stripe_lib.php';
require_once __DIR__ . '/provision_storefront.php';

$pdo = db();
$orderId = (int)($_GET['order'] ?? 0);
$sessionId = trim((string)($_GET['session_id'] ?? ''));

$st = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$st->execute([$orderId]);
$order = $st->fetch();
if (!$order) die('Order not found');
if ($sessionId === '') $sessionId = (string)($order['provider_txn'] ?? '');

try {
  $session = stripe_get_checkout_session($sessionId);
  $mode = strtolower((string)($session['mode'] ?? ''));
  $status = strtolower((string)($session['payment_status'] ?? ''));
  $subscriptionId = '';
  if (is_array($session['subscription'] ?? null)) $subscriptionId = (string)($session['subscription']['id'] ?? '');
  else $subscriptionId = (string)($session['subscription'] ?? '');
  if ($mode !== 'subscription' || $subscriptionId === '') throw new Exception('Stripe subscription checkout not completed.');
  if (!in_array($status, ['paid', 'no_payment_required'], true)) throw new Exception('Stripe payment not completed yet.');

  $subscription = is_array($session['subscription'] ?? null) ? $session['subscription'] : stripe_get_subscription($subscriptionId);
  $userId = gc_sync_stripe_subscription_from_order($pdo, $orderId, $subscription);

  if (session_status() === PHP_SESSION_NONE) session_start();
  $_SESSION['store_user'] = $userId;
  header('Location: success.php?order=' . $orderId);
  exit;
} catch (Throwable $e) {
  die('Stripe verification error: ' . $e->getMessage());
}
