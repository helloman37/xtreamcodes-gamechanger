<?php
// admin/epg_import.php
// Browser/admin wrapper for scripts/epg_import.php with full admin UI.

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$flush = isset($_GET['flush']) ? (int)$_GET['flush'] : 1;
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

// Build argv for the underlying importer
$argv = ['epg_import.php', '--flush=' . $flush];
if ($source_id > 0) $argv[] = '--source_id=' . $source_id;
$argc = count($argv);

// Run importer and capture its output
ob_start();
require __DIR__ . '/../scripts/epg_import.php';
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
  <title>EPG Import</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>EPG Import</h2>
  <p class="muted" style="margin-top:6px;">
    Flush: <b><?= e((string)$flush) ?></b>
    <?php if ($source_id > 0): ?> | Source ID: <b><?= e((string)$source_id) ?></b><?php endif; ?>
  </p>

  <div class="row" style="margin-top:12px;">
    <a class="btn" href="epg_manager.php">Back to EPG Manager</a>
    <a class="btn" href="epg_import.php?flush=1<?= $source_id>0 ? '&source_id='.urlencode((string)$source_id) : '' ?>" onclick="return confirm('Replace currently imported EPG with the latest source data?')">Run Import (replace old)</a>
    <a class="btn" href="epg_import.php?flush=0<?= $source_id>0 ? '&source_id='.urlencode((string)$source_id) : '' ?>" style="background:#2b3545;">Append (advanced)</a>
  </div>

  <h3 style="margin-top:16px;">Output</h3>
  <pre style="white-space:pre-wrap; background:#0b0f14; color:#e7edf7; padding:12px; border-radius:12px; border:1px solid #243041; overflow:auto; max-height:70vh;"><?= e($out) ?></pre>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
