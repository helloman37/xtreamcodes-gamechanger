<?php
// Storefront Topbar (no session_start, no config include)
$baseFile = basename($_SERVER['PHP_SELF']);
?>
<div class="top">
  <div class="brand"><div class="dot"></div>IPTV PLATFORM</div>
  <div class="nav">
    <a href="index.php" <?= $baseFile==='index.php'?'style="color:#111;"':''; ?>>Home</a>
    <a href="plans.php" <?= $baseFile==='plans.php'?'style="color:#111;"':''; ?>>Plans</a>
    <?php if(!empty($_SESSION['store_user'])): ?>
      <a href="/portal/" <?= $baseFile==='portal'?'style="color:#111;"':''; ?>>Portal</a>
      <a href="dashboard.php" <?= $baseFile==='dashboard.php'?'style="color:#111;"':''; ?>>My Account</a>
      <a href="logout.php">Logout</a>
    <?php else: ?>
      <a href="login.php" <?= $baseFile==='login.php'?'style="color:#111;"':''; ?>>Login</a>
      <a href="register.php" <?= $baseFile==='register.php'?'style="color:#111;"':''; ?>>Register</a>
    <?php endif; ?>
    <a href="/admin/reseller_signin.php">Reseller</a>
  </div>
</div>
