<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['kill_session'])) {
    $sid = (int)$_POST['session_id'];
    try {
      $pdo->prepare("UPDATE stream_sessions SET killed_at=NOW() WHERE id=?")->execute([$sid]);
    } catch (Throwable $e) {
      // fallback: delete
      $pdo->prepare("DELETE FROM stream_sessions WHERE id=?")->execute([$sid]);
    }
    flash_set("Killed session #$sid", "success");
    header("Location: sessions.php");
    exit;
  }

  if (isset($_POST['kill_user'])) {
    $uid = (int)$_POST['user_id'];
    $pdo->prepare("DELETE FROM stream_sessions WHERE user_id=?")->execute([$uid]);
    flash_set("Killed sessions for user #$uid", "success");
    header("Location: sessions.php");
    exit;
  }
  if (isset($_POST['kill_all'])) {
    $pdo->exec("TRUNCATE TABLE stream_sessions");
    flash_set("Killed ALL sessions", "success");
    header("Location: sessions.php");
    exit;
  }
  if (isset($_POST['reset_device_lock'])) {
    $uid = (int)$_POST['user_id'];
    $pdo->prepare("DELETE FROM user_devices WHERE user_id=?")->execute([$uid]);
    $pdo->prepare("UPDATE users SET device_lock=0 WHERE id=?")->execute([$uid]);
    flash_set("Device lock cleared for user #$uid", "success");
    header("Location: sessions.php");
    exit;
  }
}

$win = 300;
$st = $pdo->prepare("
  SELECT ss.user_id, u.username,
         COUNT(*) AS hits,
         COUNT(DISTINCT ss.ip) AS ip_count,
         COUNT(DISTINCT IFNULL(ss.device_fp,'')) AS device_count,
         MAX(ss.last_seen) AS last_seen
  FROM stream_sessions ss
  JOIN users u ON u.id=ss.user_id
  WHERE ss.last_seen > (NOW() - INTERVAL ? SECOND)
  GROUP BY ss.user_id, u.username
  ORDER BY last_seen DESC
");
$st->execute([$win]);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

$detail = $pdo->prepare("
  SELECT ss.id, ss.user_id, ss.stream_type, ss.item_id,
         ss.channel_id,
         CASE
           WHEN ss.stream_type='live' THEN c.name
           WHEN ss.stream_type='movie' THEN m.name
           WHEN ss.stream_type='episode' THEN CONCAT(s.name, ' - S', LPAD(e.season_num,2,'0'),'E', LPAD(e.episode_num,2,'0'), ' - ', e.title)
           ELSE CONCAT('#', ss.item_id)
         END AS item_name,
         ss.ip, ss.device_fp, ss.user_agent, ss.last_seen, ss.killed_at
  FROM stream_sessions ss
  LEFT JOIN channels c ON c.id=ss.channel_id
  LEFT JOIN movies m ON m.id=ss.item_id AND ss.stream_type='movie'
  LEFT JOIN series_episodes e ON e.id=ss.item_id AND ss.stream_type='episode'
  LEFT JOIN series s ON s.id=e.series_id
  WHERE ss.user_id=? AND ss.last_seen > (NOW() - INTERVAL ? SECOND)
  ORDER BY ss.last_seen DESC
  LIMIT 200
");

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
  <title>Sessions</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>
<div class="card">
  <h2>Active Sessions (last <?=$win?>s)</h2>
  <?php flash_show(); ?>
  <form method="post" onsubmit="return confirm('Kill ALL sessions?');" style="margin-top:10px;">
    <input type="hidden" name="kill_all" value="1">
    <button class="btn red">Kill ALL</button>
  </form>
</div>

<br>

<div class="card">
  <table>
    <tr><th>User</th><th>Devices</th><th>IPs</th><th>Hits</th><th>Last Seen</th><th>Actions</th></tr>
    <?php foreach($users as $u): ?>
      <tr>
        <td><?=e($u['username'])?> <span class="muted">(#<?=$u['user_id']?>)</span></td>
        <td><?= (int)$u['device_count'] ?></td>
        <td><?= (int)$u['ip_count'] ?></td>
        <td><?= (int)$u['hits'] ?></td>
        <td><?=e($u['last_seen'])?></td>
        <td>
          <form method="post" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="user_id" value="<?=$u['user_id']?>">
            <button name="kill_user" value="1" class="btn red" onclick="return confirm('Kill sessions for this user?');">Kill</button>
            <button name="reset_device_lock" value="1" class="btn gray" onclick="return confirm('Clear device lock + device fingerprints?');">Reset Device</button>
          </form>
        </td>
      </tr>
      <tr>
        <td colspan="6" style="background:#0b1220;">
          <?php
            $detail->execute([(int)$u['user_id'], $win]);
            $rows = $detail->fetchAll(PDO::FETCH_ASSOC);
          ?>
          <div style="max-height:240px;overflow:auto;border-radius:10px;">
            <table>
              <tr><th>Stream</th><th>IP</th><th>Device FP</th><th>User-Agent</th><th>Last</th><th>Kill</th></tr>
              <?php foreach($rows as $r): ?>
                <tr>
                  <td><?=e($r['item_name'] ?? ('#'.$r['item_id']))?></td>
                  <td class="code"><?=e($r['ip'])?></td>
                  <td class="code"><?=e($r['device_fp'] ?: '-')?></td>
                  <td><?=e($r['user_agent'])?></td>
                  <td><?=e($r['last_seen'])?></td>
                  <td>
                    <?php if (empty($r['killed_at'])): ?>
                      <form method="post" style="margin:0;">
                        <input type="hidden" name="session_id" value="<?=$r['id']?>">
                        <button class="btn red" name="kill_session" value="1" onclick="return confirm('Kill this one stream?');">Kill</button>
                      </form>
                    <?php else: ?>
                      <span class="muted">killed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
