<?php
// admin/stream_probe.php
// Browser/admin wrapper for scripts/stream_probe.php with full admin UI.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit < 1) $limit = 200;
if ($limit > 2000) $limit = 2000;

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

// Build argv for the underlying probe script
$argv = ['stream_probe.php', '--limit=' . $limit];
$argc = count($argv);

ob_start();
require __DIR__ . '/../scripts/stream_probe.php';
$out = ob_get_clean();
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Stream Probe</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Stream Probe</h2>
  <p class="muted" style="margin-top:6px;">
    Checks channels and updates health status. Limit caps how many channels are probed this run.
  </p>

  <form method="get" class="row" style="margin-top:12px; align-items:flex-end;">
    <div>
      <label>Limit</label>
      <input type="number" name="limit" value="<?= e((string)$limit) ?>" min="1" max="2000">
    </div>
    <div>
      <button class="btn" type="submit">Run Probe</button>
      <a class="btn" href="health.php" style="margin-left:8px;">View Health</a>
    </div>
  </form>

  <h3 style="margin-top:16px;">Output</h3>
  <pre style="white-space:pre-wrap; background:#0b0f14; color:#e7edf7; padding:12px; border-radius:12px; border:1px solid #243041; overflow:auto; max-height:70vh;"><?= e($out) ?></pre>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
