<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../plugins_core.php';
require_admin();

$pdo = db();
gc_plugins_db_init($pdo);

$pid = preg_replace('~[^a-zA-Z0-9_-]~', '', (string)($_GET['plugin'] ?? ''));
if ($pid === '') {
  http_response_code(400);
  echo 'Missing plugin.';
  exit;
}

$plugin_dir = gc_plugins_dir() . DIRECTORY_SEPARATOR . $pid;
if (!is_dir($plugin_dir)) {
  http_response_code(404);
  echo 'Plugin not found.';
  exit;
}

// Load manifest (prefer filesystem, it is source of truth for paths)
try {
  $manifest = gc_plugin_manifest_load($pid);
} catch (Throwable $t) {
  http_response_code(500);
  echo 'Invalid plugin manifest.';
  exit;
}

$adminPath = (string)($manifest['admin']['path'] ?? '');
if ($adminPath === '') {
  http_response_code(404);
  echo 'This plugin has no admin UI.';
  exit;
}

$adminPath = str_replace('\\', '/', $adminPath);
// Force plugin admin files to live under an "admin/" subfolder inside the plugin.
if (!gc_str_starts_with($adminPath, 'admin/')) {
  http_response_code(400);
  echo 'Invalid admin path.';
  exit;
}

$target = realpath($plugin_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $adminPath));
$realBase = realpath($plugin_dir);
if (!$target || !$realBase || strpos($target, $realBase) !== 0 || !is_file($target)) {
  http_response_code(404);
  echo 'Plugin admin page not found.';
  exit;
}

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

// Variables exposed to plugin admin page
$GC_PLUGIN_ID = $pid;
$GC_PLUGIN_MANIFEST = $manifest;
$GC_PLUGIN_DIR = $plugin_dir;
$GC_PDO = $pdo;


// Render plugin admin page first (buffered) so it can safely redirect / modify headers.
ob_start();
require $target;
$GC_PLUGIN_ADMIN_HTML = ob_get_clean();
?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title><?= e(($manifest['name'] ?? $pid) . ' Settings') ?></title>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>


<div class="card">
  <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <h2 style="margin:0;"><?= e($manifest['name'] ?? $pid) ?> <span class="muted" style="font-size:14px;">Plugin</span></h2>
    <a class="btn btn-small gray" href="plugins.php">← Back to Plugins</a>
  </div>
  <?php flash_show(); ?>
  <div style="margin-top:12px;">
    <?= $GC_PLUGIN_ADMIN_HTML ?>
  </div>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
