<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
session_start();
$pdo=db();

// Global maintenance mode: block storefront pages while enabled
try {
  if (function_exists('gc_enforce_maintenance')) {
    gc_enforce_maintenance($pdo, ['format' => 'html']);
  }
} catch (Throwable $e) { /* ignore */ }

$orderId=(int)($_GET['order'] ?? 0);
$st=$pdo->prepare("SELECT * FROM orders WHERE id=?");
$st->execute([$orderId]);
$order=$st->fetch();
if(!$order) die("Order not found");
$payCfg = gc_payment_settings($pdo);
$cashtag = trim((string)($payCfg['cashapp']['cashtag'] ?? ''));
if($cashtag && $cashtag[0] !== '$') $cashtag='$'.$cashtag;
$payUrl = $cashtag ? "https://cash.app/".rawurlencode($cashtag) : "https://cash.app/";
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=".urlencode($payUrl);
?>
<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — CashApp Payment';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>CashApp Payment</h1>
  <p class="muted">Pay using CashApp, then provide your Order ID for activation.</p>
</div>

<div class="grid" style="grid-template-columns: 1.2fr .8fr; gap:18px;">
  <div class="card">
    <h3 style="margin:0 0 10px;">Order #<?= (int)$orderId ?></h3>
    <div class="muted">Total</div>
    <div style="font-size:28px;font-weight:900;margin-top:2px;">
      $<?= number_format((float)($order['amount'] ?? 0), 2) ?>
    </div>

    <div style="margin-top:14px;" class="muted">
      After payment, contact support and include your Order ID so we can activate immediately.
    </div>

    <?php if ($cashtag): ?>
      <div style="margin-top:14px;" class="badge">Send to <?= e($cashtag) ?></div>
      <a class="btn primary" style="margin-top:12px;" href="<?= e($payUrl) ?>" target="_blank" rel="noopener">Open CashApp</a>
    <?php else: ?>
      <div class="notice" style="margin-top:14px;">No CashApp cashtag configured.</div>
    <?php endif; ?>

    <div style="margin-top:14px;">
      <a class="btn" href="/dashboard.php">Back to My Account</a>
    </div>
  </div>

  <div class="card" style="text-align:center;">
    <h3 style="margin:0 0 10px;">Scan to Pay</h3>
    <?php if ($cashtag): ?>
      <img src="<?= e($qrUrl) ?>" alt="CashApp QR" style="width:240px;height:240px;border-radius:18px;border:1px solid rgba(255,255,255,.12);">
      <div class="muted" style="margin-top:10px;">Order ID: <b>#<?= (int)$orderId ?></b></div>
    <?php else: ?>
      <div class="muted">QR unavailable.</div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
