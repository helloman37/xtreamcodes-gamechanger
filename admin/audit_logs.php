<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_admin();
$pdo = db();

$event = trim($_GET['event'] ?? '');
$user  = trim($_GET['user'] ?? '');

$where = "1=1";
$params = [];
if ($event !== '') { $where .= " AND a.event=?"; $params[] = $event; }
if ($user !== '') {
  $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
  $st->execute([$user]);
  $uid = (int)($st->fetch(PDO::FETCH_ASSOC)['id'] ?? 0);
  if ($uid) { $where .= " AND a.user_id=?"; $params[] = $uid; }
}

$st = $pdo->prepare("
  SELECT a.*, u.username
  FROM audit_logs a
  LEFT JOIN users u ON u.id=a.user_id
  WHERE $where
  ORDER BY a.created_at DESC
  LIMIT 300
");
$st->execute($params);
$logs = $st->fetchAll(PDO::FETCH_ASSOC);

$events = $pdo->query("SELECT event, COUNT(*) c FROM audit_logs GROUP BY event ORDER BY c DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);

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
  <title>Audit Logs</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Audit Logs</h2>
  <form method="get" class="row" style="align-items:flex-end;">
    <div>
      <label>Event</label>
      <select name="event">
        <option value="">(all)</option>
        <?php foreach($events as $e): ?>
          <option value="<?=e($e['event'])?>" <?= $event===$e['event']?'selected':'' ?>><?=e($e['event'])?> (<?=$e['c']?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>User (username)</label>
      <input name="user" value="<?=e($user)?>" placeholder="jb123">
    </div>
    <div>
      <button>Filter</button>
      <a class="btn gray" href="audit_logs.php">Reset</a>
    </div>
  </form>
</div>

<br>

<div class="card">
  <table>
    <tr><th>Time</th><th>Event</th><th>User</th><th>IP</th><th>Meta</th></tr>
    <?php foreach($logs as $l): ?>
      <tr>
        <td class="code"><?=e($l['created_at'])?></td>
        <td><?=e($l['event'])?></td>
        <td><?=e($l['username'] ?? ('#'.$l['user_id']))?></td>
        <td class="code"><?=e($l['ip'] ?? '')?></td>
        <td class="code" style="max-width:520px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=e($l['meta_json'] ?? '')?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
