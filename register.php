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

<div class="card row auth-wrap" style="margin-top:18px; padding:18px;">
  <?php if ($err): ?>
    <div class="notice" style="border-color: rgba(255,80,80,.35); background: rgba(120,0,0,.18);">
      <?= e($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="off" style="margin-top:10px;">
    <?= csrf_input() ?>
    <label>Username</label>
    <input class="input" name="username" value="<?= e($_POST['username'] ?? '') ?>" required>

    <label>Password</label>
    <input class="input" type="password" name="password" required>

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
