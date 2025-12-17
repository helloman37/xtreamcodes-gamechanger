<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

if (!empty($_SESSION['admin_id'])) { header('Location: dashboard.php'); exit; }
if (!empty($_SESSION['reseller_id'])) { header('Location: reseller_dashboard.php'); exit; }


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username'] ?? '');
  $p = $_POST['password'] ?? '';
  if (admin_login($u, $p)) {
    flash_set("Welcome back, $u", "success");
    header("Location: dashboard.php");
    exit;
  }
  // Try reseller login as a fallback (so resellers can use /admin/signin.php too)
  if (reseller_login($u, $p)) {
    flash_set("Welcome back, $u", "success");
    header("Location: reseller_dashboard.php");
    exit;
  }
  flash_set("Invalid login", "error");
}
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Xtream UI Game Changer - Admin Login</title>
  <link rel="stylesheet" href="login.css?v=1">
</head>
<body>
  <div class="login-bg">
    <div class="login-wrap">
      <div class="login-card">
        <div class="brand">
          <div class="xtream-logo" aria-label="XTREAM UI Game Changer">
            <span class="x">X</span><span class="tream">TREAM</span><span class="ui">ui</span>
          </div>
          <div class="gc">GAME CHANGER</div>
        </div>

        <div class="subhead">ADMIN &amp; RESELLER INTERFACE</div>

        <div class="form">
          <div class="flash-wrap"><?php flash_show(); ?></div>
          <form method="post" autocomplete="on">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" autocomplete="username" placeholder="Enter Your Username" required>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter Your Password" required>

            <button class="btn" type="submit">Login</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</body>
</html>