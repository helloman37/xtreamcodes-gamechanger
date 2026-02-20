<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

// Tunables
$legacyBatchSize    = 50;  // legacy redirect-based checker batch
$timeoutPerStream   = 6;   // seconds
$apiDefaultBatch    = 10;  // AJAX checker default per-request batch
$apiMaxBatch        = 25;  // hard cap per-request batch to reduce timeout risk

// unlock session so other pages don't hang
session_write_close();
@set_time_limit(0);
@ignore_user_abort(true);

/*
  ------------------------------
  AJAX API
  ------------------------------
  checker.php?api=1&action=init
  checker.php?api=1&action=step&offset=0&limit=10
  checker.php?api=1&action=recent
*/
if (isset($_GET['api'])) {
  header('Content-Type: application/json; charset=utf-8');

  try {
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'init') {
      $total = (int)($pdo->query("SELECT COUNT(*) AS t FROM channels")->fetch()['t'] ?? 0);
      echo json_encode([
        'ok' => true,
        'total' => $total,
        'default_limit' => $apiDefaultBatch,
        'max_limit' => $apiMaxBatch,
      ]);
      exit;
    }

    if ($action === 'recent') {
      $recent = $pdo->query("SELECT id, name, works, last_status_code, last_checked_at FROM channels ORDER BY last_checked_at DESC LIMIT 50")->fetchAll();
      echo json_encode([
        'ok' => true,
        'recent' => $recent,
      ]);
      exit;
    }

    if ($action === 'step') {
      $offset = (int)($_GET['offset'] ?? 0);
      $limit  = (int)($_GET['limit'] ?? $apiDefaultBatch);
      if ($limit < 1) $limit = 1;
      if ($limit > $apiMaxBatch) $limit = $apiMaxBatch;

      $total = (int)($pdo->query("SELECT COUNT(*) AS t FROM channels")->fetch()['t'] ?? 0);

      $stmt = $pdo->prepare("SELECT id, name, stream_url FROM channels ORDER BY id LIMIT :l OFFSET :o");
      $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
      $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
      $stmt->execute();
      $rows = $stmt->fetchAll();

      $update = $pdo->prepare("UPDATE channels SET works=?, last_status_code=?, last_checked_at=NOW() WHERE id=?");

      $results = [];
      foreach ($rows as $ch) {
        $r = check_stream_url((string)$ch['stream_url'], $timeoutPerStream);
        $update->execute([$r['works'] ? 1 : 0, $r['code'], $ch['id']]);

        $results[] = [
          'id' => (int)$ch['id'],
          'name' => (string)($ch['name'] ?? ''),
          'works' => (bool)$r['works'],
          'code' => (int)$r['code'],
          'checked_at' => date('Y-m-d H:i:s'),
          'error' => (string)($r['error'] ?? ''),
        ];

        // tiny breather so we don't hammer remote hosts too hard
        usleep(120000);
      }

      $processed = count($results);
      $nextOffset = $offset + $processed;
      $finished = ($processed < $limit) || ($nextOffset >= $total);

      echo json_encode([
        'ok' => true,
        'offset' => $offset,
        'limit' => $limit,
        'processed' => $processed,
        'next_offset' => $nextOffset,
        'total' => $total,
        'finished' => $finished,
        'results' => $results,
      ]);
      exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error', 'detail' => $e->getMessage()]);
    exit;
  }
}

/*
  ------------------------------
  Legacy redirect-based runner (kept for compatibility)
  ------------------------------
*/
if (isset($_POST['run_legacy'])) {
  header("Location: checker.php?run=1&offset=0");
  exit;
}

$running = isset($_GET['run']);
$offset  = (int)($_GET['offset'] ?? 0);

if ($running) {
  $stmt = $pdo->prepare("SELECT id, stream_url FROM channels ORDER BY id LIMIT :l OFFSET :o");
  $stmt->bindValue(':l', $legacyBatchSize, PDO::PARAM_INT);
  $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
  $stmt->execute();
  $rows = $stmt->fetchAll();

  $update = $pdo->prepare("UPDATE channels SET works=?, last_status_code=?, last_checked_at=NOW() WHERE id=?");

  $count = 0;
  foreach ($rows as $ch) {
    $r = check_stream_url((string)$ch['stream_url'], $timeoutPerStream);
    $update->execute([$r['works'] ? 1 : 0, $r['code'], $ch['id']]);
    $count++;
    usleep(150000);
  }

  if ($count < $legacyBatchSize) {
    header("Location: checker.php?done=1");
    exit;
  }
  header("Location: checker.php?run=1&offset=" . ($offset + $legacyBatchSize));
  exit;
}

$recent = $pdo->query("SELECT id, name, works, last_status_code, last_checked_at FROM channels ORDER BY last_checked_at DESC LIMIT 50")->fetchAll();
$total  = (int)($pdo->query("SELECT COUNT(*) AS t FROM channels")->fetch()['t'] ?? 0);
$done   = isset($_GET['done']);
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
  <title>Channel Health Checker</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
  <style>
    .progress-wrap{margin-top:10px}
    .progress-meta{display:flex; justify-content:space-between; gap:10px; align-items:center; flex-wrap:wrap; margin-top:8px}
    .progress{
      height:12px; border-radius:999px; overflow:hidden;
      background:var(--bg-soft); border:1px solid var(--line);
      box-shadow:inset 0 1px 0 rgba(255,255,255,.6);
    }
    .progress > div{
      height:100%; width:0%;
      background:linear-gradient(90deg, rgba(79,109,245,.85), rgba(6,182,212,.85));
      transition:width .15s ease;
    }
    .spinner{width:14px; height:14px; border-radius:50%; border:2px solid rgba(15,23,42,.18); border-top-color:rgba(79,109,245,.95); animation:spin .8s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px;}
    .results-scroll{max-height:420px; overflow:auto; border-radius:12px}
    .row-actions{display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:10px}
    .btn.secondary{background:#0f172a; box-shadow:0 6px 16px rgba(15,23,42,.25)}
    .btn.ghost{background:transparent; color:var(--text); border:1px solid var(--line); box-shadow:none}
    .btn.ghost:hover{background:var(--bg-soft)}
    .inline-input{width:auto; min-width:90px}
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>Channel Health Checker</h2>
  <?php if ($done): ?><div class="pill good">HEALTH CHECK FINISHED</div><?php endif; ?>
  <p class="muted">Total Channels: <b id="totalChannels"><?=$total?></b></p>

  <div class="row-actions">
    <button class="btn" id="startBtn" type="button">Start Health Check</button>
    <button class="btn ghost" id="stopBtn" type="button" disabled>Stop</button>

    <label class="muted" style="display:flex; gap:8px; align-items:center;">
      Batch
      <input class="inline-input" id="batchInput" type="number" min="1" max="25" value="<?=$apiDefaultBatch?>">
      <span class="mono muted" id="batchHint">(max 25)</span>
    </label>

    <span id="runState" class="muted mono"></span>
  </div>

  <div class="progress-wrap">
    <div class="progress"><div id="progressBar"></div></div>
    <div class="progress-meta">
      <div class="row" style="gap:8px;">
        <span id="spinner" class="spinner" style="display:none"></span>
        <span class="mono" id="progressText">Ready.</span>
      </div>
      <div class="mono muted" id="speedText"></div>
    </div>
  </div>

  <details style="margin-top:12px">
    <summary class="muted" style="cursor:pointer; font-weight:800;">Legacy mode (page redirects)</summary>
    <p class="muted" style="margin:10px 0 8px">If your server blocks AJAX requests, you can run the old redirect-based checker.</p>
    <form method="post">
      <button name="run_legacy" value="1">Run Legacy Health Check</button>
    </form>
  </details>
</div>

<br>

<div class="card">
  <h3>Live / Recent Results</h3>
  <p class="muted" style="margin-top:0">This table updates as the checker runs.</p>

  <div class="results-scroll">
    <table id="resultsTable">
      <thead>
        <tr><th>Name</th><th>Works</th><th>Code</th><th>Checked</th></tr>
      </thead>
      <tbody id="resultsBody">
        <?php foreach($recent as $c): ?>
        <tr>
          <td><?=e($c['name'])?></td>
          <td><?=$c['works']?'<span class="pill good">OK</span>':'<span class="pill bad">DEAD</span>'?></td>
          <td><?=e($c['last_status_code'])?></td>
          <td><?=e($c['last_checked_at'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(() => {
  const $ = (id) => document.getElementById(id);

  const startBtn = $('startBtn');
  const stopBtn  = $('stopBtn');
  const batchInput = $('batchInput');
  const batchHint = $('batchHint');
  const totalEl = $('totalChannels');
  const progressBar = $('progressBar');
  const progressText = $('progressText');
  const speedText = $('speedText');
  const resultsBody = $('resultsBody');
  const spinner = $('spinner');
  const runState = $('runState');

  let running = false;
  let offset = 0;
  let total = parseInt(totalEl.textContent || '0', 10) || 0;
  let okCount = 0;
  let deadCount = 0;
  let startedAt = 0;

  function setRunningState(isRunning) {
    running = isRunning;
    startBtn.disabled = isRunning;
    stopBtn.disabled = !isRunning;
    spinner.style.display = isRunning ? 'inline-block' : 'none';
    runState.textContent = isRunning ? 'RUNNING' : '';
  }

  function clampBatch(n) {
    const x = Number(n);
    if (!Number.isFinite(x)) return 10;
    return Math.min(25, Math.max(1, Math.floor(x)));
  }

  function updateProgress() {
    const pct = total > 0 ? Math.min(100, (offset / total) * 100) : 0;
    progressBar.style.width = pct.toFixed(2) + '%';

    const elapsed = (Date.now() - startedAt) / 1000;
    let speed = '';
    if (running && elapsed > 1 && offset > 0) {
      const perMin = (offset / elapsed) * 60;
      speed = `${perMin.toFixed(1)} checks/min`;
    }
    speedText.textContent = speed;

    progressText.textContent = `Checked ${offset}/${total}  •  OK ${okCount}  •  DEAD ${deadCount}`;
  }

  function prependRow(r) {
    const tr = document.createElement('tr');
    const status = r.works ? '<span class="pill good">OK</span>' : '<span class="pill bad">DEAD</span>';
    tr.innerHTML = `
      <td>${escapeHtml(r.name || '')}</td>
      <td>${status}</td>
      <td>${escapeHtml(String(r.code ?? ''))}</td>
      <td>${escapeHtml(r.checked_at || '')}</td>
    `;
    resultsBody.insertBefore(tr, resultsBody.firstChild);

    // keep the table from growing forever
    const maxRows = 200;
    while (resultsBody.children.length > maxRows) {
      resultsBody.removeChild(resultsBody.lastChild);
    }
  }

  function escapeHtml(s) {
    return String(s)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  async function api(action, params = {}) {
    const u = new URL('checker.php', window.location.href);
    u.searchParams.set('api', '1');
    u.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, String(v)));

    const res = await fetch(u.toString(), { credentials: 'same-origin' });
    const j = await res.json().catch(() => null);
    if (!res.ok || !j || !j.ok) {
      const msg = (j && (j.error || j.detail)) ? (j.error || j.detail) : `HTTP ${res.status}`;
      throw new Error(msg);
    }
    return j;
  }

  async function refreshTotals() {
    const j = await api('init');
    total = parseInt(j.total || '0', 10) || 0;
    totalEl.textContent = String(total);
    batchHint.textContent = `(max ${j.max_limit || 25})`;
    if (!batchInput.value) batchInput.value = String(j.default_limit || 10);
  }

  async function start() {
    setRunningState(true);
    offset = 0;
    okCount = 0;
    deadCount = 0;
    startedAt = Date.now();
    progressBar.style.width = '0%';
    progressText.textContent = 'Starting…';
    speedText.textContent = '';

    // clear table and refill with recent (so the UI doesn't start empty)
    try {
      const recent = await api('recent');
      resultsBody.innerHTML = '';
      (recent.recent || []).forEach((r) => {
        prependRow({
          name: r.name,
          works: !!r.works,
          code: r.last_status_code,
          checked_at: r.last_checked_at,
        });
      });
    } catch (e) {
      // non-fatal
    }

    updateProgress();

    const limit = clampBatch(batchInput.value);
    batchInput.value = String(limit);

    while (running) {
      try {
        const step = await api('step', { offset, limit });
        (step.results || []).forEach((r) => {
          if (r.works) okCount++; else deadCount++;
          prependRow(r);
        });

        offset = step.next_offset;
        updateProgress();

        if (step.finished) {
          setRunningState(false);
          progressText.textContent = `Finished. Checked ${offset}/${total}  •  OK ${okCount}  •  DEAD ${deadCount}`;
          break;
        }
      } catch (e) {
        setRunningState(false);
        progressText.textContent = `Stopped (error): ${e.message}`;
        break;
      }
    }
  }

  function stop() {
    setRunningState(false);
    progressText.textContent = `Stopped. Checked ${offset}/${total}  •  OK ${okCount}  •  DEAD ${deadCount}`;
  }

  startBtn.addEventListener('click', async () => {
    try {
      await refreshTotals();
    } catch (e) {
      // if totals fail, still let user try
    }
    start();
  });

  stopBtn.addEventListener('click', stop);

  // Initial metadata refresh (non-blocking)
  refreshTotals().catch(() => {});
})();
</script>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
