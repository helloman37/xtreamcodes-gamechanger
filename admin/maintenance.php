<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  $enabled = isset($_POST['enabled']) ? '1' : '0';
  $message = trim((string)($_POST['message'] ?? ''));

  system_setting_set($pdo, 'maintenance_mode', $enabled);
  system_setting_set($pdo, 'maintenance_message', $message !== '' ? $message : null);

  audit_log('system_maintenance_update', null, ['enabled' => $enabled === '1']);
  flash_set('Maintenance settings updated', 'success');
  header('Location: maintenance.php');
  exit;
}

$enabled = system_setting_get($pdo, 'maintenance_mode', '0') === '1';
$message = system_setting_get(
  $pdo,
  'maintenance_message',
  'Service is temporarily under maintenance. Please try again later.'
);

$topbar = file_get_contents(__DIR__ . '/topbar.html');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Maintenance Mode</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<h1>Maintenance Mode</h1>
<?php flash_show(); ?>

<div class="card">
  <form method="post">
    <?= csrf_input(); ?>

    <div style="margin-bottom:12px;">
      <label>
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        Enable global maintenance mode
      </label>
      <div class="muted">When enabled, get.php + web player will show this message to normal users.</div>
    </div>

    <div style="margin-bottom:12px;">
      <label>Maintenance message shown to clients</label>
      <textarea name="message" rows="4" style="width:100%;"><?= e($message) ?></textarea>
    </div>

    <button type="submit">Save</button>
  </form>
</div>

</body>
</html>
