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
if (!$reseller) { flash_set('Reseller not found or suspended.', 'error'); header('Location: reseller_signin.php'); exit; }

$plans = $pdo->query("SELECT * FROM plans ORDER BY price ASC")->fetchAll(PDO::FETCH_ASSOC);

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
  $user_id = (int)($_POST['user_id'] ?? 0);
  $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
  $allow_adult = !empty($_POST['allow_adult']) ? 1 : 0;
  $name  = trim((string)($_POST['name'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  // Admin-only responsibility: subscription overrides/plan changes are not allowed in reseller panel.
  $new_pass = trim($_POST['new_password'] ?? '');

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set("Invalid email address.", "error");
    header("Location: reseller_users.php?edit=".$user_id);
    exit;
  }

  // verify ownership
  $own = $pdo->prepare("SELECT * FROM users WHERE id=? AND reseller_id=? LIMIT 1");
  $own->execute([$user_id, $reseller_id]);
  $user = $own->fetch(PDO::FETCH_ASSOC);
  if (!$user) {
    flash_set("User not found or not yours.", "error");
    header("Location: reseller_users.php"); exit;
  }

  try {
    $pdo->beginTransaction();

    if ($new_pass !== '') {
      $hash = password_hash($new_pass, PASSWORD_BCRYPT);
      $enc  = iptv_encrypt($new_pass);
      $uStmt = $pdo->prepare("UPDATE users SET name=?, email=?, password_hash=?, password_enc=?, status=?, allow_adult=? WHERE id=? AND reseller_id=?");
      $uStmt->execute([$name, $email, $hash, $enc, $status, $allow_adult, $user_id, $reseller_id]);
    } else {
      $uStmt = $pdo->prepare("UPDATE users SET name=?, email=?, status=?, allow_adult=? WHERE id=? AND reseller_id=?");
      $uStmt->execute([$name, $email, $status, $allow_adult, $user_id, $reseller_id]);
    }

    $pdo->commit();
    flash_set("User updated.", "success");
  } catch (Exception $e) {
    $pdo->rollBack();
    flash_set("Update failed: ".$e->getMessage(), "error");
  }

  header("Location: reseller_users.php?edit=".$user_id); exit;
}

$edit_user = null;
if ($edit_id > 0) {
  $st = $pdo->prepare(
    "SELECT u.*, s.plan_id, s.ends_at, p.name plan_name
     FROM users u
     LEFT JOIN subscriptions s ON s.user_id=u.id AND s.status='active'
     LEFT JOIN plans p ON p.id=s.plan_id
     WHERE u.id=? AND u.reseller_id=? LIMIT 1"
  );
  $st->execute([$edit_id, $reseller_id]);
  $edit_user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$edit_user) $edit_id = 0;
}

$my_users = $pdo->prepare(
  "SELECT u.id, u.username, u.name, u.email, u.status, u.allow_adult, s.ends_at, p.name plan_name, s.plan_id
   FROM users u
   LEFT JOIN subscriptions s ON s.user_id=u.id AND s.status='active'
   LEFT JOIN plans p ON p.id=s.plan_id
   WHERE u.reseller_id=?
   ORDER BY u.id DESC"
);
$my_users->execute([$reseller_id]);
$my_users = $my_users->fetchAll(PDO::FETCH_ASSOC);

$topbar = file_get_contents(__DIR__ . '/reseller_topbar.html');
$_credits = (int)($reseller['credits'] ?? 0);
$topbar = str_replace('{{CREDITS}}', (string)$_credits, $topbar);
if ($_credits <= 0) { $topbar = str_replace('dot-green', 'dot-red', $topbar); }
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>My My Users</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>
<h1 style="margin-top:10px;">My My Users</h1>
<?php flash_show(); ?>

<?php if ($edit_id && $edit_user): ?>
  <div class="card" style="margin-top:15px;">
    <h3>Edit User: <?= e($edit_user['username']) ?></h3>
    <form method="post">
      <input type="hidden" name="save_user" value="1">
      <input type="hidden" name="user_id" value="<?= (int)$edit_user['id'] ?>">
      <div class="row">
        <label>Subscriber Name</label>
        <input name="name" value="<?= e($edit_user['name'] ?? '') ?>" placeholder="John Doe">
      </div>
      <div class="row">
        <label>Subscriber Email</label>
        <input name="email" type="email" value="<?= e($edit_user['email'] ?? '') ?>" placeholder="user@example.com">
      </div>
      <div class="row">
        <label>Status</label>
        <select name="status">
          <option value="active" <?= $edit_user['status']==='active'?'selected':'' ?>>Active</option>
          <option value="suspended" <?= $edit_user['status']==='suspended'?'selected':'' ?>>Suspended</option>
        </select>
      </div>
      <div class="row">
        <label>Allow Adult Content</label>
        <input type="checkbox" name="allow_adult" value="1" <?= (int)$edit_user['allow_adult']?'checked':'' ?>>
      </div>
      <div class="row">
        <label>New Password (leave blank to keep)</label>
        <input name="new_password" type="text" placeholder="new password">
      </div>
      <hr>
      <div class="row">
        <small style="opacity:.8;">
          Subscription changes (plan/renewals/overrides) are admin-only. Resellers can edit subscriber info and reset passwords.
        </small>
      </div>
      <button class="btn" type="submit">Save Changes</button>
      <a class="btn" href="reseller_users.php" style="margin-left:8px;">Close</a>
    </form>
  </div>
<?php endif; ?>

<div class="card" style="margin-top:15px;">
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