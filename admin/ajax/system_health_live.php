<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Admin only.
if (isset($_SESSION['reseller_id']) && empty($_SESSION['admin_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}
if (empty($_SESSION['admin_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

function sh_read_meminfo_live(): array {
  $p = '/proc/meminfo';
  if (!is_readable($p)) return ['ok'=>false];
  $raw = @file($p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$raw) return ['ok'=>false];
  $m = [];
  foreach ($raw as $line) {
    if (preg_match('/^([A-Za-z_]+):\s+(\d+)\s+kB$/', trim($line), $mm)) {
      $m[$mm[1]] = (int)$mm[2] * 1024;
    }
  }
  if (empty($m['MemTotal'])) return ['ok'=>false];
  $total = (int)$m['MemTotal'];
  $avail = (int)($m['MemAvailable'] ?? (($m['MemFree'] ?? 0) + ($m['Buffers'] ?? 0) + ($m['Cached'] ?? 0)));
  if ($avail < 0) $avail = 0;
  if ($avail > $total) $avail = $total;
  $used = $total - $avail;
  $pct = $total > 0 ? (int)round(($used / $total) * 100) : 0;
  return ['ok'=>true, 'total'=>$total, 'used'=>$used, 'avail'=>$avail, 'pct'=>$pct];
}

try {
  $panel_root = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');

  $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [];
  $mem = sh_read_meminfo_live();

  $disk_total = @disk_total_space($panel_root);
  $disk_free  = @disk_free_space($panel_root);
  $disk_used  = ($disk_total && $disk_free !== false) ? max(0, (int)$disk_total - (int)$disk_free) : null;
  $disk_pct   = ($disk_total && $disk_used !== null && (int)$disk_total > 0) ? (int)round(($disk_used / (int)$disk_total) * 100) : null;
  $disk = ['ok'=>false];
  if ($disk_total && $disk_used !== null && $disk_pct !== null) {
    $disk = ['ok'=>true, 'total'=>(int)$disk_total, 'used'=>(int)$disk_used, 'free'=>(int)$disk_free, 'pct'=>$disk_pct];
  }

  $db = ['ok'=>true, 'threads'=>'', 'slow_queries'=>''];
  try {
    $pdo = db();
    $pdo->query('SELECT 1');
    try {
      $st = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Slow_queries')");
      $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
      foreach ($rows as $r) {
        $k = strtolower((string)($r['Variable_name'] ?? ''));
        $v = (string)($r['Value'] ?? '');
        if ($k === 'threads_connected') $db['threads'] = $v;
        if ($k === 'slow_queries') $db['slow_queries'] = $v;
      }
    } catch (Throwable $e) {}
  } catch (Throwable $e) {
    $db = ['ok'=>false];
  }

  echo json_encode([
    'ok' => true,
    'ts' => gmdate('c'),
    'load' => $load,
    'mem' => $mem,
    'disk' => $disk,
    'db' => $db,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server_error']);
}
