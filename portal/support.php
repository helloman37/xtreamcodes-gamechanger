<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../supportdesk_lib.php';
require_once __DIR__ . '/../admin_notifications_lib.php';

supportdesk_db_init($pdo);
supportdesk_require_portal_enabled($pdo);

$reqPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$reqPath = rtrim($reqPath, '/');

$mode = 'list';
$viewTicketId = 0;

if ($reqPath === '/portal/support') {
  $mode = 'list';
} elseif ($reqPath === '/portal/support/new') {
  $mode = 'new';
} elseif (preg_match('~^/portal/support/([0-9]+)$~', $reqPath, $m)) {
  $mode = 'view';
  $viewTicketId = (int)$m[1];
} else {
  // fallback: list
  $mode = 'list';
}

$PORTAL_PAGE = 'support';

if ($mode === 'new') {

  $errors = [];
  $subject = trim((string)($_POST['subject'] ?? ''));
  $message = trim((string)($_POST['message'] ?? ''));
  $prioritySel = strtolower(trim((string)($_POST['priority'] ?? '')));

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $subjectClean = supportdesk_clean_subject($subject);
    $messageClean = supportdesk_clean_message($message);

    if ($subjectClean === '') $errors[] = 'Subject is required.';
    if ($messageClean === '') $errors[] = 'Message is required.';

    if (!$errors) {
      $now = supportdesk_now();
      $priority = (string) supportdesk_setting($pdo, 'default_priority', 'normal');
      $priority = strtolower(trim($priority));
      if (!in_array($priority, ['low','normal','high'], true)) $priority = 'normal';

      // Allow user to pick a priority, but keep it constrained.
      if (in_array($prioritySel, ['low','normal','high'], true)) {
        $priority = $prioritySel;
      }

      $pdo->beginTransaction();
      try {
        $st = $pdo->prepare("INSERT INTO support_tickets (user_id, subject, status, priority, created_at, updated_at, last_message_at, last_author)
                             VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([(int)$user['id'], $subjectClean, 'open', $priority, $now, $now, $now, 'user']);
        $ticketId = (int)$pdo->lastInsertId();

        $st2 = $pdo->prepare("INSERT INTO support_messages (ticket_id, author_type, author_ref, message, created_at)
                              VALUES (?,?,?,?,?)");
        $st2->execute([$ticketId, 'user', (string)$user['id'], $messageClean, $now]);

        $pdo->commit();

        
        // Notify admins of new ticket (in-app admin bell)
        try {
          $username = (string)($user['username'] ?? ('User #' . (int)$user['id']));
          $title = 'New support ticket: ' . $subjectClean;
          $msg = $username . ' opened ticket #' . $ticketId . '.';
          $link = '/admin/support_desk.php?ticket=' . $ticketId;
          admin_notifications_broadcast($pdo, 'support', $title, $msg, $link, 'support_new:' . $ticketId);
        } catch (Throwable $t) {}

        header("Location: /portal/support/" . $ticketId);
        exit;
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Could not create ticket.';
      }
    }
  }

  // Render after POST handling so redirects can send headers safely.
  require_once __DIR__ . '/_layout_top.php';
  ?>
  <div class="card pad">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Support Desk</h2>
        <div class="muted sd-sub">Create a new ticket.</div>
      </div>
      <div class="sd-tabs">
        <a class="btn sm ghost" href="/portal/support/">Inbox</a>
        <a class="btn sm primary" href="/portal/support/new">New Ticket</a>
      </div>
    </div>

    <?php if ($errors): ?>
      <div class="alert bad" style="margin-top:12px;">
        <?php foreach ($errors as $e): ?>
          <div><?= e($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="sd-form" style="margin-top:12px;">
      <?= csrf_input() ?>

      <div class="sd-form-grid">
        <div class="sd-field">
          <label>Subject</label>
          <input class="input sd-input" name="subject" value="<?= e($subject) ?>" required>
        </div>
        <div class="sd-field">
          <label>Priority</label>
          <select class="input sd-input" name="priority">
            <?php
              $default_priority = (string)supportdesk_setting($pdo, 'default_priority', 'normal');
              $default_priority = strtolower(trim($default_priority));
              if (!in_array($default_priority, ['low','normal','high'], true)) $default_priority = 'normal';
              $priorityUi = in_array($prioritySel, ['low','normal','high'], true) ? $prioritySel : $default_priority;
            ?>
            <option value="low" <?= $priorityUi==='low'?'selected':'' ?>>Low</option>
            <option value="normal" <?= $priorityUi==='normal'?'selected':'' ?>>Normal</option>
            <option value="high" <?= $priorityUi==='high'?'selected':'' ?>>High</option>
          </select>
        </div>
      </div>

      <div class="sd-field" style="margin-top:12px;">
        <label>Message</label>
        <textarea class="input sd-input" name="message" rows="9" required><?= e($message) ?></textarea>
      </div>

      <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn primary" type="submit">Create Ticket</button>
        <a class="btn" href="/portal/support/">Cancel</a>
      </div>
    </form>
  </div>
  <?php

} elseif ($mode === 'view') {

  $st = $pdo->prepare("SELECT * FROM support_tickets WHERE id=? AND user_id=? LIMIT 1");
  $st->execute([$viewTicketId, (int)$user['id']]);
  $ticket = $st->fetch(PDO::FETCH_ASSOC);

  // Handle reply POST before rendering/layout.
  $errors = [];
  $reply = trim((string)($_POST['message'] ?? ''));

  if ($ticket && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($ticket['status'] ?? '') !== 'closed') {
    csrf_validate();

    $replyClean = supportdesk_clean_message($reply);
    if ($replyClean === '') $errors[] = 'Message is required.';

    if (!$errors) {
      $now = supportdesk_now();
      $pdo->beginTransaction();
      try {
        $st2 = $pdo->prepare("INSERT INTO support_messages (ticket_id, author_type, author_ref, message, created_at)
                              VALUES (?,?,?,?,?)");
        $st2->execute([(int)$ticket['id'], 'user', (string)$user['id'], $replyClean, $now]);

        // User reply re-opens ticket unless it is closed
        $st3 = $pdo->prepare("UPDATE support_tickets
                              SET status='open', updated_at=?, last_message_at=?, last_author='user'
                              WHERE id=? AND user_id=?");
        $st3->execute([$now, $now, (int)$ticket['id'], (int)$user['id']]);

        $pdo->commit();

        
        // Notify admins of user reply (in-app admin bell)
        try {
          $msgId = (int)$pdo->lastInsertId();
          $username = (string)($user['username'] ?? ('User #' . (int)$user['id']));
          $subj = trim((string)($ticket['subject'] ?? ''));
          if ($subj === '') $subj = 'Ticket #' . (int)$ticket['id'];
          $title = 'Ticket reply: ' . $subj;
          $msg = $username . ' replied on ticket #' . (int)$ticket['id'] . '.';
          $link = '/admin/support_desk.php?ticket=' . (int)$ticket['id'];
          admin_notifications_broadcast($pdo, 'support', $title, $msg, $link, 'support_reply:' . $msgId);
        } catch (Throwable $t) {}

        header('Location: /portal/support/' . (int)$ticket['id'] . '/');
        exit;
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Could not send message.';
      }
    }
  }

  // Render after POST handling so redirects can send headers safely.
  require_once __DIR__ . '/_layout_top.php';

  if (!$ticket) {
    echo "<div class='card'><h2>Not found</h2><p class='muted'>That ticket does not exist.</p><a class='btn ghost' href='/portal/support/'>Back</a></div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
  }

  $stM = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY id ASC");
  $stM->execute([(int)$ticket['id']]);
  $messages = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];

  ?>
  <div class="card pad">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Support Desk</h2>
        <div class="muted sd-sub">Tickets and replies inside the panel.</div>
      </div>
      <div class="sd-tabs">
        <a class="btn sm primary" href="/portal/support/">Inbox</a>
        <a class="btn sm ghost" href="/portal/support/new">New Ticket</a>
      </div>
    </div>

    <div style="margin-top:14px;">
      <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap;">
        <div>
          <h3 style="margin:0;">Ticket #<?= (int)$ticket['id'] ?> — <?= e((string)($ticket['subject'] ?? '')) ?></h3>
          <div class="muted" style="margin-top:6px;">
            User: <span class="code"><?= e((string)($user['username'] ?? $user['id'])) ?></span> |
            Priority: <span class="code"><?= e((string)($ticket['priority'] ?? 'normal')) ?></span> |
            Status: <?= supportdesk_portal_status_badge((string)($ticket['status'] ?? 'open')) ?>
          </div>
        </div>
        <a class="btn sm ghost" href="/portal/support/">← Back</a>
      </div>

      <div class="card sd-pane" style="margin-top:12px;">
        <h3 style="margin-top:0;">Conversation</h3>

        <?php foreach ($messages as $m): ?>
          <?php
            $isAdmin = ((string)($m['author_type'] ?? '') === 'admin');
            $who = $isAdmin ? 'Support' : 'You';
            $ts = (string)($m['created_at'] ?? '');
          ?>
          <div class="sd-msg">
            <div class="meta">
              <b class="who"><?= e($who) ?></b>
              <span class="ts"><?= e($ts) ?></span>
            </div>
            <div class="body"><?= supportdesk_render_message((string)($m['message'] ?? '')) ?></div>
          </div>
        <?php endforeach; ?>

        <?php if ($errors): ?>
          <div class="alert bad" style="margin-top:12px;">
            <?php foreach ($errors as $e): ?>
              <div><?= e($e) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <h3>Reply</h3>

        <?php if ((string)($ticket['status'] ?? '') === 'closed'): ?>
          <div class="alert" style="margin-top:12px;">This ticket is closed.</div>
        <?php else: ?>
          <form method="post" class="sd-form" style="margin-top:10px;">
            <?= csrf_input() ?>
            <label>Your message</label>
            <textarea class="input sd-input" name="message" rows="8" required><?= e($reply) ?></textarea>
            <div style="margin-top:10px;">
              <button class="btn primary" type="submit">Send Reply</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php

} else {

  // Render list after routing decision.
  require_once __DIR__ . '/_layout_top.php';

  $st = $pdo->prepare("SELECT id, subject, status, priority, created_at, updated_at, last_message_at
                       FROM support_tickets
                       WHERE user_id=?
                       ORDER BY last_message_at DESC, id DESC
                       LIMIT 200");
  $st->execute([(int)$user['id']]);
  $tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  ?>
  <div class="card pad">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Support Desk</h2>
        <div class="muted sd-sub">Tickets and replies inside the panel.</div>
      </div>
      <div class="sd-tabs">
        <a class="btn sm primary" href="/portal/support/">Inbox</a>
        <a class="btn sm ghost" href="/portal/support/new">New Ticket</a>
      </div>
    </div>

    <div style="overflow:auto; margin-top:14px;">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Priority</th>
            <th>Last Update</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tickets): ?>
            <tr class="empty"><td colspan="6" class="muted">No tickets yet.</td></tr>
          <?php endif; ?>

          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><span class="code">#<?= (int)$t['id'] ?></span></td>
              <td><?= e((string)($t['subject'] ?? '')) ?></td>
              <td><?= supportdesk_portal_status_badge((string)($t['status'] ?? 'open')) ?></td>
              <td><span class="code"><?= e((string)($t['priority'] ?? 'normal')) ?></span></td>
              <td class="muted"><?= e((string)(($t['last_message_at'] ?: $t['updated_at']) ?? '')) ?></td>
              <td style="text-align:right;">
                <a class="btn sm ghost" href="/portal/support/<?= (int)$t['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="muted" style="margin-top:12px;">
      Portal support replies will show up here.
    </div>
  </div>
<?php
}

require_once __DIR__ . '/_layout_bottom.php';
