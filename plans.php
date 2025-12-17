<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Plans';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Plans</h1>
  <p>Choose your package and get instant access. Adult content can be enabled/disabled per account.</p>
  <div class="big-buttons">
    <a class="btn" href="/index.php">Home</a>
    <?php if (!empty($_SESSION['store_user'])): ?>
      <a class="btn primary" href="/portal/">Open Portal</a>
    <?php else: ?>
      <a class="btn primary" href="/login.php">Login</a>
    <?php endif; ?>
  </div>
</div>

<div class="card row" style="margin-top:18px;">
  <?php include __DIR__ . '/plans_grid.php'; ?>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
