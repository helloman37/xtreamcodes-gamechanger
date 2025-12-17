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
<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Register';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';
?>

<div class="card hero">
  <h1>Create Account</h1>
  <p class="muted">Create your subscriber account to access the portal.</p>
</div>

<div class="card" style="max-width:560px;">
  <?php if ($err): ?>
    <div class="notice" style="border-color: rgba(255,80,80,.35); background: rgba(120,0,0,.18);">
      <?= e($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" class="form" autocomplete="off">
    <?= csrf_input() ?>
    <label>Username</label>
    <input class="input" name="username" value="<?= e($_POST['username'] ?? '') ?>" required>

    <label style="margin-top:10px;">Password</label>
    <input class="input" type="password" name="password" required>

    <label style="margin-top:10px;display:flex;gap:8px;align-items:center;">
      <input type="checkbox" name="allow_adult" value="1" <?= !empty($_POST['allow_adult']) ? 'checked' : '' ?>>
      Allow adult content on this account (optional)
    </label>

    <button class="btn primary" style="margin-top:14px;width:100%;" type="submit">Create Account</button>

    <p class="muted" style="margin-top:10px;">
      Already have an account? <a href="/login.php">Login</a>
    </p>
  </form>
</div>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>
