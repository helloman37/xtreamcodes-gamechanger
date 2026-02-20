<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
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

// Filters
$scope = (string)($_GET['scope'] ?? 'channels'); // channels|movies|episodes
$status = (string)($_GET['status'] ?? 'all');   // all|ok|fail
$item_id = max(0, (int)($_GET['item_id'] ?? 0));
$q = trim((string)($_GET['q'] ?? ''));

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 10) $limit = 10;
if ($limit > 500) $limit = 500;

$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

$type = 'channel';
if ($scope === 'movies') $type = 'movie';
if ($scope === 'episodes') $type = 'episode';

$where = ["item_type = ?"];
$params = [$type];

if ($status === 'ok') { $where[] = "ok = 1"; }
if ($status === 'fail') { $where[] = "ok = 0"; }

if ($item_id > 0) {
  $where[] = "item_id = ?";
  $params[] = $item_id;
}

if ($q !== '') {
  $where[] = "(item_name LIKE ? OR url LIKE ? OR error LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
}

$where_sql = implode(" AND ", $where);

try {
  $stc = $pdo->prepare("SELECT COUNT(*) c FROM dsh_probe_history WHERE $where_sql");
  $stc->execute($params);
  $total = (int)($stc->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
} catch (Throwable $t) {
  $total = 0;
}

$pages = max(1, (int)ceil($total / $limit));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $limit;

$rows = [];
try {
  $st = $pdo->prepare("SELECT id,item_type,item_id,item_name,url,ok,http_code,latency_ms,error,created_at
    FROM dsh_probe_history
    WHERE $where_sql
    ORDER BY created_at DESC, id DESC
    LIMIT $limit OFFSET $offset");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $t) {
  flash_set("History table not available: " . $t->getMessage(), "error");
}

function qp(array $overrides = []): string {
  $qs = $_GET;
  foreach ($overrides as $k=>$v) {
    if ($v === null) unset($qs[$k]);
    else $qs[$k] = $v;
  }
  return '?' . http_build_query($qs);
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
  <title>Probe History</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card" style="margin:14px 0;">
  <h2>Probe History</h2>
  <p class="muted">Search past probe results (latency, HTTP, errors) for Live channels and VOD.</p>
  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:10px;">
    <a class="btn btn-small gray" href="stream_probe.php">Back to Stream Probe</a>
  </div>
  <?php flash_show(); ?>
</div>

<style>
  select, input[type="number"], input[type="text"]{max-width:100%; padding:9px 10px; border:1px solid var(--line); border-radius:10px; background:white;}
  select{min-width:170px;}
  .pill{display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); background:#fff;}
  .pill.ok{background:#ecfdf5; border-color:#a7f3d0;}
  .pill.fail{background:#fef2f2; border-color:#fecaca;}
  .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
</style>

<div class="card" style="margin:14px 0;">
  <h2>Filters</h2>
  <form method="get">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
      <label style="display:block;">
        <div class="muted">Scope</div>
        <select name="scope" class="inp">
          <option value="channels" <?= $scope==='channels'?'selected':'' ?>>Live channels</option>
          <option value="movies" <?= $scope==='movies'?'selected':'' ?>>Movies</option>
          <option value="episodes" <?= $scope==='episodes'?'selected':'' ?>>Episodes</option>
        </select>
      </label>

      <label style="display:block;">
        <div class="muted">Status</div>
        <select name="status" class="inp">
          <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
          <option value="ok" <?= $status==='ok'?'selected':'' ?>>OK only</option>
          <option value="fail" <?= $status==='fail'?'selected':'' ?>>Fail only</option>
        </select>
      </label>

      <label style="display:block;">
        <div class="muted">Item ID</div>
        <input class="inp" type="number" name="item_id" min="0" value="<?= e((string)$item_id) ?>" style="width:160px;">
      </label>

      <label style="display:block;">
        <div class="muted">Search</div>
        <input class="inp" type="text" name="q" value="<?= e($q) ?>" placeholder="name / url / error" style="width:260px;">
      </label>

      <label style="display:block;">
        <div class="muted">Limit</div>
        <input class="inp" type="number" name="limit" min="10" max="500" value="<?= e((string)$limit) ?>" style="width:120px;">
      </label>

      <button class="btn btn-small" type="submit">Apply</button>
    </div>
  </form>
</div>

<div class="card" style="margin:14px 0;">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
    <div class="muted">
      Total: <b><?= (int)$total ?></b> &nbsp; | &nbsp; Page <b><?= (int)$page ?></b> / <b><?= (int)$pages ?></b>
    </div>

    <div style="display:flex; gap:8px; align-items:center;">
      <a class="btn btn-small gray" href="<?= e(qp(['page'=>1])) ?>">First</a>
      <a class="btn btn-small gray" href="<?= e(qp(['page'=> max(1,$page-1) ])) ?>">Prev</a>
      <a class="btn btn-small gray" href="<?= e(qp(['page'=> min($pages,$page+1) ])) ?>">Next</a>
      <a class="btn btn-small gray" href="<?= e(qp(['page'=>$pages])) ?>">Last</a>
    </div>
  </div>

  <div style="margin-top:10px; overflow:auto;">
    <table>
      <thead>
        <tr>
          <th>Time</th>
          <th>Type</th>
          <th>ID</th>
          <th>Name</th>
          <th>OK</th>
          <th>HTTP</th>
          <th>Latency</th>
          <th>URL</th>
          <th>Error</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="9" class="muted">No history rows.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $ok = (int)($r['ok'] ?? 0) === 1;
            $u = (string)($r['url'] ?? '');
            $uShort = $u;
            if (mb_strlen($uShort) > 80) $uShort = mb_substr($uShort, 0, 77) . '...';
          ?>
          <tr>
            <td class="mono"><?= e((string)($r['created_at'] ?? '')) ?></td>
            <td><?= e((string)($r['item_type'] ?? '')) ?></td>
            <td class="mono"><?= (int)($r['item_id'] ?? 0) ?></td>
            <td><?= e((string)($r['item_name'] ?? '')) ?></td>
            <td>
              <span class="pill <?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? 'OK' : 'FAIL' ?></span>
            </td>
            <td class="mono"><?= e((string)($r['http_code'] ?? '')) ?></td>
            <td class="mono"><?= isset($r['latency_ms']) && $r['latency_ms'] !== null ? (int)$r['latency_ms'] . ' ms' : '' ?></td>
            <td class="mono" title="<?= e($u) ?>"><?= e($uShort) ?></td>
            <td><?= e((string)($r['error'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- container closed by topbar.html -->
</body>
</html>
