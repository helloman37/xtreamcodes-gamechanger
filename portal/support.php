<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../supportdesk_lib.php';

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
require_once __DIR__ . '/_layout_top.php';

if ($mode === 'new') {

  $errors = [];
  $subject = trim((string)($_POST['subject'] ?? ''));
  $message = trim((string)($_POST['message'] ?? ''));

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

        header("Location: /portal/support/" . $ticketId);
        exit;
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Could not create ticket.';
      }
    }
  }
  ?>
  <div class="card pad">
    <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap;">
      <h2 style="margin:0;">New Ticket</h2>
      <a class="btn ghost" href="/portal/support/">Back</a>
    </div>

    <?php if ($errors): ?>
      <div class="alert bad" style="margin-top:12px;">
        <?php foreach ($errors as $e): ?>
          <div><?= e($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" style="margin-top:12px;">
      <?= csrf_input() ?>
      <label>Subject</label>
      <input class="input" name="subject" value="<?= e($subject) ?>" required>

      <label style="margin-top:10px;">Message</label>
      <textarea class="input" name="message" rows="6" required><?= e($message) ?></textarea>

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

  if (!$ticket) {
    echo "<div class='card'><h2>Not found</h2><p class='muted'>That ticket does not exist.</p><a class='btn ghost' href='/portal/support/'>Back</a></div>";
    require_once __DIR__ . '/_layout_bottom.php';
    exit;
  }

  $errors = [];
  $reply = trim((string)($_POST['message'] ?? ''));

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($ticket['status'] ?? '') !== 'closed') {
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

        header("Location: /portal/support/" . (int)$ticket['id']);
        exit;
      } catch (Throwable $t) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Could not send message.';
      }
    }
  }

  $stM = $pdo->prepare("SELECT * FROM support_messages WHERE ticket_id=? ORDER BY id ASC");
  $stM->execute([(int)$ticket['id']]);
  $messages = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];

  ?>
  <div class="card pad">
    <div style="display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;">Ticket #<?= (int)$ticket['id'] ?></h2>
        <div class="muted" style="margin-top:6px;">
          <?= supportdesk_portal_status_badge((string)($ticket['status'] ?? 'open')) ?>
          <span class="muted">•</span>
          <span class="muted"><?= e((string)($ticket['subject'] ?? '')) ?></span>
        </div>
      </div>
      <a class="btn ghost" href="/portal/support/">Back</a>
    </div>

    <div style="margin-top:14px;">
      <?php foreach ($messages as $m): ?>
        <?php
          $isAdmin = ((string)($m['author_type'] ?? '') === 'admin');
          $who = $isAdmin ? 'Support' : 'You';
          $ts = (string)($m['created_at'] ?? '');
        ?>
        <div style="padding:12px; border:1px solid rgba(255,255,255,.08); border-radius:14px; margin-bottom:10px; background: rgba(0,0,0,.18);">
          <div style="display:flex; justify-content:space-between; gap:10px; align-items:center;">
            <b><?= e($who) ?></b>
            <span class="muted" style="font-size:12px;"><?= e($ts) ?></span>
          </div>
          <div style="margin-top:8px; line-height:1.55;"><?= supportdesk_render_message((string)($m['message'] ?? '')) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($errors): ?>
      <div class="alert bad" style="margin-top:12px;">
        <?php foreach ($errors as $e): ?>
          <div><?= e($e) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ((string)($ticket['status'] ?? '') === 'closed'): ?>
      <div class="alert" style="margin-top:12px;">This ticket is closed.</div>
    <?php else: ?>
      <form method="post" style="margin-top:14px;">
        <?= csrf_input() ?>
        <label>Your message</label>
        <textarea class="input" name="message" rows="5" required><?= e($reply) ?></textarea>
        <div style="margin-top:10px;">
          <button class="btn primary" type="submit">Send</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
  <?php

} else {

  $st = $pdo->prepare("SELECT id, subject, status, priority, created_at, updated_at, last_message_at
                       FROM support_tickets
                       WHERE user_id=?
                       ORDER BY last_message_at DESC, id DESC
                       LIMIT 200");
  $st->execute([(int)$user['id']]);
  $tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  ?>
  <div class="card pad">
    <div class="support-header">
      <h2 style="margin:0;">Support</h2>
      <a class="btn primary" href="/portal/support/new">New Ticket</a>
    </div>

    <div class="tablewrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Subject</th>
            <th>Status</th>
            <th>Last Update</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$tickets): ?>
            <tr class="empty"><td colspan="5" class="muted">No tickets yet.</td></tr>
          <?php endif; ?>

          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><span class="code">#<?= (int)$t['id'] ?></span></td>
              <td><?= e((string)$t['subject']) ?></td>
              <td><?= supportdesk_portal_status_badge((string)$t['status']) ?></td>
              <td class="muted"><?= e((string)($t['last_message_at'] ?: $t['updated_at'])) ?></td>
              <td style="text-align:right;">
                <a class="btn ghost" href="/portal/support/<?= (int)$t['id'] ?>">Open</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="muted support-footer-note">
      Support replies will show up here.
    </div>
  </div>
  <?php
}

require_once __DIR__ . '/_layout_bottom.php';
