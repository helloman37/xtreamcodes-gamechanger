<?php
// admin/watchlist.php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();
require_once __DIR__ . '/../portal/lib_watchlist.php';

// Ensure DB table exists (or fallback backend will be used)
try { wl_db_init($pdo); } catch (Throwable $t) {}

$backend = 'db';
try { $backend = function_exists('wl_backend') ? (string)wl_backend($pdo) : 'db'; } catch (Throwable $t) { $backend = 'db'; }

// Stats
$total_items = 0;
$total_users = 0;
try {
  if ($backend === 'db') {
    $total_items = (int)($pdo->query("SELECT COUNT(*) FROM watchlist_items")?->fetchColumn() ?: 0);
    $total_users = (int)($pdo->query("SELECT COUNT(DISTINCT user_id) FROM watchlist_items")?->fetchColumn() ?: 0);
  } elseif ($backend === 'file') {
    $dir = wl_storage_dir();
    $files = ($dir && is_dir($dir)) ? (glob(rtrim($dir,'/\\') . '/u*.json') ?: []) : [];
    $total_users = count($files);
    $sum = 0;
    foreach ($files as $f) {
      $raw = @file_get_contents($f);
      if (!$raw) continue;
      $j = json_decode($raw, true);
      if (is_array($j)) $sum += count($j);
    }
    $total_items = $sum;
  }
} catch (Throwable $t) {
  // ignore
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['wipe_all'] ?? '') === 'yes') {
  try {
    $r = function_exists('wl_admin_wipe_all') ? wl_admin_wipe_all($pdo) : ['wiped'=>false,'backend'=>$backend];
    $backend = (string)($r['backend'] ?? $backend);
    $total_items = 0;
    $total_users = 0;
    flash_set('Watchlists cleared (' . $backend . ').', 'success');
  } catch (Throwable $t) {
    flash_set('Failed to clear watchlists: ' . $t->getMessage(), 'error');
  }
  header('Location: watchlist.php');
  exit;
}

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
  <title>Watchlist</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Watchlist</h2>
  <?php flash_show(); ?>

  <div class="row" style="gap:12px">
    <div class="card" style="margin:0; flex:1;">
      <div style="opacity:.9">Lets users add items to their Watchlist (<span class="inline-ico"><?= gc_svg_icon('star') ?></span>) from Live / Movies / Series / TMDB tiles.</div>
      <div style="margin-top:10px; opacity:.9">User page: <code>/portal/watchlist.php</code></div>
      <div style="margin-top:10px; opacity:.9">API: <code>/portal/watchlist_api.php</code></div>
    </div>

    <div class="card" style="margin:0; width:min(380px,100%);">
      <div><b>Backend:</b> <?= e($backend) ?></div>
      <div><b>Users:</b> <?= (int)$total_users ?></div>
      <div><b>Items:</b> <?= (int)$total_items ?></div>
    </div>
  </div>

  <form method="post" style="margin-top:14px" onsubmit="return confirm('Clear ALL watchlists for ALL users?');">
    <input type="hidden" name="wipe_all" value="yes">
    <button class="btn danger" type="submit">Clear all watchlists</button>
  </form>

</div>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
