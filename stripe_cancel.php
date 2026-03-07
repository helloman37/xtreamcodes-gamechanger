<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/admin_notifications_lib.php';
session_start();
$pdo = db();
$orderId = (int)($_GET['order'] ?? 0);

$pdo->prepare("UPDATE orders SET status='failed' WHERE id=?")->execute([$orderId]);

try {
  if ($orderId > 0) {
    $st = $pdo->prepare("SELECT id,user_id,email,amount,currency,provider FROM orders WHERE id=? LIMIT 1");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $provider = (string)($o['provider'] ?? 'stripe');
    $amt = (string)($o['amount'] ?? '0.00');
    $email = (string)($o['email'] ?? '');
    $uid = (int)($o['user_id'] ?? 0);
    $msg = 'Order #' . (int)$orderId . ' failed/cancelled (' . $provider . ') — $' . $amt;
    if ($uid > 0) $msg .= ' — user_id ' . $uid;
    elseif ($email !== '') $msg .= ' — ' . $email;
    $msg .= '.';
    admin_notifications_broadcast($pdo, 'payment', 'Payment failed', $msg, '/admin/billing_reports.php', 'payfail:' . (int)$orderId);
  }
} catch (Throwable $t) {}
?>
<!doctype html><html><head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
<meta charset="utf-8"><link rel="stylesheet" href="store.css"><title>Cancelled</title></head>
<body><div class="wrap"><div class="card" style="max-width:520px;margin:0 auto;">
<h3>Payment Cancelled</h3>
<p class="muted">No charge was made. You can try again.</p>
<a class="btn" href="plans.php">Back to Plans</a>
</div></div></body></html>
