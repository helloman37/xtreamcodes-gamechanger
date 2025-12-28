<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_reseller();

$pdo = db();
$reseller_id = (int)$_SESSION['reseller_id'];

$resellerStmt = $pdo->prepare("SELECT * FROM resellers WHERE id=? AND status='active' LIMIT 1");
$resellerStmt->execute([$reseller_id]);
$reseller = $resellerStmt->fetch(PDO::FETCH_ASSOC);
if (!$reseller) {
  flash_set("Reseller account not found or suspended.", "error");
  header("Location: reseller_signin.php"); exit;
}

$plans = $pdo->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);

// Prefill numeric credentials for convenience (reseller can overwrite)
$prefill_username = iptv_unique_numeric_username($pdo, 10);
$prefill_password = iptv_numeric_string(10);

/* Create user by reseller (cost: 1 credit per user) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
  $auto_gen = !empty($_POST['auto_gen']) ? 1 : 0;
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $name = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $plan_id  = (int)($_POST['plan_id'] ?? 0);
  $allow_adult = !empty($_POST['allow_adult']) ? 1 : 0;
  $unlimited = 0; // resellers cannot create unlimited subs

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set("Invalid email address.", "error");
    header("Location: reseller_dashboard.php"); exit;
  }

  if ($auto_gen) {
    $username = iptv_unique_numeric_username($pdo, 10);
    $password = iptv_numeric_string(10);
  }

  if ($username === '' || $password === '' || $plan_id <= 0) {
    flash_set("Please fill username, password, and plan.", "error");
    header("Location: reseller_dashboard.php"); exit;
  }

  
  // get plan
  $planStmt = $pdo->prepare("SELECT * FROM plans WHERE id=? LIMIT 1");
  $planStmt->execute([$plan_id]);
  $plan = $planStmt->fetch(PDO::FETCH_ASSOC);
  if (!$plan) {
    flash_set("Invalid plan selected.", "error");
    header("Location: reseller_dashboard.php"); exit;
  }

  $cost = (int)($plan['reseller_credits_cost'] ?? 1);
  if ($cost < 0) $cost = 0;

  // Reseller hard limits
  if (!empty($reseller['max_users'])) {
    $st = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE reseller_id=?");
    $st->execute([$reseller_id]);
    $c = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    if ($c >= (int)$reseller['max_users']) {
      flash_set("Reseller limit reached (max users).", "error");
      header("Location: reseller_dashboard.php"); exit;
    }
  }
  if (!empty($reseller['max_active_users'])) {
    $st = $pdo->prepare("
      SELECT COUNT(DISTINCT u.id) c
      FROM users u
      JOIN subscriptions s ON s.user_id=u.id AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())
      WHERE u.reseller_id=?
    ");
    $st->execute([$reseller_id]);
    $c = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    if ($c >= (int)$reseller['max_active_users']) {
      flash_set("Reseller limit reached (max active).", "error");
      header("Location: reseller_dashboard.php"); exit;
    }
  }

  // Credits check (unless reseller is unlimited)
  if (empty($reseller['unlimited'])) {
    if ((int)$reseller['credits'] < $cost) {
      flash_set("Not enough credits. Need {$cost}.", "error");
      header("Location: reseller_dashboard.php"); exit;
    }
  }

  // compute ends_at
  $starts_at = date("Y-m-d H:i:s");
  $ends_at = null;
  $duration_days = (int)($plan['duration_days'] ?? 0);
  if (!empty($reseller['max_days_per_sub']) && $duration_days > (int)$reseller['max_days_per_sub']) {
    $duration_days = (int)$reseller['max_days_per_sub'];
  }
  if (!$unlimited && $duration_days > 0) {
    $ends_at = date("Y-m-d H:i:s", strtotime("+{$duration_days} days"));
  }

  try {
    $pdo->beginTransaction();

    // ensure username unique
    $chk = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $chk->execute([$username]);
    if ($chk->fetch()) {
      throw new Exception("Username already exists.");
    }

    // insert user tied to reseller
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $enc  = iptv_encrypt($password);
    $uStmt = $pdo->prepare("INSERT INTO users (username, name, email, password_hash, password_enc, status, allow_adult, reseller_id) VALUES (?,?,?,?,?,?,?,?)");
    $uStmt->execute([$username, $name, $email, $hash, $enc, 'active', $allow_adult, $reseller_id]);
    $user_id = (int)$pdo->lastInsertId();

    // insert subscription
    $sStmt = $pdo->prepare("INSERT INTO subscriptions (user_id, plan_id, starts_at, ends_at, status) VALUES (?,?,?,?,?)");
    $sStmt->execute([$user_id, $plan_id, $starts_at, $ends_at, 'active']);

    // deduct credits
    if (empty($reseller['unlimited']) && $cost > 0) {
      $cStmt = $pdo->prepare("UPDATE resellers SET credits = credits - ? WHERE id=? AND credits >= ?");
      $cStmt->execute([$cost, $reseller_id, $cost]);
      if ($cStmt->rowCount() !== 1) {
        throw new Exception("Credit deduction failed.");
      }
    }

    $pdo->commit();
    flash_set("User created. Credits used: ".$cost, "success");
  } catch (Exception $e) {
	    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    flash_set("Create failed: ".$e->getMessage(), "error");
  }

  header("Location: reseller_dashboard.php"); exit;
}

// list reseller's recent users
$my_users = $pdo->prepare(
  "SELECT u.id, u.username, u.name, u.email, u.status, u.allow_adult, s.ends_at, p.name AS plan_name
   FROM users u
   LEFT JOIN subscriptions s ON s.user_id=u.id AND s.status='active'
   LEFT JOIN plans p ON p.id=s.plan_id
   WHERE u.reseller_id=?
   ORDER BY u.id DESC
   LIMIT 50"
);
$my_users->execute([$reseller_id]);
$my_users = $my_users->fetchAll(PDO::FETCH_ASSOC);

$topbar = file_get_contents(__DIR__ . '/reseller_topbar.html');
$_credits = (int)($reseller['credits'] ?? 0);
$topbar = str_replace('{{CREDITS}}', (string)$_credits, $topbar);
if ($_credits <= 0) { $topbar = str_replace('dot-green', 'dot-red', $topbar); }
$topbar = str_replace('{{USERNAME}}', e($_SESSION['reseller_username'] ?? 'Reseller'), $topbar);
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Reseller Dashboard</title>
  <link rel="stylesheet" href="panel.css">
  <link rel="stylesheet" href="reseller_xtream.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <h2 style="margin:0;">Welcome, <?= e($_SESSION['reseller_username'] ?? 'Reseller') ?></h2>
    <span class="pill good">Credits: <?= (int)$reseller['credits'] ?></span>
  </div>
  <?php flash_show(); ?>
</div>

<div class="card" style="margin-top:15px;">
  <h3>Create New User (cost: 1 credit)</h3>
  <form method="post">
    <input type="hidden" name="create_user" value="1">
    <div class="row">
      <label>Subscriber Name</label>
      <input name="name" placeholder="John Doe">
    </div>
    <div class="row">
      <label>Subscriber Email</label>
      <input name="email" type="email" placeholder="user@example.com">
    </div>
    <div class="row">
      <label>Username</label>
      <input id="username" name="username" value="<?= e($prefill_username) ?>" required>
    </div>
    <div class="row">
      <label>Password</label>
      <input id="password" name="password" type="text" value="<?= e($prefill_password) ?>" required>
    </div>

    <div class="row" style="margin-top:8px;">
      <label style="display:block;">
        <input type="checkbox" id="auto_gen" name="auto_gen" value="1" checked>
        Auto-generate numeric username &amp; password
      </label>
      <button type="button" id="regen_btn" class="btn gray" style="margin-top:8px;">Regenerate</button>
    </div>
    <div class="row">
      <label>Plan</label>
      <select name="plan_id" required>
        <option value="">--select--</option>
        <?php foreach($plans as $pl): ?>
          <option value="<?= (int)$pl['id'] ?>">
            <?= e($pl['name']) ?> ($<?= e($pl['price']) ?> / <?= (int)$pl['duration_days'] ?>d)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
    </div>
    <div class="row">
      <label><input type="checkbox" name="allow_adult" value="1"> Allow Adult Content</label>
    </div>
    <button class="btn" type="submit">Create User</button>
  </form>
</div>

<script>
  (function(){
    const autoGen = document.getElementById('auto_gen');
    const u = document.getElementById('username');
    const p = document.getElementById('password');
    const btn = document.getElementById('regen_btn');
    if (!autoGen || !u || !p || !btn) return;
    function randDigits(n){
      let s='';
      for (let i=0;i<n;i++) s += Math.floor(Math.random()*10);
      return s;
    }
    function regen(){
      u.value = randDigits(10);
      p.value = randDigits(10);
    }
    btn.addEventListener('click', regen);
    autoGen.addEventListener('change', function(){
      if (autoGen.checked) regen();
    });
  })();
</script>

<div class="card" style="margin-top:15px;">
  <h3>My Recent My Users</h3>
  <table class="table">
    <thead><tr>
      <th>ID</th><th>Username</th><th>Name</th><th>Email</th><th>Plan</th><th>Expires</th><th>Status</th><th>Adult</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach($my_users as $u): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><?= e($u['username']) ?></td>
        <td><?= e($u['name'] ?? '') ?></td>
        <td><?= e($u['email'] ?? '') ?></td>
        <td><?= e($u['plan_name'] ?? '-') ?></td>
        <td><?= $u['ends_at'] ? e($u['ends_at']) : 'Unlimited' ?></td>
        <td><?= e($u['status']) ?></td>
        <td><?= (int)$u['allow_adult'] ? 'Yes' : 'No' ?></td>
        <td><a class="btn btn-small" href="reseller_users.php?edit=<?= (int)$u['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body></html>