<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

// Client sends last_id to avoid repeats
$last_id = (int)($_GET['last_id'] ?? 0);
$window = (int)($_GET['window'] ?? 10); // minutes
if ($window < 2) $window = 10;
if ($window > 120) $window = 120;

$alerts = [];
$now = time();
$window_key = (int)floor($now / 60 / $window); // changes every window minutes
$base_id = $window_key * 10; // space for multiple alerts per window

// 1) Anomaly spike (uses request_logs if available, else falls back)
$anom_count = 0;
try {
  $st = $pdo->prepare("SELECT COUNT(DISTINCT user_id) AS c
    FROM request_logs
    WHERE created_at >= (NOW() - INTERVAL ? MINUTE)
      AND user_id IS NOT NULL
      AND (IFNULL(reason,'ok') <> 'ok' OR IFNULL(status_code,200) >= 400)
  ");
  $st->execute([$window]);
  $anom_count = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
} catch (Throwable $e) {
  $anom_count = 0;
}

// Thresholds (tweak later)
$anom_threshold = 5;

if ($anom_count >= $anom_threshold) {
  $id = $base_id + 1;
  if ($id > $last_id) {
    $alerts[] = [
      'id' => $id,
      'type' => 'anomaly_spike',
      'title' => 'Anomaly spike',
      'body' => $anom_count . ' users flagged in last ' . $window . ' min',
      'sev' => 'warn',
      'ts' => date('c')
    ];
  }
}

// 2) Bad request spike
$bad = 0; $total = 0;
try {
  $st = $pdo->prepare("
    SELECT
      SUM(CASE WHEN IFNULL(reason,'ok') <> 'ok' OR IFNULL(status_code,200) >= 400 THEN 1 ELSE 0 END) AS bad,
      COUNT(*) AS total
    FROM request_logs
    WHERE created_at >= (NOW() - INTERVAL ? MINUTE)
  ");
  $st->execute([$window]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $bad = (int)($row['bad'] ?? 0);
  $total = (int)($row['total'] ?? 0);
} catch (Throwable $e) {
  $bad = 0; $total = 0;
}

$bad_threshold = 50;
if ($bad >= $bad_threshold) {
  $id = $base_id + 2;
  if ($id > $last_id) {
    $alerts[] = [
      'id' => $id,
      'type' => 'badreq_spike',
      'title' => 'Bad requests spike',
      'body' => $bad . ' bad / ' . $total . ' total in last ' . $window . ' min',
      'sev' => 'warn',
      'ts' => date('c')
    ];
  }
}

_gc_json([
  'ok' => true,
  'items' => $alerts,
  'count' => count($alerts),
  'next_last_id' => count($alerts) ? max(array_column($alerts,'id')) : $last_id,
  'window_minutes' => $window
]);
