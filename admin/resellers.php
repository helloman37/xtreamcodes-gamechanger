<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();
$pdo = db();

/* Create / Update reseller */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'create_reseller') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $credits = (int)($_POST['credits'] ?? 0);
    if ($u && $p) {
      $hash = password_hash($p, PASSWORD_BCRYPT);
      $stmt = $pdo->prepare("INSERT INTO resellers (username, password_hash, credits) VALUES (?,?,?)");
      try {
        $stmt->execute([$u, $hash, $credits]);
        flash_set("Reseller created", "success");
      } catch (Exception $e) {
        flash_set("Create failed: ".$e->getMessage(), "error");
      }
    } else {
      flash_set("Username and password required", "error");
    }
    header("Location: resellers.php"); exit;
  }

  if ($action === 'update_reseller') {
    $id = (int)($_POST['id'] ?? 0);
    $credits = (int)($_POST['credits'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
    $pdo->prepare("UPDATE resellers SET credits=?, status=? WHERE id=?")
        ->execute([$credits, $status, $id]);

    $newpass = $_POST['password'] ?? '';
    if ($newpass !== '') {
      $hash = password_hash($newpass, PASSWORD_BCRYPT);
      $pdo->prepare("UPDATE resellers SET password_hash=? WHERE id=?")->execute([$hash, $id]);
    }
    flash_set("Reseller updated", "success");
    header("Location: resellers.php"); exit;
  }

  if ($action === 'delete_reseller') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM resellers WHERE id=?")->execute([$id]);
    $pdo->prepare("UPDATE users SET reseller_id=NULL WHERE reseller_id=?")->execute([$id]);
    flash_set("Reseller deleted", "success");
    header("Location: resellers.php"); exit;
  }
}

// Include quick stats so admins can see reseller activity at a glance.
$resellers = $pdo->query(
  "SELECT r.*,
          (SELECT COUNT(*) FROM users u WHERE u.reseller_id=r.id) AS users_total,
          (
            SELECT COUNT(DISTINCT u2.id)
            FROM users u2
            JOIN subscriptions s ON s.user_id=u2.id
            WHERE u2.reseller_id=r.id
              AND s.status='active'
              AND (s.ends_at IS NULL OR s.ends_at>NOW())
          ) AS users_active
   FROM resellers r
   ORDER BY r.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
?><!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Resellers</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>
<h1>Resellers</h1>
<?php flash_show(); ?>

<div class="card" style="margin:12px 0;">
  <h2>Create Reseller</h2>
  <form method="post">
    <input type="hidden" name="action" value="create_reseller">
    <div class="grid2">
      <div>
        <label>Username</label>
        <input name="username" required>
      </div>
      <div>
        <label>Temp Password</label>
        <input name="password" required>
      </div>
    </div>
    <label>Credits</label>
    <input name="credits" type="number" min="0" value="0">
    <button type="submit" style="margin-top:8px;">Create</button>
  </form>
</div>

<table class="table">
  <thead>
    <tr>
      <th>ID</th><th>Username</th><th>Credits</th><th>Status</th><th>Users</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($resellers as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= e($r['username']) ?></td>
      <td><?= (int)$r['credits'] ?></td>
      <td><?= e($r['status']) ?></td>
      <td>
        <a href="reseller_details.php?id=<?= (int)$r['id'] ?>" title="View reseller users">
          <?= (int)($r['users_total'] ?? 0) ?> total / <?= (int)($r['users_active'] ?? 0) ?> active
        </a>
      </td>
      <td>
        <details>
          <summary>Edit / Details</summary>
          <form method="post" style="margin-top:6px;">
            <input type="hidden" name="action" value="update_reseller">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <label>Credits</label>
            <input name="credits" type="number" min="0" value="<?= (int)$r['credits'] ?>">
            <label>Status</label>
            <select name="status">
              <option value="active" <?= $r['status']=='active'?'selected':'' ?>>active</option>
              <option value="suspended" <?= $r['status']=='suspended'?'selected':'' ?>>suspended</option>
            </select>
            <label>New Password (optional)</label>
            <input name="password" placeholder="leave blank to keep">
            <button type="submit" style="margin-top:6px;">Save</button>
          </form>
          <form method="post" onsubmit="return confirm('Delete reseller? This will not delete their users.');" style="margin-top:6px;">
            <input type="hidden" name="action" value="delete_reseller">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button type="submit" class="danger">Delete</button>
          </form>

          <hr>
          <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:8px;">
            <div>
              <strong>Users:</strong>
              <?= (int)($r['users_total'] ?? 0) ?> total,
              <?= (int)($r['users_active'] ?? 0) ?> active
            </div>
            <a class="btn btn-small" href="reseller_details.php?id=<?= (int)$r['id'] ?>">View Users</a>
          </div>
        </details>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

</div></body></html>
