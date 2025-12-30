<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../notifications_lib.php';

$userId = (int)($user['id'] ?? 0);

// Quick action: open a notification, mark it read, then redirect to its link.
$go = (int)($_GET['go'] ?? 0);
if ($go > 0) {
  try {
    $st = $pdo->prepare("SELECT link FROM notifications WHERE id=? AND user_id=? LIMIT 1");
    $st->execute([$go, $userId]);
    $link = (string)($st->fetchColumn() ?? '');
    notifications_mark_read($pdo, $userId, $go);
    if ($link !== '') {
      header("Location: " . $link);
      exit;
    }
  } catch (Throwable $t) {}
  header("Location: /portal/notifications/");
  exit;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'mark_all') {
    notifications_mark_all_read($pdo, $userId);
    flash_set('All notifications marked read.', 'ok');
    header('Location: /portal/notifications/');
    exit;
  }
  if ($action === 'mark_one') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      notifications_mark_read($pdo, $userId, $id);
      flash_set('Notification marked read.', 'ok');
    }
    header('Location: /portal/notifications/');
    exit;
  }
}

$items = notifications_list($pdo, $userId, 80);

require_once __DIR__ . '/_layout_top.php';
?>

<div class="card pad">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <h2 style="margin:0;">Notifications</h2>
    <form method="post" style="margin:0;">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="mark_all">
      <button class="btn ghost" type="submit">Mark all read</button>
    </form>
  </div>

  <?php if (!$items): ?>
    <div class="muted" style="margin-top:14px;">Nothing yet.</div>
  <?php else: ?>
    <div style="margin-top:14px; display:flex; flex-direction:column; gap:10px;">
      <?php foreach ($items as $n): ?>
        <?php
          $isRead = !empty($n['is_read']);
          $type = (string)($n['type'] ?? 'info');
          $badge = $isRead ? 'badge' : 'badge good';
          $when = (string)($n['created_at'] ?? '');
          $link = (string)($n['link'] ?? '');
        ?>
        <div class="card pad" style="border:1px solid rgba(255,255,255,0.08); <?= $isRead ? 'opacity:0.85;' : '' ?>">
          <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:center;">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
              <span class="<?= $badge ?>"><?= e($type) ?></span>
              <?php if (!$isRead): ?><span class="badge">new</span><?php endif; ?>
            </div>
            <div class="muted" style="font-size:12px;"><?= e($when) ?></div>
          </div>

          <div style="margin-top:8px; font-weight:700;"><?= e((string)$n['title']) ?></div>
          <?php if (!empty($n['message'])): ?>
            <div class="muted" style="margin-top:6px; white-space:pre-wrap;"><?= e((string)$n['message']) ?></div>
          <?php endif; ?>

          <div style="margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <?php if ($link !== ''): ?>
              <a class="btn primary" href="/portal/notifications/?go=<?= (int)$n['id'] ?>">Open</a>
            <?php endif; ?>

            <?php if (!$isRead): ?>
              <form method="post" style="margin:0;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="mark_one">
                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                <button class="btn ghost" type="submit">Mark read</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
