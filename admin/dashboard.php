<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();
$counts = [
  'channels' => $pdo->query("SELECT COUNT(*) c FROM channels")->fetch()['c'],
  'users'    => $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'],
  'plans'    => $pdo->query("SELECT COUNT(*) c FROM plans")->fetch()['c'],
  'subs'     => $pdo->query("SELECT COUNT(*) c FROM subscriptions")->fetch()['c'],
];

$online = [
  'streams' => (int)$pdo->query("SELECT COUNT(*) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
  'users' => (int)$pdo->query("SELECT COUNT(DISTINCT user_id) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
  'connections' => (int)$pdo->query("SELECT COUNT(DISTINCT ip) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
  'servers' => 1,
];

$access_logs = $pdo->query("
  SELECT ss.last_seen, ss.ip, u.username AS user_name, c.name AS channel_name
  FROM stream_sessions ss
  LEFT JOIN users u ON u.id = ss.user_id
  LEFT JOIN channels c ON c.id = ss.channel_id
  ORDER BY ss.last_seen DESC
  LIMIT 10
")->fetchAll();


$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
$cfg = require __DIR__ . '/../config.php';
$base_url = rtrim($cfg['base_url'], '/');

// Derive site root for protected links (strip /public if present)
$site_url = rtrim($base_url, '/');
if (preg_match('~/public$~', $site_url)) {
  $site_url = preg_replace('~/public$~', '', $site_url);
}

?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Admin Panel</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>
<h1>IPTV Admin Panel</h1>
<p class="muted">Logged in as <?=e($_SESSION['admin_username'])?></p>
<?php flash_show(); ?>


<div class="card" style="margin:14px 0;">
  <h2>Playlist Link Output Mode</h2>
  <div class="row">
    <div>
      <label for="linkMode">Link mode</label>
      <select id="linkMode">
        <option value="auto">Auto (current behavior)</option>
        <option value="direct_protected">Direct Link with Protection</option>
        <option value="standard_protected">Standard Link with Protection</option>
      </select>
    </div>
    <div>
      <label for="m3uUrl">Example M3U URL</label>
      <input id="m3uUrl" type="text" readonly>
    </div>
    <div>
      <label for="xmlUrl">Example XMLTV URL</label>
      <input id="xmlUrl" type="text" readonly>
    </div>
  </div>
  <p class="muted" style="margin-top:8px;">
    Protected modes always hide upstream stream URLs and return links from <b><?=e($site_url)?></b>.
  </p>
</div>

<script>
(function(){
  const baseUrl = <?= json_encode($base_url) ?>;
  const siteUrl = <?= json_encode($site_url) ?>;
  const modeSel = document.getElementById('linkMode');
  const m3u = document.getElementById('m3uUrl');
  const xml = document.getElementById('xmlUrl');

  function update(){
    const mode = modeSel.value;
    const linkParam = (mode === 'auto') ? '' : '&link=' + encodeURIComponent(mode);
    m3u.value = baseUrl + '/get.php?username=YOURUSER&password=YOURPASS&type=m3u' + linkParam;
    xml.value = baseUrl + '/xmltv.php?username=YOURUSER&password=YOURPASS';
  }
  modeSel.addEventListener('change', update);
  update();
})();
</script>



<div class="stat-tiles xtream-tiles">
  <div class="xtile xtile-green">
    <div class="xtile-left">
      <div class="xtile-value"><?= (int)$online['streams'] ?> / <?= (int)$counts['channels'] ?></div>
      <div class="xtile-label">Online Streams</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle"><span>▶️</span></div>
    </div>
</div>

  <div class="xtile xtile-blue">
    <div class="xtile-left">
      <div class="xtile-value"><?= (int)$online['users'] ?> / <?= (int)$counts['users'] ?></div>
      <div class="xtile-label">Online Users</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle"><span>👥</span></div>
    </div>
</div>

  <div class="xtile xtile-yellow">
    <div class="xtile-left">
      <div class="xtile-value"><?= (int)$online['connections'] ?> / ∞</div>
      <div class="xtile-label">Online Connections</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle"><span>⚡</span></div>
    </div>
</div>

  <div class="xtile xtile-gray">
    <div class="xtile-left">
      <div class="xtile-value"><?= (int)$online['servers'] ?> / <?= (int)$online['servers'] ?></div>
      <div class="xtile-label">Online Servers</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle"><span>🗄️</span></div>
    </div>
</div>
</div>

<div class="card" style="margin-top:14px;">

  <h2 style="margin-bottom:8px;">Recent Access Logs</h2>
  <div style="overflow:auto;">
    <table class="table">
      <tr>
        <th>Time</th><th>User</th><th>Channel</th><th>IP</th>
      </tr>
      <?php foreach($access_logs as $log): ?>
      <tr>
        <td><?= e($log['last_seen']) ?></td>
        <td><?= e($log['user_name'] ?? '-') ?></td>
        <td><?= e($log['channel_name'] ?? '-') ?></td>
        <td><?= e($log['ip']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($access_logs)): ?>
      <tr><td colspan="4" style="text-align:center; opacity:.7;">No recent sessions</td></tr>
      <?php endif; ?>
    </table>
  </div>
</div>
</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>