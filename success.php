<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
session_start();
$pdo=db();
$orderId=(int)($_GET['order'] ?? 0);
$st=$pdo->prepare("SELECT o.*, p.name plan_name FROM orders o JOIN plans p ON p.id=o.plan_id WHERE o.id=?");
$st->execute([$orderId]);
$o=$st->fetch();
?>
<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Success';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Payment Successful</h1>
  <p class="muted">Your account is active.</p>
  <div class="big-buttons">
    <a class="btn primary" href="/dashboard.php">Go to My Account</a>
    <a class="btn" href="/portal/">Open Portal</a>
  </div>
</div>

<?php if ($o): ?>
  <div class="card" style="max-width:640px;">
    <h3 style="margin:0 0 10px;">Order #<?= (int)$o['id'] ?></h3>
    <div class="muted">Plan</div>
    <div style="font-size:18px;font-weight:900;margin-top:2px;"><?= e($o['plan_name'] ?? '') ?></div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
