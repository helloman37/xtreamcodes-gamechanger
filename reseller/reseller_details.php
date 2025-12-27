<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();
$reseller_id = (int)($_GET['id'] ?? 0);
if ($reseller_id <= 0) {
  flash_set('Missing reseller id.', 'error');
  header('Location: resellers.php');
  exit;
}

$rStmt = $pdo->prepare("SELECT * FROM resellers WHERE id=? LIMIT 1");
$rStmt->execute([$reseller_id]);
$reseller = $rStmt->fetch(PDO::FETCH_ASSOC);
if (!$reseller) {
  flash_set('Reseller not found.', 'error');
  header('Location: resellers.php');
  exit;
}

$mode = ($_GET['mode'] ?? 'active') === 'all' ? 'all' : 'active';

// summary stats
$totalStmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE reseller_id=?");
$totalStmt->execute([$reseller_id]);
$users_total = (int)($totalStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

$activeStmt = $pdo->prepare(
  "SELECT COUNT(DISTINCT u.id) c
   FROM users u
   JOIN subscriptions s ON s.user_id=u.id
   WHERE u.reseller_id=?
     AND s.status='active'
     AND (s.ends_at IS NULL OR s.ends_at>NOW())"
);
$activeStmt->execute([$reseller_id]);
$users_active = (int)($activeStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

// list users
$where = "u.reseller_id=?";
if ($mode === 'active') {
  $where .= " AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())";
}

$uStmt = $pdo->prepare(
  "SELECT u.id, u.username, u.name, u.email, u.status, u.allow_adult, u.created_at,
          s.ends_at, s.status AS sub_status,
          p.name AS plan_name
   FROM users u
   LEFT JOIN subscriptions s ON s.user_id=u.id AND s.status='active'
   LEFT JOIN plans p ON p.id=s.plan_id
   WHERE $where
   ORDER BY u.id DESC"
);
$uStmt->execute([$reseller_id]);
$users = $uStmt->fetchAll(PDO::FETCH_ASSOC);

$topbar = file_get_contents(__DIR__ . '/topbar.html');
?><!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Reseller Details</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
  <h1 style="margin:10px 0;">Reseller Details: <?= e($reseller['username']) ?></h1>
  <a class="btn" href="resellers.php">&larr; Back</a>
</div>

<?php flash_show(); ?>

<div class="card" style="margin-top:12px;">
  <div class="grid2">
    <div>
      <div><strong>ID:</strong> <?= (int)$reseller['id'] ?></div>
      <div><strong>Status:</strong> <?= e($reseller['status']) ?></div>
      <div><strong>Credits:</strong> <?= (int)$reseller['credits'] ?></div>
    </div>
    <div>
      <div><strong>Users:</strong> <?= (int)$users_total ?> total</div>
      <div><strong>Active subs:</strong> <?= (int)$users_active ?></div>
      <div><strong>Created:</strong> <?= e($reseller['created_at'] ?? '-') ?></div>
    </div>
  </div>

  <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn btn-small" href="reseller_details.php?id=<?= (int)$reseller_id ?>&mode=active" <?= $mode==='active'?'style="opacity:.7; pointer-events:none;"':'' ?>>Active only</a>
    <a class="btn btn-small" href="reseller_details.php?id=<?= (int)$reseller_id ?>&mode=all" <?= $mode==='all'?'style="opacity:.7; pointer-events:none;"':'' ?>>All users</a>
  </div>
</div>

<div class="card" style="margin-top:12px;">
  <h3 style="margin:0 0 10px 0;">Users</h3>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Username</th>
        <th>Name</th>
        <th>Email</th>
        <th>Plan</th>
        <th>Expires</th>
        <th>Status</th>
        <th>Adult</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$users): ?>
      <tr><td colspan="9" style="text-align:center; opacity:.8;">No users found.</td></tr>
    <?php else: foreach ($users as $u): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><?= e($u['username']) ?></td>
        <td><?= e($u['name'] ?? '') ?></td>
        <td><?= e($u['email'] ?? '') ?></td>
        <td><?= e($u['plan_name'] ?? '-') ?></td>
        <td><?= $u['ends_at'] ? e($u['ends_at']) : 'Unlimited' ?></td>
        <td><?= e($u['status']) ?></td>
        <td><?= (int)$u['allow_adult'] ? 'Yes' : 'No' ?></td>
        <td><a class="btn btn-small" href="user_accounts.php?edit=<?= (int)$u['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
