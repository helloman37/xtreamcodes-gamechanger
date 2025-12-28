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
  $video_url = trim((string)($_POST['video_url'] ?? ''));

  system_setting_set($pdo, 'maintenance_mode', $enabled);
  system_setting_set($pdo, 'maintenance_message', $message !== '' ? $message : null);
  system_setting_set($pdo, 'maintenance_video_url', $video_url !== '' ? $video_url : null);

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

$video_url = (string)system_setting_get($pdo, 'maintenance_video_url', '');

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Maintenance Mode</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Maintenance Mode</h2>
  <?php flash_show(); ?>

  <form method="post">
    <?= csrf_input(); ?>

    <div style="margin-bottom:12px;">
      <label>
        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
        Enable global maintenance mode
      </label>
      <div class="muted">When enabled, stream requests will be redirected to the maintenance video (if set). If no video is set, clients will see the message below.</div>
    </div>

    <div style="margin-bottom:12px;">
      <label>Maintenance video URL (optional)</label>
      <input type="text" name="video_url" value="<?= e($video_url) ?>" placeholder="https://.../maintenance.m3u8 (or .ts / .mp4)" style="width:100%;">
      <div class="muted" style="margin-top:6px;">When maintenance mode is enabled, /live + /movie + /series stream requests will redirect to this URL.</div>
      <?php if ($video_url !== ''): ?>
        <div style="margin-top:8px;"><a class="btn" target="_blank" rel="noopener" href="<?= e($video_url) ?>">Open</a></div>
      <?php endif; ?>
    </div>

    <div style="margin-bottom:12px;">
      <label>Maintenance message shown to clients</label>
      <textarea name="message" rows="4" style="width:100%;"><?= e($message) ?></textarea>
    </div>

    <button type="submit">Save</button>
  </form>
</div>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
