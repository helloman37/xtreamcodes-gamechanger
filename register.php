<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/admin_notifications_lib.php';
require_once __DIR__ . '/email_lib.php';
session_start();
$pdo = db();


// Global maintenance mode: block storefront pages while enabled
try {
  if (function_exists('gc_enforce_maintenance')) {
    gc_enforce_maintenance($pdo, ['format' => 'html']);
  }
} catch (Throwable $e) { /* ignore */ }

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  // reCAPTCHA (if enabled)
  $rec = gc_recaptcha_verify_post($pdo, $_POST['g-recaptcha-response'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null);
  if (!$rec['ok']) {
    $err = $rec['error'] ?? 'reCAPTCHA verification failed.';
  }

  if ($err === null) {
  $username = trim($_POST['username'] ?? '');
  $name = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $password = $_POST['password'] ?? '';
  $allow_adult = isset($_POST['allow_adult']) ? 1 : 0;

  if ($username === '' || $password === '' || $email === '') {
    $err = "Username, email, and password are required.";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = "Please enter a valid email address.";
  } else {
    // unique checks
    $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    if ($st->fetch()) {
      $err = "That username is taken.";
    } else {
      $st = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
      $st->execute([$email]);
      if ($st->fetch()) {
        $err = "That email address is already registered.";
      }
    }

    if ($err === null) {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $enc  = iptv_encrypt($password);
      $pdo->prepare("INSERT INTO users (username,name,email,password_hash,password_enc,status,allow_adult) VALUES (?,?,?,?,?, 'active', ?)")
          ->execute([$username,$name,$email,$hash,$enc,$allow_adult]);
      $uid = (int)$pdo->lastInsertId();

      // Admin notify: new user joined
      admin_notifications_broadcast($pdo, 'user', 'New user joined', $username . ' created an account.', '/admin/user_accounts.php?edit=' . $uid, 'newuser:' . $uid);

      // Email notifications
      try { gc_email_send_welcome($pdo, $uid); } catch (Throwable $t) {}
      try { gc_email_send_verification($pdo, $uid); } catch (Throwable $t) {}

      $_SESSION['store_user'] = $uid;
      if (gc_email_verification_required($pdo)) {
        header("Location: verify_needed.php");
      } else {
        header("Location: dashboard.php");
      }
      exit;
    }
  }
  }

}
?>
<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Register';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Create Account</h1>
  <p class="muted">Create your subscriber account to access the portal.</p>
</div>

<div class="card row auth-wrap" style="margin-top:18px; padding:18px;">
  <?php if ($err): ?>
    <div class="notice" style="border-color: rgba(255,80,80,.35); background: rgba(120,0,0,.18);">
      <?= e($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off" style="margin-top:10px;">
    <?= csrf_input() ?>
    <label>Name (optional)</label>
    <input class="input" name="name" value="<?= e($_POST['name'] ?? '') ?>">

    <label style="margin-top:10px;">Email</label>
    <input class="input" name="email" type="email" value="<?= e($_POST['email'] ?? '') ?>" required>

    <label>Username</label>
    <input class="input" name="username" value="<?= e($_POST['username'] ?? '') ?>" required>

    <label>Password</label>
    <input class="input" type="password" name="password" required>

    <?= gc_recaptcha_render_widget($pdo) ?>

    <div class="checkline">
      <input type="checkbox" id="allow_adult" name="allow_adult" value="1" <?= !empty($_POST['allow_adult']) ? 'checked' : '' ?>>
      <label for="allow_adult">Allow adult content on this account (optional)</label>
    </div>

    <div style="display:flex; gap:10px; align-items:center; margin-top:14px; flex-wrap:wrap;">
      <button class="btn primary" type="submit">Create Account</button>
      <a class="btn" href="/login.php">Login</a>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
