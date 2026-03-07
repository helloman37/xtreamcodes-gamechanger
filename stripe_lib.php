<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function gc_stripe_runtime_settings(): array {
  try {
    $pdo = db();
    return gc_payment_settings($pdo)['stripe'];
  } catch (Throwable $e) {
    return [
      'enabled' => false,
      'publishable_key' => defined('STRIPE_PUBLISHABLE_KEY') ? (string)STRIPE_PUBLISHABLE_KEY : '',
      'secret_key' => defined('STRIPE_SECRET_KEY') ? (string)STRIPE_SECRET_KEY : '',
      'webhook_secret' => defined('STRIPE_WEBHOOK_SECRET') ? (string)STRIPE_WEBHOOK_SECRET : '',
      'mode' => defined('STRIPE_MODE') ? (string)STRIPE_MODE : 'test',
      'configured' => ((defined('STRIPE_PUBLISHABLE_KEY') ? (string)STRIPE_PUBLISHABLE_KEY : '') !== '' && (defined('STRIPE_SECRET_KEY') ? (string)STRIPE_SECRET_KEY : '') !== ''),
    ];
  }
}

function stripe_api_request(string $method, string $path, array $params = []): array {
  $cfg = gc_stripe_runtime_settings();
  $secret = trim((string)($cfg['secret_key'] ?? ''));
  if ($secret === '') throw new Exception('Stripe keys not set in Payment Gateways.');

  $url = 'https://api.stripe.com' . $path;
  $ch = curl_init();
  $headers = ['Authorization: Bearer ' . $secret];
  $method = strtoupper($method);

  if ($method === 'GET' && !empty($params)) {
    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
  } else {
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
  }

  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
  ]);

  if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

  $res = curl_exec($ch);
  if ($res === false) throw new Exception(curl_error($ch));
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $data = json_decode($res, true);
  if (!is_array($data)) throw new Exception('Invalid Stripe response.');
  if ($http >= 400) {
    $msg = (string)($data['error']['message'] ?? ('Stripe HTTP ' . $http));
    throw new Exception($msg);
  }
  return $data;
}

function stripe_create_subscription_checkout_session(array $order, array $plan, string $successUrl, string $cancelUrl): array {
  $priceId = trim((string)($plan['stripe_price_id'] ?? $order['stripe_price_id'] ?? ''));
  if ($priceId === '') throw new Exception('This plan is missing a Stripe Price ID.');
  $params = [
    'mode' => 'subscription',
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'client_reference_id' => (string)($order['id'] ?? ''),
    'customer_email' => (string)($order['email'] ?? ''),
    'metadata[order_id]' => (string)($order['id'] ?? ''),
    'metadata[plan_id]' => (string)($order['plan_id'] ?? ''),
    'line_items[0][quantity]' => '1',
    'line_items[0][price]' => $priceId,
    'subscription_data[metadata][order_id]' => (string)($order['id'] ?? ''),
    'subscription_data[metadata][plan_id]' => (string)($order['plan_id'] ?? ''),
  ];
  if (!empty($order['user_id'])) $params['subscription_data[metadata][user_id]'] = (string)$order['user_id'];
  return stripe_api_request('POST', '/v1/checkout/sessions', $params);
}

function stripe_get_checkout_session(string $sessionId): array {
  return stripe_api_request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId), [
    'expand[]' => 'subscription',
  ]);
}

function stripe_get_subscription(string $subscriptionId): array {
  return stripe_api_request('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId));
}

function stripe_verify_webhook_signature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool {
  if ($secret === '') return false;
  $parts = [];
  foreach (explode(',', $sigHeader) as $piece) {
    $kv = explode('=', trim($piece), 2);
    if (count($kv) === 2) $parts[$kv[0]] = $kv[1];
  }
  $ts = isset($parts['t']) ? (int)$parts['t'] : 0;
  $v1 = (string)($parts['v1'] ?? '');
  if ($ts < 1 || $v1 === '') return false;
  if (abs(time() - $ts) > $tolerance) return false;
  $signed = $ts . '.' . $payload;
  $expected = hash_hmac('sha256', $signed, $secret);
  return hash_equals($expected, $v1);
}
