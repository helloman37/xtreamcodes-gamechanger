<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

$hours = (int)($_GET['hours'] ?? 24);
if ($hours < 1) $hours = 24;
if ($hours > 720) $hours = 720; // 30 days

$endpoint = trim((string)($_GET['endpoint'] ?? ''));
$reason   = trim((string)($_GET['reason'] ?? ''));
$ip       = trim((string)($_GET['ip'] ?? ''));
$username = trim((string)($_GET['username'] ?? ''));

$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 50) $limit = 50;
if ($limit > 1000) $limit = 1000;

$where = ["rl.created_at >= (NOW() - INTERVAL ? HOUR)"];
$params = [$hours];

if ($endpoint !== '') { $where[] = "rl.endpoint = ?"; $params[] = $endpoint; }
if ($reason !== '')   { $where[] = "IFNULL(rl.reason,'') = ?"; $params[] = $reason; }
if ($ip !== '')       { $where[] = "rl.ip = ?"; $params[] = $ip; }
// IMPORTANT: Several queries on this page only read from `request_logs` (no JOIN to users).
// So we must NOT reference a users alias (e.g. `u.username`) inside the shared WHERE clause.
// `request_logs.username` already stores the attempted/known username string.
if ($username !== '') { $where[] = "rl.username = ?"; $params[] = $username; }

$where_sql = implode(' AND ', $where);

// Distinct values for filters
$endpoints = $pdo->query("SELECT DISTINCT endpoint FROM request_logs ORDER BY endpoint")->fetchAll(PDO::FETCH_COLUMN);
$reasons   = $pdo->query("SELECT DISTINCT IFNULL(reason,'') AS r FROM request_logs ORDER BY r")->fetchAll(PDO::FETCH_COLUMN);

// Summary counts
$st = $pdo->prepare("SELECT COUNT(*) c FROM request_logs rl WHERE $where_sql");
$st->execute($params);
$total = (int)($st->fetchColumn() ?: 0);

$st = $pdo->prepare("SELECT IFNULL(rl.reason,'') AS reason, COUNT(*) c FROM request_logs rl WHERE $where_sql GROUP BY IFNULL(rl.reason,'') ORDER BY c DESC LIMIT 12");
$st->execute($params);
$by_reason = $st->fetchAll(PDO::FETCH_ASSOC);

// Top IPs (non-ok)
$st = $pdo->prepare("
  SELECT rl.ip, COUNT(*) c,
         SUM(CASE WHEN IFNULL(rl.reason,'ok') <> 'ok' THEN 1 ELSE 0 END) AS bad,
         MAX(rl.created_at) AS last_seen
  FROM request_logs rl
  WHERE $where_sql
  GROUP BY rl.ip
  ORDER BY bad DESC, c DESC
  LIMIT 12
");
$st->execute($params);
$top_ips = $st->fetchAll(PDO::FETCH_ASSOC);

// Suspicious users: many IPs
$st = $pdo->prepare("
  SELECT rl.user_id,
         COALESCE(NULLIF(rl.username,''), u.username) AS username,
         COUNT(DISTINCT rl.ip) AS uniq_ips,
         COUNT(*) AS hits,
         MAX(rl.created_at) AS last_seen
  FROM request_logs rl
  LEFT JOIN users u ON u.id=rl.user_id
  WHERE $where_sql AND rl.user_id IS NOT NULL
  GROUP BY rl.user_id
  HAVING COUNT(DISTINCT rl.ip) >= 4
  ORDER BY uniq_ips DESC, hits DESC
  LIMIT 12
");
$st->execute($params);
$suspicious = $st->fetchAll(PDO::FETCH_ASSOC);

// Recent rows
$st = $pdo->prepare("
  SELECT rl.*, u.username AS user_name
  FROM request_logs rl
  LEFT JOIN users u ON u.id=rl.user_id
  WHERE $where_sql
  ORDER BY rl.created_at DESC, rl.id DESC
  LIMIT $limit
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

function pill(string $t, string $kind=''): string {
  $t = e($t);
  $cls = 'pill'.($kind?(' '.$kind):'');
  return "<span class=\"$cls\">$t</span>";
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
  <title>Telemetry</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    .grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
    .pill{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);font-size:12px;white-space:nowrap}
    .pill.ok{border-color:rgba(0,255,170,.35)}
    .pill.bad{border-color:rgba(255,99,132,.35)}
    .muted{opacity:.8}
    .small{font-size:12px}
    .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
    .filters > div{min-width:160px;flex:1}
    code{word-break:break-word}
    .row-actions a{margin-right:10px}
    pre.meta{white-space:pre-wrap;word-break:break-word;max-width:520px}
    @media(max-width:900px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>Telemetry</h2>
  <p class="muted">API + stream starts logged to <code>request_logs</code>. Use this to spot brute-force, restreaming, and noisy devices.</p>

  <form method="get">
    <div class="filters">
      <div>
        <label>Window (hours)</label>
        <input type="number" name="hours" value="<?= (int)$hours ?>" min="1" max="720">
      </div>
      <div>
        <label>Endpoint</label>
        <select name="endpoint">
          <option value="">All</option>
          <?php foreach($endpoints as $ep): ?>
            <option value="<?= e($ep) ?>" <?= $ep===$endpoint?'selected':''?>><?= e($ep) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Reason</label>
        <select name="reason">
          <option value="">All</option>
          <?php foreach($reasons as $r): ?>
            <option value="<?= e($r) ?>" <?= $r===$reason?'selected':''?>><?= $r==='' ? '(empty)' : e($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>IP (exact)</label>
        <input name="ip" value="<?= e($ip) ?>" placeholder="1.2.3.4">
      </div>
      <div>
        <label>Username</label>
        <input name="username" value="<?= e($username) ?>" placeholder="client username">
      </div>
      <div>
        <label>Rows</label>
        <input type="number" name="limit" value="<?= (int)$limit ?>" min="50" max="1000">
      </div>
      <div style="flex:0;min-width:140px">
        <button class="btn" type="submit">Apply</button>
      </div>
    </div>
  </form>

  <div style="margin-top:14px">
    <?= pill((string)$total.' hits', $total>0?'ok':'') ?>
    <?= pill('last '.$hours.'h', ''); ?>
  </div>
</div>

<div class="card">
  <div class="grid">
    <div>
      <h3>Top reasons</h3>
      <table>
        <tr><th>Reason</th><th>Count</th></tr>
        <?php foreach($by_reason as $r): ?>
          <tr>
            <td>
              <?php
                $rr = (string)($r['reason'] ?? '');
                echo pill($rr===''?'(empty)':$rr, ($rr==='ok'||$rr===''?'ok':'bad'));
              ?>
            </td>
            <td><?= (int)$r['c'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <p class="muted small">Tip: reasons are set by endpoints when they know what's happening. Anything else will show as <code>ok</code> or blank.</p>
    </div>

    <div>
      <h3>Top IPs</h3>
      <table>
        <tr><th>IP</th><th>Bad</th><th>Total</th><th></th></tr>
        <?php foreach($top_ips as $r): ?>
          <tr>
            <td><code><?= e($r['ip'] ?? '') ?></code></td>
            <td><?= (int)($r['bad'] ?? 0) ?></td>
            <td><?= (int)($r['c'] ?? 0) ?></td>
            <td class="row-actions">
              <a href="telemetry.php?<?= http_build_query(array_merge($_GET, ['ip'=>$r['ip'] ?? ''])) ?>">Filter</a>
              <a href="abuse_bans.php?type=ip&ip=<?= urlencode((string)($r['ip'] ?? '')) ?>">Ban IP</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
      <p class="muted small">"Bad" = reason not equal to <code>ok</code>.</p>
    </div>
  </div>
</div>

<div class="card">
  <h3>Suspicious accounts</h3>
  <p class="muted">Users with 4+ unique IPs in this window (often restreaming or account sharing).</p>
  <table>
    <tr><th>User</th><th>Unique IPs</th><th>Hits</th><th>Last</th><th></th></tr>
    <?php foreach($suspicious as $s): ?>
      <tr>
        <td><strong><?= e($s['username'] ?? ('#'.(int)$s['user_id'])) ?></strong></td>
        <td><?= (int)$s['uniq_ips'] ?></td>
        <td><?= (int)$s['hits'] ?></td>
        <td class="muted"><?= e($s['last_seen'] ?? '') ?></td>
        <td class="row-actions">
          <a href="telemetry.php?<?= http_build_query(array_merge($_GET, ['username'=>$s['username'] ?? ''])) ?>">Filter</a>
          <?php if(!empty($s['user_id'])): ?>
            <a href="abuse_bans.php?type=user&user_id=<?= (int)$s['user_id'] ?>">Ban User</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h3>Recent requests</h3>
  <table>
    <tr>
      <th>Time</th><th>Endpoint</th><th>Action</th><th>User</th><th>IP</th><th>Status</th><th>Reason</th><th>ms</th><th></th>
    </tr>
    <?php foreach($rows as $r): ?>
      <?php
        $rr = (string)($r['reason'] ?? '');
        $ok = ($rr==='' || $rr==='ok');
        $uname = (string)($r['username'] ?? '') ?: (string)($r['user_name'] ?? '');
      ?>
      <tr>
        <td class="muted small"><?= e($r['created_at'] ?? '') ?></td>
        <td><?= pill((string)$r['endpoint'], '') ?></td>
        <td class="muted"><?= e((string)($r['action'] ?? '')) ?></td>
        <td><?= $uname ? '<strong>'.e($uname).'</strong>' : '<span class="muted">-</span>' ?></td>
        <td><code><?= e((string)($r['ip'] ?? '')) ?></code></td>
        <td><?= (int)($r['status_code'] ?? 200) ?></td>
        <td><?= pill($rr===''?'(empty)':$rr, $ok?'ok':'bad') ?></td>
        <td class="muted"><?= (int)($r['duration_ms'] ?? 0) ?></td>
        <td class="row-actions">
          <?php if(!empty($r['ip'])): ?>
            <a href="telemetry.php?<?= http_build_query(array_merge($_GET, ['ip'=>$r['ip']])) ?>">IP</a>
            <a href="abuse_bans.php?type=ip&ip=<?= urlencode((string)$r['ip']) ?>">Ban IP</a>
          <?php endif; ?>
          <?php if(!empty($r['user_id'])): ?>
            <a href="telemetry.php?<?= http_build_query(array_merge($_GET, ['username'=>$uname])) ?>">User</a>
            <a href="abuse_bans.php?type=user&user_id=<?= (int)$r['user_id'] ?>">Ban User</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php if (!empty($r['meta_json'])): ?>
        <tr>
          <td></td>
          <td colspan="8" class="muted small">
            <pre class="meta"><?php echo e($r['meta_json']); ?></pre>
          </td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </table>
</div>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
