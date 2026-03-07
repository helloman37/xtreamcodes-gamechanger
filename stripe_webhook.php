<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/stripe_lib.php';
require_once __DIR__ . '/provision_storefront.php';
require_once __DIR__ . '/admin_notifications_lib.php';

$pdo = db();
$cfg = gc_stripe_runtime_settings();
$secret = trim((string)($cfg['webhook_secret'] ?? ''));
$payload = file_get_contents('php://input') ?: '';
$sig = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if ($secret === '') {
  http_response_code(500);
  echo 'Stripe webhook secret not configured.';
  exit;
}
if (!stripe_verify_webhook_signature($payload, $sig, $secret)) {
  http_response_code(400);
  echo 'Invalid signature.';
  exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
  http_response_code(400);
  echo 'Invalid JSON.';
  exit;
}
$eventId = (string)($event['id'] ?? '');
if ($eventId === '') {
  http_response_code(400);
  echo 'Missing event id.';
  exit;
}
try {
  $pdo->prepare("INSERT INTO payment_webhook_events (provider, event_id) VALUES ('stripe', ?)")->execute([$eventId]);
} catch (Throwable $e) {
  http_response_code(200);
  echo 'duplicate';
  exit;
}

$type = (string)($event['type'] ?? '');
$obj = $event['data']['object'] ?? [];
if (!is_array($obj)) $obj = [];

function gc_stripe_order_id_from_meta(array $obj): int {
  $meta = (array)($obj['metadata'] ?? []);
  $id = (int)($meta['order_id'] ?? 0);
  if ($id > 0) return $id;
  $cr = (int)($obj['client_reference_id'] ?? 0);
  return $cr > 0 ? $cr : 0;
}

try {
  switch ($type) {
    case 'checkout.session.completed':
      $orderId = gc_stripe_order_id_from_meta($obj);
      $subscriptionId = '';
      if (is_array($obj['subscription'] ?? null)) $subscriptionId = (string)($obj['subscription']['id'] ?? '');
      else $subscriptionId = (string)($obj['subscription'] ?? '');
      if ($orderId > 0 && $subscriptionId !== '') {
        $subscription = is_array($obj['subscription'] ?? null) ? $obj['subscription'] : stripe_get_subscription($subscriptionId);
        gc_sync_stripe_subscription_from_order($pdo, $orderId, $subscription);
      }
      break;

    case 'invoice.paid':
      $subscriptionId = (string)($obj['subscription'] ?? '');
      if ($subscriptionId !== '') {
        $subscription = stripe_get_subscription($subscriptionId);
        $meta = (array)($subscription['metadata'] ?? []);
        $orderId = (int)($meta['order_id'] ?? 0);
        if ($orderId < 1) {
          $st = $pdo->prepare("SELECT id FROM orders WHERE stripe_subscription_id=? ORDER BY id ASC LIMIT 1");
          $st->execute([$subscriptionId]);
          $orderId = (int)($st->fetchColumn() ?: 0);
        }
        if ($orderId > 0) {
          $userId = gc_sync_stripe_subscription_from_order($pdo, $orderId, $subscription, (string)($obj['id'] ?? ''));
          $billingReason = strtolower((string)($obj['billing_reason'] ?? ''));
          if ($billingReason !== 'subscription_create') {
            $amount = ((int)($obj['amount_paid'] ?? 0)) / 100;
            $currency = strtoupper((string)($obj['currency'] ?? 'USD'));
            $item = $subscription['items']['data'][0] ?? [];
            $priceId = (string)($item['price']['id'] ?? '');
            gc_record_stripe_renewal_order($pdo, $orderId, $userId, (int)($meta['plan_id'] ?? 0 ?: 0) ?: (int)(gc_storefront_load_order($pdo, $orderId)['plan_id'] ?? 0), (string)($obj['id'] ?? ''), $subscriptionId, (string)($subscription['customer'] ?? ''), $priceId, (float)$amount, $currency);
          }
        }
      }
      break;

    case 'invoice.payment_failed':
      $subscriptionId = (string)($obj['subscription'] ?? '');
      if ($subscriptionId !== '') {
        try {
          admin_notifications_broadcast($pdo, 'payment', 'Stripe renewal failed', 'Subscription ' . $subscriptionId . ' had a failed renewal invoice.', '/admin/billing_reports.php', 'stripefail:' . $subscriptionId . ':' . (string)($obj['id'] ?? '')); 
        } catch (Throwable $t) {}
      }
      break;

    case 'customer.subscription.updated':
    case 'customer.subscription.deleted':
      $subscription = $obj;
      $meta = (array)($subscription['metadata'] ?? []);
      $orderId = (int)($meta['order_id'] ?? 0);
      if ($orderId < 1) {
        $st = $pdo->prepare("SELECT id FROM orders WHERE stripe_subscription_id=? ORDER BY id ASC LIMIT 1");
        $st->execute([(string)($subscription['id'] ?? '')]);
        $orderId = (int)($st->fetchColumn() ?: 0);
      }
      if ($orderId > 0) gc_sync_stripe_subscription_from_order($pdo, $orderId, $subscription);
      break;
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo 'Webhook error: ' . $e->getMessage();
  exit;
}

http_response_code(200);
echo 'ok';
