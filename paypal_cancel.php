<?php
require_once __DIR__ . '/db.php';
session_start();
$pdo=db();
$orderId=(int)($_GET['order'] ?? 0);
$pdo->prepare("UPDATE orders SET status='failed' WHERE id=?")->execute([$orderId]);
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
