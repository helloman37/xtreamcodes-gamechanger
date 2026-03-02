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
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card dash-top">

<div class="dash-head">
  <div class="dash-head-left">
    <h1 class="dash-title">Dashboard</h1>
    <div class="dash-sub muted">
      Channels <b><?= (int)$counts['channels'] ?></b> · Users <b><?= (int)$counts['users'] ?></b> · Plans <b><?= (int)$counts['plans'] ?></b> · Subs <b><?= (int)$counts['subs'] ?></b>
    </div>
  </div>
  <div class="dash-head-right">
    <span class="dash-chip">Signed in: <?=e($_SESSION['admin_username'])?></span>
  </div>
</div>

<?php flash_show(); ?>



<div class="stat-tiles xtream-tiles">
  <div class="xtile xtile-green">
    <div class="xtile-left">
      <div class="xtile-value"><span id="dashOnlineStreams"><?= (int)$online['streams'] ?></span> / <span id="dashTotalChannels"><?= (int)$counts['channels'] ?></span></div>
      <div class="xtile-label">Online Streams</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle" aria-hidden="true">
        <svg class="xtile-ico" viewBox="0 0 24 24" role="img" focusable="false">
          <circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2" />
          <polygon points="10 8 16 12 10 16 10 8" fill="currentColor" stroke="none" />
        </svg>
      </div>
    </div>
</div>

  <div class="xtile xtile-blue">
    <div class="xtile-left">
      <div class="xtile-value"><span id="dashOnlineUsers"><?= (int)$online['users'] ?></span> / <span id="dashTotalUsers"><?= (int)$counts['users'] ?></span></div>
      <div class="xtile-label">Online Users</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle" aria-hidden="true">
        <svg class="xtile-ico" viewBox="0 0 24 24" role="img" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
          <circle cx="9" cy="7" r="4" />
          <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
          <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
      </div>
    </div>
</div>

  <div class="xtile xtile-yellow">
    <div class="xtile-left">
      <div class="xtile-value"><span id="dashOnlineConnections"><?= (int)$online['connections'] ?></span> / ∞</div>
      <div class="xtile-label">Online Connections</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle" aria-hidden="true">
        <svg class="xtile-ico" viewBox="0 0 24 24" role="img" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2" />
        </svg>
      </div>
    </div>
</div>

  <div class="xtile xtile-gray">
    <div class="xtile-left">
      <div class="xtile-value"><span id="dashOnlineServers"><?= (int)$online['servers'] ?></span> / <span id="dashTotalServers"><?= (int)$online['servers'] ?></span></div>
      <div class="xtile-label">Online Servers</div>
    </div>
    <div class="xtile-right">
      <div class="xtile-circle" aria-hidden="true">
        <svg class="xtile-ico" viewBox="0 0 24 24" role="img" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="18" height="7" rx="2" />
          <rect x="3" y="14" width="18" height="7" rx="2" />
          <circle cx="7" cy="6.5" r="1" fill="currentColor" stroke="none" />
          <circle cx="7" cy="17.5" r="1" fill="currentColor" stroke="none" />
        </svg>
      </div>
    </div>
</div>
</div>

</div>

<div class="card dash-logs">
  <div class="dash-card-head">
    <h2>Recent Access Logs</h2>
    <a class="btn btn-small" href="sessions.php">View sessions</a>
  </div>
  <div class="dash-table-wrap">
    <table class="dash-table">
      <thead>
        <tr>
          <th>Time</th><th>User</th><th>Channel</th><th>IP</th>
        </tr>
      </thead>
      <tbody id="dashRecentAccessRows">
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
      </tbody>
    </table>
  </div>
</div>


<details class="card dash-settings" id="dashLinkMode">
  <summary>
    <span class="dash-settings-title">Playlist link output</span>
    <span class="dash-settings-pill" id="linkModePill">Auto</span>
    <span class="dash-settings-meta muted">Examples &amp; protection</span>
  </summary>

  <div class="dash-settings-body">
    <div class="dash-settings-grid">
      <div class="dash-settings-field">
        <label for="linkMode">Link mode</label>
        <select id="linkMode">
          <option value="auto">Auto (current behavior)</option>
          <option value="direct_protected">Direct Link with Protection</option>
          <option value="standard_protected">Standard Link with Protection</option>
        </select>
      </div>
      <div class="dash-settings-field">
        <label for="m3uUrl">Example M3U URL</label>
        <input id="m3uUrl" type="text" readonly>
      </div>
      <div class="dash-settings-field">
        <label for="xmlUrl">Example XMLTV URL</label>
        <input id="xmlUrl" type="text" readonly>
      </div>
    </div>

    <p class="muted dash-settings-note">
      Protected modes always hide upstream stream URLs and return links from <b><?=e($site_url)?></b>.
    </p>
  </div>
</details>

<script>
(function(){
  const baseUrl = <?= json_encode($base_url) ?>;
  const modeSel = document.getElementById('linkMode');
  const m3u = document.getElementById('m3uUrl');
  const xml = document.getElementById('xmlUrl');
  const pill = document.getElementById('linkModePill');

  if(!modeSel || !m3u || !xml){ return; }

  function update(){
    const mode = modeSel.value;
    const linkParam = (mode === 'auto') ? '' : '&link=' + encodeURIComponent(mode);
    m3u.value = baseUrl + '/get.php?username=YOURUSER&password=YOURPASS&type=m3u_plus' + linkParam;
    xml.value = baseUrl + '/xmltv.php?username=YOURUSER&password=YOURPASS';

    if(pill){
      const txt = (modeSel.options[modeSel.selectedIndex] && modeSel.options[modeSel.selectedIndex].text) ? modeSel.options[modeSel.selectedIndex].text : 'Auto';
      pill.textContent = txt.replace(/\s*\(.*\)\s*$/,'');
    }
  }
  modeSel.addEventListener('change', update);
  update();
})();
</script>
<script>
(function(){
  const streamsEl = document.getElementById('dashOnlineStreams');
  const usersEl = document.getElementById('dashOnlineUsers');
  const connsEl = document.getElementById('dashOnlineConnections');
  const serversEl = document.getElementById('dashOnlineServers');

  const totalChEl = document.getElementById('dashTotalChannels');
  const totalUsersEl = document.getElementById('dashTotalUsers');
  const totalServersEl = document.getElementById('dashTotalServers');

  const logsBody = document.getElementById('dashRecentAccessRows');

  if(!streamsEl || !usersEl || !connsEl || !logsBody){ return; }

  const url = 'ajax/dashboard_live.php';
  let inFlight = false;

  async function refresh(){
    if(inFlight) return;
    inFlight = true;
    try{
      const r = await fetch(url, {
        method: 'GET',
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if(!r.ok) throw new Error('HTTP ' + r.status);
      const j = await r.json();
      if(!j || !j.ok) throw new Error('Bad response');

      if(j.online){
        if(typeof j.online.streams !== 'undefined') streamsEl.textContent = j.online.streams;
        if(typeof j.online.users !== 'undefined') usersEl.textContent = j.online.users;
        if(typeof j.online.connections !== 'undefined') connsEl.textContent = j.online.connections;
        if(serversEl && typeof j.online.servers !== 'undefined') serversEl.textContent = j.online.servers;
      }
      if(j.counts){
        if(totalChEl && typeof j.counts.channels !== 'undefined') totalChEl.textContent = j.counts.channels;
        if(totalUsersEl && typeof j.counts.users !== 'undefined') totalUsersEl.textContent = j.counts.users;
        if(totalServersEl && typeof j.online?.servers !== 'undefined') totalServersEl.textContent = j.online.servers;
      }
      if(typeof j.logs_html === 'string'){
        logsBody.innerHTML = j.logs_html;
      }
    }catch(e){
      // silent
    }finally{
      inFlight = false;
    }
  }

  refresh();
  setInterval(refresh, 1000);
})();
</script>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>