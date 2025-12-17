<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Store';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Instant Activation</h1>
  <p>Pick a plan, pay, and your line opens automatically. Your playlist and login show up instantly in your account dashboard.</p>
  <div class="big-buttons">
    <a class="btn primary" href="/plans.php">View Plans</a>
    <a class="btn ghost" href="/trial_start.php">Start 7‑Day Trial</a>
    <?php if (!empty($_SESSION['store_user'])): ?>
      <a class="btn" href="/portal/">Open Portal</a>
    <?php else: ?>
      <a class="btn" href="/login.php">Customer Login</a>
    <?php endif; ?>
  </div>

  <div class="hero-sub">
    <span class="chip">⚡ Fast activation</span>
    <span class="chip">📺 Works on any IPTV app</span>
    <span class="chip">🧾 M3U + XMLTV</span>
    <span class="chip">🔁 Renew anytime</span>
  </div>
</div>

<div class="card row" style="margin-top:18px;">
  <h2>Plans</h2>
  <?php include __DIR__ . '/plans_grid.php'; ?>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
