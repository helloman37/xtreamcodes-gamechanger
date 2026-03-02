<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

// ---------- defaults ----------
$disk_warn = (int)system_setting_get($pdo, 'health_disk_warn_pct', '80');
$disk_crit = (int)system_setting_get($pdo, 'health_disk_crit_pct', '90');
if ($disk_warn < 1 || $disk_warn > 99) $disk_warn = 80;
if ($disk_crit < 1 || $disk_crit > 99) $disk_crit = 90;
if ($disk_crit < $disk_warn) $disk_crit = max($disk_warn, 90);

$dir_warn_gb = (float)system_setting_get($pdo, 'health_dir_warn_gb', '25');
$dir_crit_gb = (float)system_setting_get($pdo, 'health_dir_crit_gb', '50');
if ($dir_warn_gb <= 0) $dir_warn_gb = 25;
if ($dir_crit_gb <= 0) $dir_crit_gb = 50;
if ($dir_crit_gb < $dir_warn_gb) $dir_crit_gb = max($dir_warn_gb, 50);

// Optional custom log sources (JSON)
$log_sources_json = (string)system_setting_get($pdo, 'health_log_sources_json', '');

// ---------- save thresholds / log sources ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_health_settings'])) {
  csrf_check();

  $dw = (int)($_POST['disk_warn_pct'] ?? $disk_warn);
  $dc = (int)($_POST['disk_crit_pct'] ?? $disk_crit);
  $gw = (float)($_POST['dir_warn_gb'] ?? $dir_warn_gb);
  $gc = (float)($_POST['dir_crit_gb'] ?? $dir_crit_gb);

  if ($dw < 1 || $dw > 99) $dw = 80;
  if ($dc < 1 || $dc > 99) $dc = 90;
  if ($dc < $dw) $dc = $dw;

  if ($gw <= 0) $gw = 25;
  if ($gc <= 0) $gc = 50;
  if ($gc < $gw) $gc = $gw;

  system_setting_set($pdo, 'health_disk_warn_pct', (string)$dw);
  system_setting_set($pdo, 'health_disk_crit_pct', (string)$dc);
  system_setting_set($pdo, 'health_dir_warn_gb', (string)$gw);
  system_setting_set($pdo, 'health_dir_crit_gb', (string)$gc);

  $ls = trim((string)($_POST['log_sources_json'] ?? ''));
  if ($ls !== '') {
    // Store only if JSON is valid and reasonably small.
    $decoded = json_decode($ls, true);
    if (is_array($decoded) && strlen($ls) <= 20000) {
      system_setting_set($pdo, 'health_log_sources_json', $ls);
    }
  } else {
    system_setting_set($pdo, 'health_log_sources_json', '');
  }

  flash_set('Health settings saved', 'success');
  header('Location: system_health.php');
  exit;
}

// ---------- helpers ----------
function sh_bytes_to_human(int $bytes): string {
  if ($bytes < 1024) return $bytes . ' B';
  $units = ['KB','MB','GB','TB','PB'];
  $v = (float)$bytes;
  $i = 0;
  while ($v >= 1024 && $i < count($units)-1) { $v /= 1024; $i++; }
  return number_format($v, $v >= 10 ? 1 : 2) . ' ' . $units[$i];
}

function sh_read_meminfo(): array {
  $out = ['ok'=>false];
  $p = '/proc/meminfo';
  if (!is_readable($p)) return $out;
  $raw = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$raw) return $out;
  $m = [];
  foreach ($raw as $line) {
    if (preg_match('/^([A-Za-z_]+):\s+(\d+)\s+kB$/', trim($line), $mm)) {
      $m[$mm[1]] = (int)$mm[2] * 1024;
    }
  }
  if (empty($m['MemTotal'])) return $out;
  $total = (int)$m['MemTotal'];
  // Prefer MemAvailable (best estimate) otherwise compute from free+buffers+cached
  $avail = (int)($m['MemAvailable'] ?? (($m['MemFree'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['Cached'] ?? 0)));
  if ($avail < 0) $avail = 0;
  if ($avail > $total) $avail = $total;
  $used = $total - $avail;
  $pct = $total > 0 ? (int)round(($used / $total) * 100) : 0;
  return ['ok'=>true, 'total'=>$total, 'used'=>$used, 'avail'=>$avail, 'pct'=>$pct];
}

function sh_dir_size_bytes(string $path, int $max_nodes=20000): array {
  $rp = realpath($path);
  if (!$rp || !is_dir($rp)) return ['ok'=>false, 'bytes'=>0, 'method'=>'na', 'path'=>$path];

  // Fast path: use `du` if available.
  $du_ok = function_exists('shell_exec') && is_callable('shell_exec');
  if ($du_ok) {
    $cmd = 'du -sb ' . escapeshellarg($rp) . ' 2>/dev/null';
    $res = @shell_exec($cmd);
    if (is_string($res) && preg_match('/^(\d+)\s+/', trim($res), $m)) {
      return ['ok'=>true, 'bytes'=>(int)$m[1], 'method'=>'du', 'path'=>$rp];
    }
  }

  // Fallback: recursive scan with cap.
  $bytes = 0;
  $nodes = 0;
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  try {
    foreach ($it as $f) {
      $nodes++;
      if ($nodes > $max_nodes) {
        return ['ok'=>true, 'bytes'=>$bytes, 'method'=>'scan_partial', 'path'=>$rp];
      }
      if ($f->isFile()) {
        $bytes += (int)$f->getSize();
      }
    }
  } catch (Throwable $e) {
    return ['ok'=>true, 'bytes'=>$bytes, 'method'=>'scan_partial', 'path'=>$rp];
  }

  return ['ok'=>true, 'bytes'=>$bytes, 'method'=>'scan', 'path'=>$rp];
}

function sh_tail_file(string $path, int $lines=200, int $max_bytes=1048576): array {
  $rp = realpath($path);
  if (!$rp || !is_file($rp) || !is_readable($rp)) return [];
  $lines = max(20, min(2000, $lines));
  $fp = @fopen($rp, 'rb');
  if (!$fp) return [];

  $buf = '';
  $pos = -1;
  $read = 0;
  $got = 0;

  fseek($fp, 0, SEEK_END);
  $size = (int)ftell($fp);
  while ($size + $pos >= 0 && $read < $max_bytes && $got <= $lines) {
    fseek($fp, $pos, SEEK_END);
    $ch = fgetc($fp);
    if ($ch === false) break;
    $buf = $ch . $buf;
    $read++;
    if ($ch === "\n") $got++;
    $pos--;
  }
  fclose($fp);

  $arr = preg_split("/\r\n|\n|\r/", trim($buf));
  if (!$arr) return [];
  if (count($arr) > $lines) $arr = array_slice($arr, -$lines);
  return $arr;
}

function sh_line_severity(string $line): string {
  $l = strtolower($line);
  if (preg_match('/\b(fatal|panic|segfault|emerg)\b/', $l)) return 'fatal';
  if (preg_match('/\b(crit|alert)\b/', $l)) return 'crit';
  if (preg_match('/\b(error)\b/', $l)) return 'error';
  if (preg_match('/\b(warn|warning)\b/', $l)) return 'warn';
  if (preg_match('/\b(notice)\b/', $l)) return 'notice';
  return 'info';
}

function sh_is_safe_log_path(string $path, string $panel_root): bool {
  $rp = realpath($path);
  if (!$rp) return false;
  // Block virtual/fs pseudo paths
  foreach (['/proc/','/sys/','/dev/','/run/','/etc/','/root/'] as $bad) {
    if (str_starts_with($rp, $bad)) return false;
  }
  // Allow /var/log, panel root, and typical shared-hosting homes.
  if (str_starts_with($rp, '/var/log/')) return true;
  if (str_starts_with($rp, rtrim($panel_root,'/') . '/')) return true;
  if (preg_match('#^/home/[^/]+/#', $rp)) return true;
  if (preg_match('#^/srv/[^/]+/#', $rp)) return true;
  if (preg_match('#^/www/[^/]+/#', $rp)) return true;
  return false;
}


// Disk space helpers (shared hosts may disable disk_total_space/disk_free_space)
function sh_disk_space_bytes(string $path): array {
  $path = $path ?: '.';

  $total = null;
  $free  = null;

  // Prefer PHP built-ins when available
  if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
    $t = @disk_total_space($path);
    $f = @disk_free_space($path);
    if ($t !== false) $total = (int)$t;
    if ($f !== false) $free  = (int)$f;
    return [$total, $free];
  }

  // Fallback: parse df output (most shared hosts allow this)
  if (function_exists('shell_exec')) {
    $cmd = 'df -Pk ' . escapeshellarg($path) . ' 2>/dev/null';
    $out = @shell_exec($cmd);
    if (is_string($out) && trim($out) !== '') {
      $lines = preg_split('/\r?\n/', trim($out));
      if (count($lines) >= 2) {
        // Filesystem 1024-blocks Used Available Capacity Mounted on
        $cols = preg_split('/\s+/', trim($lines[1]));
        if (count($cols) >= 5) {
          $blocks_total = (int)$cols[1];
          $blocks_avail = (int)$cols[3];
          if ($blocks_total > 0) $total = $blocks_total * 1024;
          if ($blocks_avail >= 0) $free = $blocks_avail * 1024;
          return [$total, $free];
        }
      }
    }
  }

  // If everything is blocked by host settings, return nulls
  return [$total, $free];
}


// ---------- compute snapshot ----------
$panel_root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [];
$mem = sh_read_meminfo();
[$disk_total, $disk_free] = sh_disk_space_bytes($panel_root);
$disk_used  = ($disk_total !== null && $disk_free !== null) ? max(0, (int)$disk_total - (int)$disk_free) : null;
$disk_pct   = ($disk_total !== null && $disk_used !== null && (int)$disk_total > 0) ? (int)round(($disk_used / (int)$disk_total) * 100) : null;

$db_ok = true;
$db_info = ['version'=>'', 'uptime'=>'', 'threads'=>'', 'slow_queries'=>''];
try {
  $pdo->query('SELECT 1');
  try {
    $db_info['version'] = (string)($pdo->query('SELECT VERSION() v')->fetch(PDO::FETCH_ASSOC)['v'] ?? '');
  } catch (Throwable $e) {}
  try {
    $st = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Slow_queries')");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $r) {
      $k = strtolower((string)($r['Variable_name'] ?? ''));
      $v = (string)($r['Value'] ?? '');
      if ($k === 'uptime') $db_info['uptime'] = $v;
      if ($k === 'threads_connected') $db_info['threads'] = $v;
      if ($k === 'slow_queries') $db_info['slow_queries'] = $v;
    }
  } catch (Throwable $e) {}
} catch (Throwable $e) {
  $db_ok = false;
}

$alerts = [];
if ($disk_pct !== null) {
  if ($disk_pct >= $disk_crit) $alerts[] = "Disk usage is {$disk_pct}% (CRIT)";
  else if ($disk_pct >= $disk_warn) $alerts[] = "Disk usage is {$disk_pct}% (WARN)";
}
if (!$db_ok) $alerts[] = "Database connection failed (CRIT)";

$storage_items = [
  ['key'=>'panel',  'label'=>'Panel Root', 'path'=>$panel_root],
  ['key'=>'cache',  'label'=>'Cache',     'path'=>$panel_root . '/cache'],
  ['key'=>'uploads','label'=>'Uploads',   'path'=>$panel_root . '/uploads'],
  ['key'=>'storage','label'=>'Storage',   'path'=>$panel_root . '/storage'],
  ['key'=>'backups','label'=>'Backups',   'path'=>$panel_root . '/storage/backups'],
  ['key'=>'stream', 'label'=>'Stream',    'path'=>$panel_root . '/stream'],
];

$storage_rows = [];
foreach ($storage_items as $it) {
  $sz = sh_dir_size_bytes($it['path']);
  $gb = ($sz['ok'] ? ($sz['bytes'] / (1024*1024*1024)) : 0);
  $status = 'ok';
  if ($sz['ok']) {
    if ($gb >= $dir_crit_gb) $status = 'crit';
    else if ($gb >= $dir_warn_gb) $status = 'warn';
  } else {
    $status = 'na';
  }
  if ($status === 'crit') $alerts[] = $it['label'] . " is " . number_format($gb, 1) . " GB (CRIT)";
  else if ($status === 'warn') $alerts[] = $it['label'] . " is " . number_format($gb, 1) . " GB (WARN)";

  $storage_rows[] = [
    'label' => $it['label'],
    'path' => (string)($sz['path'] ?? $it['path']),
    'bytes' => (int)($sz['bytes'] ?? 0),
    'ok' => (bool)($sz['ok'] ?? false),
    'method' => (string)($sz['method'] ?? 'na'),
    'status' => $status,
    'gb' => $gb,
  ];
}

// ---------- log sources ----------
$log_sources = [];

$ini_log = trim((string)ini_get('error_log'));
if ($ini_log !== '' && $ini_log !== 'syslog') {
  $log_sources[] = ['label'=>'PHP error_log (ini)', 'path'=>$ini_log];
}
foreach ([
  ['Nginx error', '/var/log/nginx/error.log'],
  ['Apache error', '/var/log/apache2/error.log'],
  ['Apache error (CentOS)', '/var/log/httpd/error_log'],
  ['PHP-FPM', '/var/log/php8.3-fpm.log'],
  ['PHP-FPM', '/var/log/php8.2-fpm.log'],
  ['PHP-FPM', '/var/log/php8.1-fpm.log'],
  ['PHP-FPM', '/var/log/php-fpm.log'],
  ['Panel storage log', $panel_root . '/storage/panel.log'],
  ['Panel storage logs', $panel_root . '/storage/logs/panel.log'],
] as $d) {
  $log_sources[] = ['label'=>$d[0], 'path'=>$d[1]];
}

// Merge custom JSON list: [{"label":"...","path":"..."}, ...]
if (trim($log_sources_json) !== '') {
  $dec = json_decode($log_sources_json, true);
  if (is_array($dec)) {
    foreach ($dec as $r) {
      if (!is_array($r)) continue;
      $lab = trim((string)($r['label'] ?? 'Custom log'));
      $pth = trim((string)($r['path'] ?? ''));
      if ($pth === '') continue;
      $log_sources[] = ['label'=>$lab, 'path'=>$pth];
    }
  }
}

// Filter to safe + unique
$seen = [];
$safe_sources = [];
foreach ($log_sources as $s) {
  $p = (string)$s['path'];
  if ($p === '') continue;
  $rp = realpath($p);
  if (!$rp) continue;
  if (!sh_is_safe_log_path($p, $panel_root)) continue;
  if (!is_file($rp) || !is_readable($rp)) continue;
  if (isset($seen[$rp])) continue;
  $seen[$rp] = true;
  $safe_sources[] = ['label'=>(string)$s['label'], 'path'=>$rp];
}

// ---------- log viewer state ----------
$log_path = (string)($_GET['log'] ?? '');
$sev = (string)($_GET['sev'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$n = (int)($_GET['n'] ?? 200);
if ($n < 20) $n = 200;
if ($n > 2000) $n = 2000;

$active_log = '';
if ($log_path !== '') {
  $rp = realpath($log_path);
  if ($rp && is_file($rp) && is_readable($rp)) {
    // Must match one of the safe sources.
    foreach ($safe_sources as $s) {
      if ($s['path'] === $rp) { $active_log = $rp; break; }
    }
  }
}
if ($active_log === '' && !empty($safe_sources)) {
  $active_log = $safe_sources[0]['path'];
}

$log_lines = $active_log ? sh_tail_file($active_log, $n) : [];
$log_rows = [];
foreach ($log_lines as $line) {
  $sv = sh_line_severity($line);
  if ($sev !== '' && $sev !== 'all') {
    if ($sv !== $sev) continue;
  }
  if ($q !== '' && stripos($line, $q) === false) continue;
  $log_rows[] = ['sev'=>$sv, 'line'=>$line];
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
  <title>System Health</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
  <style>
    .sh-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);font-weight:700;font-size:12px;}
    .sh-pill.ok{border-color:rgba(22,163,74,.35)}
    .sh-pill.warn{border-color:rgba(245,158,11,.35)}
    .sh-pill.crit{border-color:rgba(239,68,68,.35)}
    .sh-pill.na{border-color:rgba(148,163,184,.25);opacity:.8}
    .sh-kv{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px}
    .sh-kv .kv{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px;min-width:200px}
    .sh-kv .k{opacity:.75;font-size:12px}
    .sh-kv .v{font-size:18px;font-weight:800;margin-top:2px}
    .sh-alert{border-radius:14px;padding:10px 12px;margin-top:10px;border:1px solid rgba(239,68,68,.25);background:rgba(239,68,68,.08)}
    .sh-alert.warn{border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.08)}
    .sh-logline{white-space:pre-wrap;word-break:break-word;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;font-size:12px;line-height:1.35}
    .sh-table td{vertical-align:top}
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>System Health Dashboard</h2>
  <?php flash_show(); ?>

  <?php if (!empty($alerts)): ?>
    <?php
      $hasCrit = false;
      foreach ($alerts as $a) { if (stripos($a, '(CRIT)') !== false) { $hasCrit = true; break; } }
    ?>
    <div class="sh-alert <?= $hasCrit ? '' : 'warn' ?>">
      <div style="font-weight:800;margin-bottom:4px;">Alerts</div>
      <div class="muted" style="opacity:.95;">
        <?= e(implode(' • ', array_slice($alerts, 0, 8))) ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="row" style="margin-top:12px; align-items:stretch;">
    <div class="box" style="flex:1; min-width:220px;">
      <div class="badge">CPU</div>
      <div style="font-size:22px;font-weight:900;margin-top:6px;" id="shCpu"><?= e(isset($load[0]) ? (string)$load[0] : '-') ?></div>
      <div class="muted">Load avg (1m)</div>
      <div class="muted" style="margin-top:4px;">5m: <span id="shCpu5"><?= e(isset($load[1]) ? (string)$load[1] : '-') ?></span> • 15m: <span id="shCpu15"><?= e(isset($load[2]) ? (string)$load[2] : '-') ?></span></div>
    </div>

    <div class="box" style="flex:1; min-width:220px;">
      <div class="badge">RAM</div>
      <div style="font-size:22px;font-weight:900;margin-top:6px;" id="shRamPct"><?= $mem['ok'] ? e((string)$mem['pct']) . '%' : '-' ?></div>
      <div class="muted">Used</div>
      <div class="muted" style="margin-top:4px;" id="shRamDetail"><?php if ($mem['ok']): ?><?= e(sh_bytes_to_human((int)$mem['used'])) ?> / <?= e(sh_bytes_to_human((int)$mem['total'])) ?><?php else: ?>-<?php endif; ?></div>
    </div>

    <div class="box" style="flex:1; min-width:220px;">
      <div class="badge">Disk</div>
      <div style="font-size:22px;font-weight:900;margin-top:6px;" id="shDiskPct"><?= $disk_pct !== null ? e((string)$disk_pct) . '%' : '-' ?></div>
      <div class="muted">Used</div>
      <div class="muted" style="margin-top:4px;" id="shDiskDetail"><?php if ($disk_total && $disk_used !== null): ?><?= e(sh_bytes_to_human((int)$disk_used)) ?> / <?= e(sh_bytes_to_human((int)$disk_total)) ?><?php else: ?>-<?php endif; ?></div>
    </div>

    <div class="box" style="flex:1; min-width:220px;">
      <div class="badge">DB</div>
      <div style="font-size:22px;font-weight:900;margin-top:6px;" id="shDbStatus"><?= $db_ok ? 'OK' : 'FAIL' ?></div>
      <div class="muted">Connection</div>
      <div class="muted" style="margin-top:4px;">Threads: <span id="shDbThreads"><?= e($db_info['threads'] ?: '-') ?></span> • Slow: <span id="shDbSlow"><?= e($db_info['slow_queries'] ?: '-') ?></span></div>
    </div>
  </div>

  <div class="muted" style="margin-top:10px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
    <span>Auto-refresh:</span>
    <label style="display:flex;align-items:center;gap:8px;margin:0;">
      <input type="checkbox" id="shAuto" checked>
      10s
    </label>
    <a class="btn" href="system_health.php" style="margin-left:auto;">Refresh page</a>
  </div>
</div>

<br>

<div class="card">
  <h2>Storage Usage</h2>
  <p class="muted" style="margin-top:-6px;">Warn at <code><?= e((string)$dir_warn_gb) ?>GB</code> • Crit at <code><?= e((string)$dir_crit_gb) ?>GB</code> (per directory)</p>

  <table class="sh-table">
    <tr><th>Item</th><th>Path</th><th>Size</th><th>Status</th><th>Method</th></tr>
    <?php foreach ($storage_rows as $r): ?>
      <?php
        $pill = $r['status'];
        $label = strtoupper($pill);
        if ($pill === 'ok') $label = 'OK';
        if ($pill === 'na') $label = 'N/A';
      ?>
      <tr>
        <td style="font-weight:800;"><?= e($r['label']) ?></td>
        <td class="code" style="opacity:.9;"><?= e($r['path']) ?></td>
        <td><?= $r['ok'] ? e(sh_bytes_to_human((int)$r['bytes'])) : '-' ?></td>
        <td><span class="sh-pill <?= e($pill) ?>"><?= e($label) ?></span></td>
        <td class="muted"><?= e($r['method']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<br>

<div class="card">
  <h2>Thresholds</h2>
  <p class="muted" style="margin-top:-6px;">Simple defaults: keep it honest. No spam, just flags.</p>

  <form method="post" style="margin-top:12px;">
    <?= csrf_input() ?>
    <input type="hidden" name="save_health_settings" value="1">

    <div class="row">
      <div>
        <label>Disk WARN (%)</label>
        <input type="number" name="disk_warn_pct" value="<?= (int)$disk_warn ?>" min="1" max="99">
      </div>
      <div>
        <label>Disk CRIT (%)</label>
        <input type="number" name="disk_crit_pct" value="<?= (int)$disk_crit ?>" min="1" max="99">
      </div>
      <div>
        <label>Dir WARN (GB)</label>
        <input type="number" step="0.5" name="dir_warn_gb" value="<?= e((string)$dir_warn_gb) ?>" min="0.5">
      </div>
      <div>
        <label>Dir CRIT (GB)</label>
        <input type="number" step="0.5" name="dir_crit_gb" value="<?= e((string)$dir_crit_gb) ?>" min="0.5">
      </div>
    </div>

    <details style="margin-top:10px;">
      <summary style="cursor:pointer; font-weight:800;">Log sources (optional)</summary>
      <p class="muted" style="margin-top:8px;">JSON array: <code>[{"label":"Nginx error","path":"/var/log/nginx/error.log"}]</code></p>
      <textarea name="log_sources_json" style="width:100%;min-height:120px;" placeholder='[]'><?= e($log_sources_json) ?></textarea>
    </details>

    <button class="btn" type="submit" style="margin-top:10px;">Save</button>
  </form>
</div>

<br>

<div class="card">
  <h2>Error Log Viewer</h2>
  <p class="muted" style="margin-top:-6px;">Tail + search + severity. Only reads from safe, readable log paths.</p>

  <?php if (empty($safe_sources)): ?>
    <div class="muted">No readable log files found. Add one under <strong>Thresholds → Log sources</strong>.</div>
  <?php else: ?>
    <form method="get" style="margin-top:12px;">
      <div class="row" style="align-items:flex-end;">
        <div style="flex:1; min-width:240px;">
          <label>Log</label>
          <select name="log">
            <?php foreach ($safe_sources as $s): ?>
              <option value="<?= e($s['path']) ?>" <?= $active_log === $s['path'] ? 'selected' : '' ?>><?= e($s['label']) ?> — <?= e($s['path']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Severity</label>
          <select name="sev">
            <?php
              $sevs = ['all'=>'All','fatal'=>'Fatal','crit'=>'Crit','error'=>'Error','warn'=>'Warn','notice'=>'Notice','info'=>'Info'];
              foreach ($sevs as $k=>$v):
            ?>
              <option value="<?= e($k) ?>" <?= (($sev===''?'all':$sev) === $k) ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1; min-width:220px;">
          <label>Search</label>
          <input name="q" value="<?= e($q) ?>" placeholder="type to filter...">
        </div>
        <div>
          <label>Lines</label>
          <input type="number" name="n" value="<?= (int)$n ?>" min="20" max="2000">
        </div>
        <div>
          <button class="btn" type="submit">Load</button>
        </div>
      </div>
    </form>

    <div style="margin-top:10px;" class="muted">Showing <strong><?= (int)count($log_rows) ?></strong> lines from <code><?= e($active_log) ?></code></div>

    <table class="sh-table" style="margin-top:10px;">
      <tr><th style="width:110px;">Severity</th><th>Line</th></tr>
      <?php if (empty($log_rows)): ?>
        <tr><td colspan="2" class="muted" style="text-align:center;">No matches</td></tr>
      <?php else: ?>
        <?php foreach ($log_rows as $r): ?>
          <tr>
            <td>
              <span class="sh-pill <?= e(in_array($r['sev'],['fatal','crit']) ? 'crit' : ($r['sev']==='error'?'crit':($r['sev']==='warn'?'warn':'ok'))) ?>">
                <?= e(strtoupper($r['sev'])) ?>
              </span>
            </td>
            <td class="sh-logline"><?= e($r['line']) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </table>
  <?php endif; ?>
</div>

</div><!-- container -->
</main>
</div><!-- app -->

<script>
(function(){
  var auto = document.getElementById('shAuto');
  function setText(id, v){ var el = document.getElementById(id); if(el) el.textContent = v; }

  function humanBytes(bytes){
    bytes = Number(bytes||0);
    if(!isFinite(bytes) || bytes < 0) return '-';
    var units = ['B','KB','MB','GB','TB','PB'];
    var i = 0;
    var v = bytes;
    while(v >= 1024 && i < units.length-1){ v/=1024; i++; }
    var d = (v >= 10 || i===0) ? 1 : 2;
    return v.toFixed(d) + ' ' + units[i];
  }

  function tick(){
    fetch('ajax/system_health_live.php', { credentials:'same-origin', cache:'no-store' })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if(!data || !data.ok) return;
        if(data.load){
          setText('shCpu', data.load[0] != null ? String(data.load[0]) : '-');
          setText('shCpu5', data.load[1] != null ? String(data.load[1]) : '-');
          setText('shCpu15', data.load[2] != null ? String(data.load[2]) : '-');
        }
        if(data.mem && data.mem.ok){
          setText('shRamPct', String(data.mem.pct) + '%');
          setText('shRamDetail', humanBytes(data.mem.used) + ' / ' + humanBytes(data.mem.total));
        }
        if(data.disk && data.disk.ok){
          setText('shDiskPct', String(data.disk.pct) + '%');
          setText('shDiskDetail', humanBytes(data.disk.used) + ' / ' + humanBytes(data.disk.total));
        }
        if(data.db){
          setText('shDbStatus', data.db.ok ? 'OK' : 'FAIL');
          setText('shDbThreads', data.db.threads || '-');
          setText('shDbSlow', data.db.slow_queries || '-');
        }
      })
      .catch(function(){});
  }

  tick();
  setInterval(function(){ if(auto && auto.checked) tick(); }, 10000);
})();
</script>

</body>
</html>
