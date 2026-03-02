<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../admin_notifications_lib.php';
require_admin();

$pdo = db();

// Ensure plugin_settings exists (we use it as a lightweight settings store)
require_once __DIR__ . '/../plugins_core.php';
if (function_exists('gc_plugins_db_init')) {
  gc_plugins_db_init($pdo);
}

// Internal id (kept stable for settings storage)
$pid = 'dead_stream_hunter';

require_once __DIR__ . '/../scripts/dsh.php';

dsh_db_init($pdo);

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

// Settings defaults
$timeout = (int)dsh_setting_get($pdo, $pid, 'timeout', '8');
$batch   = (int)dsh_setting_get($pdo, $pid, 'batch', '15');
$fail_threshold = (int)dsh_setting_get($pdo, $pid, 'fail_threshold', '3');
$failover = (int)dsh_setting_get($pdo, $pid, 'failover', '1');
$insecure_tls = (int)dsh_setting_get($pdo, $pid, 'insecure_tls', '0');
$dead_group = dsh_setting_get($pdo, $pid, 'dead_group', 'DEAD');
$keep_days = (int)dsh_setting_get($pdo, $pid, 'keep_days', '7');
$auto_move_dead = (int)dsh_setting_get($pdo, $pid, 'auto_move_dead', '0');

if ($timeout < 2) $timeout = 2;
if ($timeout > 30) $timeout = 30;
if ($batch < 1) $batch = 1;
if ($batch > 50) $batch = 50;
if ($fail_threshold < 1) $fail_threshold = 1;
if ($fail_threshold > 50) $fail_threshold = 50;
if ($keep_days < 1) $keep_days = 1;
if ($keep_days > 365) $keep_days = 365;

$auto_move_dead = $auto_move_dead ? 1 : 0;

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['dsh_save_settings'] ?? '') === '1') {
  $timeout = max(2, min(30, (int)($_POST['timeout'] ?? $timeout)));
  $batch = max(1, min(50, (int)($_POST['batch'] ?? $batch)));
  $fail_threshold = max(1, min(50, (int)($_POST['fail_threshold'] ?? $fail_threshold)));
  $failover = isset($_POST['failover']) ? 1 : 0;
  $insecure_tls = isset($_POST['insecure_tls']) ? 1 : 0;
  $keep_days = max(1, min(365, (int)($_POST['keep_days'] ?? $keep_days)));
  $auto_move_dead = isset($_POST['auto_move_dead']) ? 1 : 0;
  $dead_group = trim((string)($_POST['dead_group'] ?? $dead_group));
  if ($dead_group === '') $dead_group = 'DEAD';
  if (strlen($dead_group) > 40) $dead_group = substr($dead_group, 0, 40);

  dsh_setting_set($pdo, $pid, 'timeout', (string)$timeout);
  dsh_setting_set($pdo, $pid, 'batch', (string)$batch);
  dsh_setting_set($pdo, $pid, 'fail_threshold', (string)$fail_threshold);
  dsh_setting_set($pdo, $pid, 'failover', (string)$failover);
  dsh_setting_set($pdo, $pid, 'insecure_tls', (string)$insecure_tls);
  dsh_setting_set($pdo, $pid, 'dead_group', (string)$dead_group);
  dsh_setting_set($pdo, $pid, 'keep_days', (string)$keep_days);
  dsh_setting_set($pdo, $pid, 'auto_move_dead', (string)$auto_move_dead);

  flash_set("Saved.", "success");
  header("Location: stream_probe.php");
  exit;
}

// AJAX endpoints
if (isset($_GET['ajax'])) {
  // unlock session so other pages don't hang
  @session_write_close();

  header('Content-Type: application/json; charset=utf-8');

  $ajax = (string)$_GET['ajax'];

  $timeout = (int)dsh_setting_get($pdo, $pid, 'timeout', '8');
  $batch   = (int)dsh_setting_get($pdo, $pid, 'batch', '15');
  $fail_threshold = (int)dsh_setting_get($pdo, $pid, 'fail_threshold', '3');
  $failover = (int)dsh_setting_get($pdo, $pid, 'failover', '1');
  $insecure_tls = (int)dsh_setting_get($pdo, $pid, 'insecure_tls', '0');
  $dead_group = dsh_setting_get($pdo, $pid, 'dead_group', 'DEAD');
  $keep_days = (int)dsh_setting_get($pdo, $pid, 'keep_days', '7');
  $auto_move_dead = (int)dsh_setting_get($pdo, $pid, 'auto_move_dead', '0');

  if ($timeout < 2) $timeout = 2;
  if ($timeout > 30) $timeout = 30;
  if ($batch < 1) $batch = 1;
  if ($batch > 50) $batch = 50;
  if ($fail_threshold < 1) $fail_threshold = 1;
  if ($fail_threshold > 50) $fail_threshold = 50;
  if ($keep_days < 1) $keep_days = 1;
  if ($keep_days > 365) $keep_days = 365;
  $auto_move_dead = $auto_move_dead ? 1 : 0;

  if ($ajax === 'scan') {
    $scope = (string)($_GET['scope'] ?? 'channels');
    $mode = (string)($_GET['mode'] ?? 'all'); // all|failing|never
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit = max(1, min(50, (int)($_GET['limit'] ?? $batch)));

    $results = [];
    $processed = 0;
    $total = 0;

    try {
      // Cleanup history once at the start of a scan
      if ($offset === 0) {
        try { dsh_probe_history_cleanup($pdo, $keep_days); } catch (Throwable $t) {}
      }
      if ($scope === 'channels') {
        $where = "1=1";
        if ($mode === 'failing') $where = "(works=0)";
        if ($mode === 'never') $where = "(last_checked_at IS NULL)";
        $total = (int)($pdo->query("SELECT COUNT(*) c FROM channels WHERE $where")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT id,name,group_title,stream_url,sources_json,works,last_status_code,last_checked_at
          FROM channels WHERE $where
          ORDER BY IFNULL(last_checked_at,'1970-01-01') ASC, id ASC
          LIMIT $limit OFFSET $offset");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $upd = $pdo->prepare("UPDATE channels SET stream_url=?, works=?, last_status_code=?, last_checked_at=NOW() WHERE id=?");
        $hup = $pdo->prepare("INSERT INTO stream_health (channel_id,last_ok,last_fail,fail_count,last_http,last_error)
          VALUES (?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE
            last_ok=VALUES(last_ok),
            last_fail=VALUES(last_fail),
            fail_count=IF(VALUES(last_ok) IS NOT NULL, 0, fail_count+1),
            last_http=VALUES(last_http),
            last_error=VALUES(last_error)");

        foreach ($rows as $ch) {
          $id = (int)$ch['id'];
          $name = (string)($ch['name'] ?? '');
          $primary = (string)($ch['stream_url'] ?? '');
          $sources = dsh_parse_sources_json($ch['sources_json'] ?? null);

          $r = dsh_check_with_sources($primary, $sources, $timeout, (bool)$insecure_tls);

          $ok = (bool)$r['ok'];
          $code = (int)($r['code'] ?? 0);
          $err = (string)($r['error'] ?? '');

          $used_url = (string)($r['used_url'] ?? $primary);
          $switched = (bool)($r['switched'] ?? false);
          $latency_ms = isset($r['latency_ms']) ? (int)$r['latency_ms'] : null;

          // If primary dead but backup works, optionally switch
          $final_url = $primary;
          $final_sources = $sources;

          if ($ok && $switched && $failover) {
            $final_url = $used_url;
            // keep the old primary as a backup source
            $old = trim($primary);
            if ($old !== '' && !in_array($old, $final_sources, true)) array_unshift($final_sources, $old);
          }

          // Persist channel status
          $upd->execute([$final_url, $ok ? 1 : 0, $code ?: null, $id]);

          // Log history
          try {
            dsh_probe_history_log($pdo, 'channel', $id, $name, $used_url, $ok, $code ?: null, $latency_ms, $err);
          } catch (Throwable $t) {}

          // Admin notify when a stream flips from working -> dead
          try {
            $wasOk = (int)($ch['works'] ?? 0) === 1;
            if ($wasOk && !$ok) {
              $title = 'Stream down: ' . $name;
              $msg = 'Channel #' . $id . ' in "' . (string)($ch['group_title'] ?? '') . '" failed (HTTP ' . $code . ').';
              if ($err !== '') $msg .= ' ' . mb_substr($err, 0, 160);
              $uniq = 'streamdown:' . $id . ':' . date('YmdH');
              admin_notifications_broadcast($pdo, 'streams', $title, $msg, '/admin/stream_probe.php?mode=failing', $uniq);
            }
          } catch (Throwable $t) {}

          // Persist channel health
          if ($ok) {
            $hup->execute([$id, date('Y-m-d H:i:s'), null, 0, $code ?: null, null]);
          } else {
            $hup->execute([$id, null, date('Y-m-d H:i:s'), 1, $code ?: null, $err ? mb_substr($err,0,255) : null]);
          }

          // Persist sources_json if changed (only when failover is on and we switched)
          if ($ok && $switched && $failover) {
            $j = json_encode(array_values(array_unique(array_filter($final_sources))), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $pdo->prepare("UPDATE channels SET sources_json=? WHERE id=?")->execute([$j ?: null, $id]);
          }

          $results[] = [
            'id' => $id,
            'name' => $name,
            'group' => (string)($ch['group_title'] ?? ''),
            'ok' => $ok,
            'code' => $code,
            'latency_ms' => $latency_ms,
            'switched' => ($ok && $switched && $failover) ? 1 : 0,
            'used_url' => $used_url,
            'checked' => (int)($r['checked'] ?? 1),
          ];

          $processed++;
          usleep(120000);
        }

        // Optional auto-action: move dead channels to group
        if ($auto_move_dead) {
          try {
            $dg = dsh_setting_get($pdo, $pid, 'dead_group', 'DEAD');
            $st = $pdo->prepare("UPDATE channels c JOIN stream_health sh ON sh.channel_id=c.id SET c.group_title=? WHERE c.works=0 AND IFNULL(sh.fail_count,0) >= ?");
            $st->execute([$dg, $fail_threshold]);
          } catch (Throwable $t) {}
        }
      } elseif ($scope === 'movies') {
        $where = "1=1";
        if ($mode === 'never') $where = "NOT EXISTS (SELECT 1 FROM dsh_vod_health h WHERE h.item_type='movie' AND h.item_id=m.id)";
        if ($mode === 'failing') $where = "EXISTS (SELECT 1 FROM dsh_vod_health h WHERE h.item_type='movie' AND h.item_id=m.id AND h.fail_count>0)";

        $total = (int)($pdo->query("SELECT COUNT(*) c FROM movies m WHERE $where")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT m.id,m.name,m.stream_url, m.category_id, c.name AS cat
          FROM movies m
          LEFT JOIN vod_categories c ON c.id=m.category_id
          WHERE $where
          ORDER BY m.id DESC
          LIMIT $limit OFFSET $offset");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $mrow) {
          $id = (int)$mrow['id'];
          $url = (string)($mrow['stream_url'] ?? '');
          $r = dsh_probe_url($url, $timeout, (bool)$insecure_tls);
          $ok = (bool)($r['works'] ?? false);
          $code = (int)($r['code'] ?? 0);
          $err = (string)($r['error'] ?? '');
          $latency_ms = isset($r['latency_ms']) ? (int)$r['latency_ms'] : null;

          dsh_vod_health_upsert($pdo, 'movie', $id, $ok, $code ?: null, $err);

          try {
            dsh_probe_history_log($pdo, 'movie', $id, (string)($mrow['name'] ?? ''), $url, $ok, $code ?: null, $latency_ms, $err);
          } catch (Throwable $t) {}

          $results[] = [
            'id' => $id,
            'name' => (string)($mrow['name'] ?? ''),
            'cat' => (string)($mrow['cat'] ?? ''),
            'ok' => $ok,
            'code' => $code,
            'latency_ms' => $latency_ms,
          ];
          $processed++;
          usleep(120000);
        }
      } elseif ($scope === 'episodes') {
        $where = "1=1";
        if ($mode === 'never') $where = "NOT EXISTS (SELECT 1 FROM dsh_vod_health h WHERE h.item_type='episode' AND h.item_id=e.id)";
        if ($mode === 'failing') $where = "EXISTS (SELECT 1 FROM dsh_vod_health h WHERE h.item_type='episode' AND h.item_id=e.id AND h.fail_count>0)";

        $total = (int)($pdo->query("SELECT COUNT(*) c FROM series_episodes e WHERE $where")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT e.id, e.title, e.stream_url, e.season_num, e.episode_num, s.name AS series_name
          FROM series_episodes e
          LEFT JOIN series s ON s.id=e.series_id
          WHERE $where
          ORDER BY e.id DESC
          LIMIT $limit OFFSET $offset");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $erow) {
          $id = (int)$erow['id'];
          $url = (string)($erow['stream_url'] ?? '');
          $r = dsh_probe_url($url, $timeout, (bool)$insecure_tls);
          $ok = (bool)($r['works'] ?? false);
          $code = (int)($r['code'] ?? 0);
          $err = (string)($r['error'] ?? '');
          $latency_ms = isset($r['latency_ms']) ? (int)$r['latency_ms'] : null;

          dsh_vod_health_upsert($pdo, 'episode', $id, $ok, $code ?: null, $err);

          try {
            dsh_probe_history_log($pdo, 'episode', $id, (string)($erow['title'] ?? ''), $url, $ok, $code ?: null, $latency_ms, $err);
          } catch (Throwable $t) {}

          $label = trim((string)($erow['series_name'] ?? '')) . ' S' . (int)($erow['season_num'] ?? 1) . 'E' . (int)($erow['episode_num'] ?? 1);
          $results[] = [
            'id' => $id,
            'name' => $label . ' - ' . (string)($erow['title'] ?? ''),
            'ok' => $ok,
            'code' => $code,
            'latency_ms' => $latency_ms,
          ];
          $processed++;
          usleep(120000);
        }
      } else {
        echo json_encode(['ok'=>false,'error'=>'bad scope']);
        exit;
      }

      $nextOffset = $offset + $processed;
      $finished = ($processed < $limit) || ($nextOffset >= $total);

      echo json_encode([
        'ok' => true,
        'scope' => $scope,
        'mode' => $mode,
        'offset' => $offset,
        'limit' => $limit,
        'processed' => $processed,
        'nextOffset' => $nextOffset,
        'total' => $total,
        'finished' => $finished,
        'results' => $results,
      ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      exit;
    } catch (Throwable $t) {
      echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
      exit;
    }
  }

  if ($ajax === 'dead_list') {
    $scope = (string)($_GET['scope'] ?? 'channels');
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));

    try {
      if ($scope === 'channels') {
        $st = $pdo->prepare("
          SELECT c.id,c.name,c.group_title,c.last_status_code,c.last_checked_at, sh.fail_count, sh.last_fail, sh.last_http, sh.last_error
          FROM channels c
          LEFT JOIN stream_health sh ON sh.channel_id=c.id
          WHERE c.works=0
          ORDER BY IFNULL(sh.fail_count,0) DESC, IFNULL(sh.last_fail,'1970-01-01') DESC
          LIMIT $limit
        ");
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      } elseif ($scope === 'movies' || $scope === 'episodes') {
        $type = $scope === 'movies' ? 'movie' : 'episode';
        if ($type === 'movie') {
          $st = $pdo->prepare("
            SELECT h.item_id AS id, m.name, h.fail_count, h.last_fail, h.last_http, h.last_error
            FROM dsh_vod_health h
            JOIN movies m ON m.id=h.item_id
            WHERE h.item_type='movie' AND h.fail_count >= ?
            ORDER BY h.fail_count DESC, h.last_fail DESC
            LIMIT $limit
          ");
          $st->execute([$fail_threshold]);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
          $st = $pdo->prepare("
            SELECT h.item_id AS id, CONCAT(COALESCE(s.name,''), ' S', e.season_num, 'E', e.episode_num, ' - ', e.title) AS name,
                   h.fail_count, h.last_fail, h.last_http, h.last_error
            FROM dsh_vod_health h
            JOIN series_episodes e ON e.id=h.item_id
            LEFT JOIN series s ON s.id=e.series_id
            WHERE h.item_type='episode' AND h.fail_count >= ?
            ORDER BY h.fail_count DESC, h.last_fail DESC
            LIMIT $limit
          ");
          $st->execute([$fail_threshold]);
          $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
      } else {
        echo json_encode(['ok'=>false,'error'=>'bad scope']); exit;
      }

      echo json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      exit;
    } catch (Throwable $t) {
      echo json_encode(['ok'=>false,'error'=>$t->getMessage()]); exit;
    }
  }

  if ($ajax === 'actions') {
    $action = (string)($_POST['action'] ?? '');
    try {
      if ($action === 'move_dead_channels') {
        $dead_group = dsh_setting_get($pdo, $pid, 'dead_group', 'DEAD');
        $st = $pdo->prepare("
          UPDATE channels c
          JOIN stream_health sh ON sh.channel_id=c.id
          SET c.group_title=?
          WHERE c.works=0 AND IFNULL(sh.fail_count,0) >= ?
        ");
        $st->execute([$dead_group, $fail_threshold]);
        echo json_encode(['ok'=>true,'moved'=>(int)$st->rowCount()]);
        exit;
      }

      if ($action === 'delete_dead_movies') {
        $st = $pdo->prepare("
          DELETE m FROM movies m
          JOIN dsh_vod_health h ON h.item_type='movie' AND h.item_id=m.id
          WHERE h.fail_count >= ?
        ");
        $st->execute([$fail_threshold]);
        echo json_encode(['ok'=>true,'deleted'=>(int)$st->rowCount()]);
        exit;
      }

      if ($action === 'delete_dead_episodes') {
        $st = $pdo->prepare("
          DELETE e FROM series_episodes e
          JOIN dsh_vod_health h ON h.item_type='episode' AND h.item_id=e.id
          WHERE h.fail_count >= ?
        ");
        $st->execute([$fail_threshold]);
        echo json_encode(['ok'=>true,'deleted'=>(int)$st->rowCount()]);
        exit;
      }

      echo json_encode(['ok'=>false,'error'=>'unknown action']);
      exit;
    } catch (Throwable $t) {
      echo json_encode(['ok'=>false,'error'=>$t->getMessage()]);
      exit;
    }
  }

  echo json_encode(['ok'=>false,'error'=>'unknown ajax']);
  exit;
}


// UI (HTML)
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Dead Stream Hunter</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<!-- container is opened by topbar.html -->

<div class="card" style="margin:14px 0;">
  <h2>Dead Stream Hunter</h2>
  <p class="muted">Scan live channels, movies, and episodes for dead links. Optional failover to backups and bulk cleanup tools.</p>
  <?php flash_show(); ?>
</div>



<style>
  select, input[type="number"], input[type="text"]{max-width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:10px; background:white;}
  select{min-width:170px;}
  .code{background:#0b1220; color:#e5e7eb; border-radius:12px; padding:10px; border:1px solid rgba(148,163,184,.25)}
</style>
<div class="card" style="margin:14px 0;">
  <h2>Settings</h2>
  <div style="margin-top:6px;">
    <a class="btn btn-small gray" href="stream_probe_history.php">View probe history</a>
  </div>
  <form method="post">
    <input type="hidden" name="dsh_save_settings" value="1">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <label style="display:block;">
        <div class="muted">Timeout (sec)</div>
        <input class="inp" type="number" name="timeout" min="2" max="30" value="<?= e((string)$timeout) ?>" style="width:140px;">
      </label>
      <label style="display:block;">
        <div class="muted">Batch size</div>
        <input class="inp" type="number" name="batch" min="1" max="50" value="<?= e((string)$batch) ?>" style="width:140px;">
      </label>
      <label style="display:block;">
        <div class="muted">Fail threshold</div>
        <input class="inp" type="number" name="fail_threshold" min="1" max="50" value="<?= e((string)$fail_threshold) ?>" style="width:140px;">
      </label>
      <label style="display:block;">
        <div class="muted">Dead group title (Live)</div>
        <input class="inp" type="text" name="dead_group" value="<?= e((string)$dead_group) ?>" style="width:200px;">
      </label>
      <label style="display:block;">
        <div class="muted">Keep probe history (days)</div>
        <input class="inp" type="number" name="keep_days" min="1" max="365" value="<?= e((string)$keep_days) ?>" style="width:160px;">
      </label>
      <label style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
        <input type="checkbox" name="failover" <?= $failover ? 'checked' : '' ?>>
        <span>Auto-failover to backup sources_json (Live)</span>
      </label>
      <label style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
        <input type="checkbox" name="auto_move_dead" <?= $auto_move_dead ? 'checked' : '' ?>>
        <span>Auto-move dead Live to group "<?= e($dead_group) ?>" (during scans)</span>
      </label>
      <label style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
        <input type="checkbox" name="insecure_tls" <?= $insecure_tls ? 'checked' : '' ?>>
        <span>Allow insecure TLS (bad certs)</span>
      </label>
      <button class="btn btn-small" type="submit">Save</button>
    </div>
  </form>
</div>

<div class="card" style="margin:14px 0;">
  <h2>Scan</h2>
  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <button class="btn btn-small" id="scan-ch">Scan Live Channels</button>
    <button class="btn btn-small" id="scan-mv">Scan Movies</button>
    <button class="btn btn-small" id="scan-ep">Scan Episodes</button>

    <span class="muted" style="margin-left:8px;">Mode:</span>
    <select id="scan-mode" class="inp" style="width:170px;">
      <option value="all">All</option>
      <option value="failing">Failing only</option>
      <option value="never">Never checked</option>
    </select>

    <span class="muted" id="scan-status" style="margin-left:8px;"></span>
  </div>

  <div style="margin-top:10px;">
    <div class="code" id="scan-log" style="white-space:pre-wrap; max-height:200px; overflow:auto;"></div>
  </div>

  <div style="margin-top:12px;">
    <table id="scan-table" style="display:none;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Name</th>
          <th>OK</th>
          <th>HTTP</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody></tbody>
    </table>
  </div>
</div>

<div class="card" style="margin:14px 0;">
  <h2>Dead list (threshold)</h2>
  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
    <button class="btn btn-small gray" id="dead-ch">Refresh Live</button>
    <button class="btn btn-small gray" id="dead-mv">Refresh Movies</button>
    <button class="btn btn-small gray" id="dead-ep">Refresh Episodes</button>

    <button class="btn btn-small danger" id="act-move-dead">Move dead Live to group "<?= e($dead_group) ?>"</button>
    <button class="btn btn-small danger" id="act-del-mv">Delete dead Movies</button>
    <button class="btn btn-small danger" id="act-del-ep">Delete dead Episodes</button>

    <span class="muted" id="dead-status" style="margin-left:8px;"></span>
  </div>

  <table id="dead-table" style="margin-top:10px;">
    <thead>
      <tr>
        <th>ID</th>
        <th>Name</th>
        <th>Fails</th>
        <th>Last fail</th>
        <th>HTTP</th>
        <th>Error</th>
      </tr>
    </thead>
    <tbody></tbody>
  </table>
</div>

<script>
(function(){
  const pid = <?= json_encode($pid) ?>;
  const base = 'stream_probe.php';

  const log = (s) => {
    const el = document.getElementById('scan-log');
    el.textContent = (el.textContent ? el.textContent + "\n" : "") + s;
    el.scrollTop = el.scrollHeight;
  };

  const setScanStatus = (s) => document.getElementById('scan-status').textContent = s;
  const setDeadStatus = (s) => document.getElementById('dead-status').textContent = s;

  let running = false;

  async function scan(scope) {
    if (running) return;
    running = true;
    document.getElementById('scan-log').textContent = '';
    setScanStatus('starting…');
    const mode = document.getElementById('scan-mode').value || 'all';
    const table = document.getElementById('scan-table');
    const tbody = table.querySelector('tbody');
    tbody.innerHTML = '';
    table.style.display = '';

    let offset = 0;
    let total = 0;
    let okCount = 0;
    let failCount = 0;

    while (true) {
      const url = base + '?ajax=scan&scope=' + encodeURIComponent(scope) +
        '&mode=' + encodeURIComponent(mode) +
        '&offset=' + offset +
        '&limit=' + <?= (int)$batch ?>;

      const r = await fetch(url, {credentials:'same-origin'});
      const j = await r.json();

      if (!j.ok) {
        log('ERROR: ' + (j.error || 'unknown'));
        break;
      }

      total = j.total || total;
      const rows = j.results || [];
      rows.forEach(row => {
        const tr = document.createElement('tr');
        const ok = !!row.ok;
        if (ok) okCount++; else failCount++;
        const notes = [];
        if (row.switched) notes.push('switched to backup');
        if (row.checked && row.checked > 1) notes.push('tried ' + row.checked + ' sources');
        tr.innerHTML = '<td>' + (row.id ?? '') + '</td>' +
          '<td>' + (row.name ?? '') + '</td>' +
          '<td>' + (ok ? 'YES' : 'NO') + '</td>' +
          '<td>' + (row.code ?? '') + '</td>' +
          '<td>' + (notes.join(', ') || '') + '</td>';
        tbody.appendChild(tr);
      });

      offset = j.nextOffset || offset + rows.length;
      setScanStatus('scope=' + scope + ' mode=' + mode + ' checked=' + offset + '/' + total + ' ok=' + okCount + ' fail=' + failCount);
      log('chunk: +' + rows.length + ' -> ' + offset + '/' + total);

      if (j.finished) {
        log('done.');
        break;
      }
    }

    running = false;
  }

  async function loadDead(scope) {
    setDeadStatus('loading…');
    const url = base + '?ajax=dead_list&scope=' + encodeURIComponent(scope) + '&limit=200';
    const r = await fetch(url, {credentials:'same-origin'});
    const j = await r.json();
    if (!j.ok) { setDeadStatus('ERROR: ' + (j.error || 'unknown')); return; }

    const tbody = document.querySelector('#dead-table tbody');
    tbody.innerHTML = '';
    (j.rows || []).forEach(row => {
      const tr = document.createElement('tr');
      tr.innerHTML = '<td>' + (row.id ?? '') + '</td>' +
        '<td>' + (row.name ?? '') + '</td>' +
        '<td>' + (row.fail_count ?? row.fail_count === 0 ? 0 : (row.fail_count ?? '')) + '</td>' +
        '<td>' + (row.last_fail ?? '') + '</td>' +
        '<td>' + (row.last_http ?? row.last_status_code ?? '') + '</td>' +
        '<td style="max-width:360px; word-break:break-word;">' + (row.last_error ?? '') + '</td>';
      tbody.appendChild(tr);
    });

    setDeadStatus('scope=' + scope + ' rows=' + (j.rows || []).length);
  }

  async function doAction(action, confirmText) {
    if (!confirm(confirmText)) return;
    setDeadStatus('working…');
    const r = await fetch(base + '?ajax=actions', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'action=' + encodeURIComponent(action)
    });
    const j = await r.json();
    if (!j.ok) { setDeadStatus('ERROR: ' + (j.error || 'unknown')); return; }
    setDeadStatus('OK: ' + JSON.stringify(j));
  }

  document.getElementById('scan-ch').addEventListener('click', () => scan('channels'));
  document.getElementById('scan-mv').addEventListener('click', () => scan('movies'));
  document.getElementById('scan-ep').addEventListener('click', () => scan('episodes'));

  document.getElementById('dead-ch').addEventListener('click', () => loadDead('channels'));
  document.getElementById('dead-mv').addEventListener('click', () => loadDead('movies'));
  document.getElementById('dead-ep').addEventListener('click', () => loadDead('episodes'));

  document.getElementById('act-move-dead').addEventListener('click', () => doAction('move_dead_channels', 'Move dead Live channels (fail_count >= threshold) to group "<?= e($dead_group) ?>"?'));
  document.getElementById('act-del-mv').addEventListener('click', () => doAction('delete_dead_movies', 'Delete dead movies (fail_count >= threshold)? This cannot be undone.'));
  document.getElementById('act-del-ep').addEventListener('click', () => doAction('delete_dead_episodes', 'Delete dead episodes (fail_count >= threshold)? This cannot be undone.'));

  // initial
  loadDead('channels');
})();
</script>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
