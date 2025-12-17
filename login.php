<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $st = $pdo->prepare("SELECT * FROM users WHERE username=?");
  $st->execute([$username]);
  $u = $st->fetch();
  if ($u && password_verify($password, $u['password_hash'])) {
    $_SESSION['store_user'] = (int)$u['id'];

    // If they have an active subscription, send them straight to the portal.
    try {
      $sub = $pdo->prepare("SELECT 1 FROM subscriptions WHERE user_id=? AND status='active' AND (ends_at IS NULL OR ends_at>NOW()) LIMIT 1");
      $sub->execute([(int)$u['id']]);
      if ($sub->fetchColumn()) {
        header("Location: /portal/");
        exit;
      }
    } catch (Throwable $e) {}

    header("Location: /dashboard.php");
    exit;
  }
  $err = "Invalid login";
}

$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Login';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Customer Login</h1>
  <p>Sign in to view your playlist, EPG, subscription status, and open the portal.</p>
</div>

<div class="card row auth-wrap" style="margin-top:18px; padding:18px;">
  <?php if (!empty($err)): ?>
    <div class="notice" style="border-color: rgba(255,107,107,.35); color: rgba(255,107,107,.95);">
      <?= e($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="on" style="margin-top:10px;">
    <label>Username</label>
    <input class="input" name="username" required>

    <label>Password</label>
    <input class="input" type="password" name="password" required>

    <div style="display:flex; gap:10px; align-items:center; margin-top:14px; flex-wrap:wrap;">
      <button class="btn primary" type="submit">Login</button>
      <a class="btn" href="/register.php">Create Account</a>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
