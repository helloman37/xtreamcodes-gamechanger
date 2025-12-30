<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../supportdesk_lib.php';
require_once __DIR__ . '/../notifications_lib.php';

require_admin();

$pdo = db();
supportdesk_db_init($pdo);

$tab = (string)($_GET['tab'] ?? 'inbox');
if ($tab !== 'settings') $tab = 'inbox';

$ticketId = (int)($_GET['ticket'] ?? 0);

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_settings') {
  csrf_validate();
  $portal_enabled = !empty($_POST['portal_enabled']) ? 1 : 0;
  $default_priority = strtolower(trim((string)($_POST['default_priority'] ?? 'normal')));
  if (!in_array($default_priority, ['low','normal','high'], true)) $default_priority = 'normal';

  supportdesk_setting_set($pdo, 'portal_enabled', $portal_enabled);
  supportdesk_setting_set($pdo, 'default_priority', $default_priority);

  flash_set('Support Desk settings saved.', 'ok');
  header('Location: support_desk.php?tab=settings');
  exit;
}

// Ticket reply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'ticket_reply') {
  csrf_validate();
  $ticketIdPost = (int)($_POST['ticket_id'] ?? 0);
  $msg = supportdesk_clean_message((string)($_POST['message'] ?? ''));
  $newStatus = strtolower(trim((string)($_POST['status'] ?? 'answered')));
  if (!in_array($newStatus, ['open','pending','answered','closed'], true)) $newStatus = 'answered';

  if ($ticketIdPost < 1 || $msg === '') {
    flash_set('Reply message required.', 'error');
    header('Location: support_desk.php?ticket=' . urlencode((string)$ticketIdPost));
    exit;
  }

  $now = supportdesk_now();
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("INSERT INTO support_messages (ticket_id, author_type, author_ref, message, created_at) VALUES (?,?,?,?,?)");
    $st->execute([$ticketIdPost, 'admin', (string)($_SESSION['admin_username'] ?? 'admin'), $msg, $now]);
$msgId = (int)$pdo->lastInsertId();

// Notify user that support replied
$stU = $pdo->prepare("SELECT user_id, subject FROM support_tickets WHERE id=? LIMIT 1");
$stU->execute([$ticketIdPost]);
$trow = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
$toUserId = (int)($trow['user_id'] ?? 0);
$subject = trim((string)($trow['subject'] ?? ''));
if ($subject === '') $subject = 'Ticket #' . (string)$ticketIdPost;

$snippet = $msg;
if (strlen($snippet) > 180) $snippet = substr($snippet, 0, 180) . '…';
notifications_add($pdo, $toUserId, 'support', 'Support replied: ' . $subject, $snippet, '/portal/support/' . (string)$ticketIdPost, 'support_reply:' . (string)$msgId);



    $st2 = $pdo->prepare("UPDATE support_tickets SET status=?, updated_at=?, last_message_at=?, last_author='admin' WHERE id=?");
    $st2->execute([$newStatus, $now, $now, $ticketIdPost]);

    $pdo->commit();
    flash_set('Reply sent.', 'ok');
  } catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('Could not send reply.', 'error');
  }

  header('Location: support_desk.php?ticket=' . urlencode((string)$ticketIdPost));
  exit;
}

$portal_enabled = (int)supportdesk_setting($pdo, 'portal_enabled', 1);
$default_priority = (string)supportdesk_setting($pdo, 'default_priority', 'normal');
if (!in_array($default_priority, ['low','normal','high'], true)) $default_priority = 'normal';

// Ticket view
$ticket = null;
$messages = [];
if ($ticketId > 0) {
  $st = $pdo->prepare("SELECT t.*, u.username, u.name FROM support_tickets t LEFT JOIN users u ON u.id=t.user_id WHERE t.id=? LIMIT 1");
  $st->execute([$ticketId]);
  $ticket = $st->fetch(PDO::FETCH_ASSOC) ?: null;

  if ($ticket) {
    $stM = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY id ASC");
    $stM->execute([$ticketId]);
    $messages = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

// Inbox list
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'open')));
if (!in_array($statusFilter, ['open','pending','answered','closed','all'], true)) $statusFilter = 'open';

$sql = "SELECT t.id,t.subject,t.status,t.priority,t.last_message_at,t.updated_at,t.user_id, u.username
        FROM support_tickets t
        LEFT JOIN users u ON u.id=t.user_id";
$params = [];
if ($statusFilter !== 'all') {
  $sql .= " WHERE t.status=?";
  $params[] = $statusFilter;
}
$sql .= " ORDER BY t.last_message_at DESC, t.id DESC LIMIT 200";
$stL = $pdo->prepare($sql);
$stL->execute($params);
$tickets = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

function sd_active_btn(string $want, string $current): string {
  return $want === $current ? '' : 'gray';
}

?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <meta charset="utf-8">
  <title>Support Desk</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
    <div>
      <h2 style="margin:0;">Support Desk</h2>
      <div class="muted" style="margin-top:6px;">Tickets and replies inside the panel.</div>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="btn btn-small <?= sd_active_btn('inbox',$tab) ?>" href="support_desk.php?tab=inbox">Inbox</a>
      <a class="btn btn-small <?= sd_active_btn('settings',$tab) ?>" href="support_desk.php?tab=settings">Settings</a>
    </div>
  </div>

  <?php flash_show(); ?>

  <?php if ($tab === 'settings'): ?>
    <div style="margin-top:14px;">
      <h3>Settings</h3>
      <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_settings">

        <label style="display:flex; gap:10px; align-items:center; margin:10px 0;">
          <input type="checkbox" name="portal_enabled" value="1" <?= $portal_enabled===1?'checked':'' ?>>
          Enable subscriber portal pages ( /portal/support/ )
        </label>

        <div style="margin-top:10px;">
          <label class="muted">Default Priority</label><br>
          <select name="default_priority">
            <option value="low" <?= $default_priority==='low'?'selected':'' ?>>Low</option>
            <option value="normal" <?= $default_priority==='normal'?'selected':'' ?>>Normal</option>
            <option value="high" <?= $default_priority==='high'?'selected':'' ?>>High</option>
          </select>
        </div>

        <div style="margin-top:12px;">
          <button class="btn" type="submit">Save</button>
        </div>
      </form>

      <div class="muted" style="margin-top:14px;">
        Portal URL: <span class="code">/portal/support/</span>
      </div>
    </div>
  <?php else: ?>

    <?php if ($ticket): ?>
      <div style="margin-top:14px;">
        <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap;">
          <div>
            <h3 style="margin:0;">Ticket #<?= e($ticket['id']) ?> — <?= e($ticket['subject'] ?? '') ?></h3>
            <div class="muted" style="margin-top:6px;">
              User: <span class="code"><?= e($ticket['username'] ?? $ticket['user_id']) ?></span> |
              Priority: <span class="code"><?= e($ticket['priority'] ?? 'normal') ?></span> |
              Status: <?= supportdesk_admin_status_badge((string)($ticket['status'] ?? 'open')) ?>
            </div>
          </div>
          <a class="btn btn-small gray" href="support_desk.php">← Back</a>
        </div>

        <div class="card" style="margin-top:12px;">
          <h3 style="margin-top:0;">Conversation</h3>

          <?php foreach ($messages as $m): ?>
            <?php
              $who = ((string)($m['author_type'] ?? '') === 'admin') ? 'Admin' : 'User';
              $ts = (string)($m['created_at'] ?? '');
            ?>
            <div style="padding:10px; border:1px solid #2a2f3a; border-radius:12px; margin-bottom:10px; background:#0d0f14; color:#e6f1ff;">
              <div style="display:flex; justify-content:space-between; gap:10px; align-items:center;">
                <b style="color:#e6f1ff;"><?= e($who) ?></b>
                <span class="muted" style="font-size:12px;"><?= e($ts) ?></span>
              </div>
              <div style="margin-top:8px; line-height:1.45;"><?= supportdesk_render_message((string)($m['message'] ?? '')) ?></div>
            </div>
          <?php endforeach; ?>

          <h3>Reply</h3>
          <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="ticket_reply">
            <input type="hidden" name="ticket_id" value="<?= e($ticket['id']) ?>">

            <label class="muted">Set Status After Reply</label><br>
            <select name="status">
              <?php foreach (['open','pending','answered','closed'] as $s): ?>
                <option value="<?= e($s) ?>" <?= strtolower((string)($ticket['status'] ?? 'open'))===$s?'selected':'' ?>><?= e(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select>

            <div style="margin-top:10px;">
              <textarea name="message" rows="6" required style="width:100%; padding:10px;"></textarea>
            </div>

            <div style="margin-top:10px;">
              <button class="btn" type="submit">Send Reply</button>
            </div>
          </form>
        </div>
      </div>

    <?php else: ?>
      <div style="margin-top:14px;">
        <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap;">
          <h3 style="margin:0;">Inbox</h3>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <?php foreach (['open','pending','answered','closed','all'] as $s): ?>
              <a class="btn btn-small <?= ($statusFilter===$s)?'':'gray' ?>" href="support_desk.php?status=<?= urlencode($s) ?>"><?= e(ucfirst($s)) ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <div style="overflow:auto; margin-top:10px;">
          <table class="table">
            <thead>
              <tr>
                <th>ID</th>
                <th>User</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Last Update</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$tickets): ?>
                <tr><td colspan="7" class="muted">No tickets.</td></tr>
              <?php endif; ?>
              <?php foreach ($tickets as $t): ?>
                <tr>
                  <td><span class="code"><?= e($t['id']) ?></span></td>
                  <td><?= e($t['username'] ?? $t['user_id']) ?></td>
                  <td><?= e($t['subject']) ?></td>
                  <td><?= supportdesk_admin_status_badge((string)$t['status']) ?></td>
                  <td><span class="code"><?= e($t['priority'] ?? 'normal') ?></span></td>
                  <td class="muted"><?= e($t['last_message_at'] ?: $t['updated_at']) ?></td>
                  <td style="text-align:right;">
                    <a class="btn btn-small gray" href="support_desk.php?ticket=<?= urlencode((string)$t['id']) ?>">Open</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="muted" style="margin-top:10px;">
          Portal page: <span class="code">/portal/support/</span>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
