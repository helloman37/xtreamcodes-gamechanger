<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

$user_id = (int)($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
  flash_set('Invalid user', 'error');
  header('Location: user_accounts.php');
  exit;
}

$st = $pdo->prepare('SELECT id, username, name FROM users WHERE id=? LIMIT 1');
$st->execute([$user_id]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
  flash_set('User not found', 'error');
  header('Location: user_accounts.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  $notes = trim((string)($_POST['notes'] ?? ''));
  $tags  = trim((string)($_POST['tags'] ?? ''));

  $pdo->prepare('
    INSERT INTO user_notes (user_id, notes, tags)
    VALUES (?,?,?)
    ON DUPLICATE KEY UPDATE notes=VALUES(notes), tags=VALUES(tags)
  ')->execute([$user_id, $notes, $tags]);

  audit_log('admin_user_notes_update', $user_id, ['tags' => $tags !== '' ? $tags : null]);
  flash_set('Notes updated', 'success');
  header('Location: user_notes.php?user_id='.$user_id);
  exit;
}

$st = $pdo->prepare('SELECT notes, tags FROM user_notes WHERE user_id=? LIMIT 1');
$st->execute([$user_id]);
$note = $st->fetch(PDO::FETCH_ASSOC) ?: ['notes' => '', 'tags' => ''];

$topbar = file_get_contents(__DIR__ . '/topbar.html');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>User Notes</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<h1>User Notes</h1>
<?php flash_show(); ?>

<div class="card">
  <div class="muted" style="margin-bottom:8px;">
    For user <strong><?= e($user['username']) ?></strong>
    <?php if (!empty($user['name'])): ?>
      (<?= e($user['name']) ?>)
    <?php endif; ?>
    — ID #<?= (int)$user['id'] ?>
  </div>

  <form method="post">
    <?= csrf_input(); ?>

    <div style="margin-bottom:10px;">
      <label>Tags (comma separated)</label>
      <input type="text" name="tags" value="<?= e($note['tags'] ?? '') ?>" style="width:100%;" placeholder="cousin, VIP, slow payer">
    </div>

    <div style="margin-bottom:10px;">
      <label>Internal notes</label>
      <textarea name="notes" rows="6" style="width:100%;"><?= e($note['notes'] ?? '') ?></textarea>
    </div>

    <button type="submit">Save</button>
    <a href="user_accounts.php?edit=<?= (int)$user['id'] ?>" class="btn" style="margin-left:8px;">Back to user</a>
  </form>
</div>

</body>
</html>
