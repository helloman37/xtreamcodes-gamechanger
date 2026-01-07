<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email_lib.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$pdo = db();

// If not logged in, send to login
if (empty($_SESSION['store_user'])) {
  header('Location: /login.php');
  exit;
}

$userId = is_array($_SESSION['store_user']) ? (int)($_SESSION['store_user']['id'] ?? 0) : (int)$_SESSION['store_user'];
if ($userId < 1) {
  header('Location: /logout.php');
  exit;
}

$u = gc_email_user_row($pdo, $userId);
if (!$u) {
  header('Location: /logout.php');
  exit;
}

if (!gc_email_verification_required($pdo) || gc_email_user_is_verified($u)) {
  header('Location: /dashboard.php');
  exit;
}

$email = trim((string)($u['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
  csrf_check();
  $ok = false;
  try {
    $ok = gc_email_send_verification($pdo, $userId, true);
  } catch (Throwable $t) {}
  flash_set($ok ? 'Verification email re-sent.' : 'Failed to send verification email. Contact support.', $ok ? 'success' : 'error');
  header('Location: /verify_needed.php');
  exit;
}

$PUBLIC_TITLE = 'Verify your email';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Email verification required</h1>
  <p class="muted">Please verify your email before using the service.</p>
</div>

<div class="card" style="margin-top:18px; padding:18px;">
  <?php flash_show(); ?>

  <p style="margin:0 0 10px;">
    We sent a verification link to:
    <b><?= e($email ?: 'your email address') ?></b>
  </p>

  <p class="muted" style="margin:0 0 14px;">If you don't see it, check your spam/junk folder.</p>

  <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <?= csrf_input() ?>
    <button class="btn" type="submit" name="resend" value="1">Resend verification email</button>
    <a class="btn" href="/dashboard.php">Back to dashboard</a>
    <a class="btn" href="/logout.php">Logout</a>
  </form>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
