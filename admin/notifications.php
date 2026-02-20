<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../admin_notifications_lib.php';

require_admin();

$pdo = db();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'mark_all_read') {
    admin_notifications_mark_all_read($pdo, $adminId);
    flash_set('All notifications marked read.', 'success');
    header('Location: notifications.php');
    exit;
  }
  if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      admin_notifications_mark_read($pdo, $adminId, $id);
    }
    header('Location: notifications.php');
    exit;
  }
}

$list = admin_notifications_list($pdo, $adminId, 100);

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin Notifications</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card xt-notifs-card" style="max-width: 980px; margin: 18px auto;">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
    <div>
      <h2 style="margin:0;">Notifications</h2>
      <div class="muted" style="margin-top:4px;">Unread only. Once marked read, they disappear.</div>
    </div>
    <form method="post" style="margin:0;">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="mark_all_read">
      <button class="btn" type="submit" <?= empty($list) ? 'disabled' : '' ?>>Mark all read</button>
    </form>
  </div>

  <?php flash_show(); ?>

  <div style="margin-top: 14px; display:grid; gap: 10px;">
    <?php if (empty($list)): ?>
      <div class="notice">No unread notifications.</div>
    <?php else: ?>
      <?php foreach ($list as $n): ?>
        <div class="notice xt-notif-item" style="border-left-width: 4px;">
          <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
            <div>
              <div style="font-weight:800;">
                <?= e((string)$n['title']) ?>
                <span class="muted" style="font-weight:600; font-size:12px; margin-left:8px;">
                  <?= e(date('M j, Y g:ia', strtotime((string)$n['created_at']))) ?>
                </span>
              </div>
              <?php if (!empty($n['message'])): ?>
                <div class="muted" style="margin-top:6px; line-height:1.4;">
                  <?= nl2br(e((string)$n['message'])) ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($n['link'])): ?>
                <div style="margin-top:8px;">
                  <a class="btn btn-small" href="<?= e((string)$n['link']) ?>" style="text-decoration:none;">Open</a>
                </div>
              <?php endif; ?>
            </div>

            <form method="post" style="margin:0;">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="mark_read">
              <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
              <button class="btn gray btn-small" type="submit">Mark read</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

</main>
</div>
</body>
</html>
