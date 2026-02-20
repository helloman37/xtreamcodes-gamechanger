<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_admin();
$pdo = db();

$tot = (int)($pdo->query("SELECT COUNT(*) c FROM channels")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
$ok  = (int)($pdo->query("SELECT COUNT(*) c FROM channels WHERE works=1")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
$bad = $tot - $ok;
$last = $pdo->query("SELECT MAX(last_checked_at) AS t FROM channels")->fetch(PDO::FETCH_ASSOC)['t'] ?? null;

$fails = $pdo->query("
  SELECT c.id,c.name, c.last_status_code, c.last_checked_at,
         sh.fail_count, sh.last_fail, sh.last_http, sh.last_error
  FROM channels c
  LEFT JOIN stream_health sh ON sh.channel_id=c.id
  WHERE c.works=0 OR (sh.fail_count>0 AND sh.last_fail IS NOT NULL)
  ORDER BY COALESCE(sh.last_fail, c.last_checked_at) DESC
  LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

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
  <title>Health</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>System Health</h2>
  <div class="row">
    <div class="box" style="flex:1;">
      <div class="badge">Channels</div>
      <div style="font-size:28px;font-weight:800;margin-top:6px;"><?= $tot ?></div>
      <div class="muted">Total</div>
    </div>
    <div class="box" style="flex:1;">
      <div class="badge">Working</div>
      <div style="font-size:28px;font-weight:800;margin-top:6px;"><?= $ok ?></div>
      <div class="muted">works=1</div>
    </div>
    <div class="box" style="flex:1;">
      <div class="badge">Failing</div>
      <div style="font-size:28px;font-weight:800;margin-top:6px;"><?= $bad ?></div>
      <div class="muted">works=0</div>
    </div>
  </div>

  <p class="muted" style="margin-top:10px;">Last probe: <?= e($last ?: 'never') ?></p>
  <p class="muted">Cron suggestions:</p>
  <div class="code" style="white-space:pre-wrap;">*/5 * * * * php scripts/stream_probe.php --limit=400 >/dev/null 2>&1
0 */6 * * * php scripts/epg_import.php --flush=0 >/dev/null 2>&1</div>
</div>

<br>

<div class="card">
  <h2>Recent Failures</h2>
  <table>
    <tr><th>Channel</th><th>HTTP</th><th>Fails</th><th>Last Fail</th><th>Error</th></tr>
    <?php foreach($fails as $f): ?>
      <tr>
        <td><?=e($f['name'])?> <span class="muted">(#<?=$f['id']?>)</span></td>
        <td class="code"><?=e((string)($f['last_http'] ?? $f['last_status_code'] ?? ''))?></td>
        <td><?= (int)($f['fail_count'] ?? 0) ?></td>
        <td><?= e((string)($f['last_fail'] ?? '')) ?></td>
        <td><?= e((string)($f['last_error'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
