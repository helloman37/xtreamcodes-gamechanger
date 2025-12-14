<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
session_start();
$pdo = db();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $allow_adult = isset($_POST['allow_adult']) ? 1 : 0;

  if ($username === '' || $password === '') {
    $err = "Username and password are required.";
  } else {
    // unique check
    $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    if ($st->fetch()) {
      $err = "That username is taken.";
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $enc  = iptv_encrypt($password);
      $pdo->prepare("INSERT INTO users (username,password_hash,password_enc,status,allow_adult) VALUES (?,?,?, 'active', ?)")
          ->execute([$username,$hash,$enc,$allow_adult]);
      $uid = (int)$pdo->lastInsertId();
      $_SESSION['store_user'] = $uid;
      header("Location: dashboard.php"); exit;
    }
  }
}
?>
<!doctype html>
<html><head>
  <meta charset="utf-8">
  <title>Create Account</title>
  <link rel="stylesheet" href="store.css">
</head><body>
<div class="wrap">
  <?php include __DIR__."/topbar.php"; ?>
  <div class="card" style="max-width:460px;margin:0 auto;">
    <h3>Create your account</h3>
    <?php if($err): ?><p style="color:#b91c1c;font-weight:800;"><?=e($err)?></p><?php endif; ?>
    <form method="post">
      <label>Username</label>
      <input class="input" name="username" required>
      <label style="margin-top:10px;">Password</label>
      <input class="input" type="password" name="password" required>
      <label style="margin-top:10px;display:flex;gap:8px;align-items:center;">
        <input type="checkbox" name="allow_adult" value="1">
        Allow adult content on this account (optional)
      </label>
      <button class="btn green" style="margin-top:14px;" type="submit">Create Account</button>
      <p class="muted" style="margin-top:8px;">Already have an account? <a href="login.php">Login</a></p>
    </form>
  </div>
</div>
</body></html>
