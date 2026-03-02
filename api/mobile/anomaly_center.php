<?php
require_once __DIR__ . '/_common.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

$minutes = (int)($_GET['minutes'] ?? 180);
if ($minutes < 5) $minutes = 5;
if ($minutes > 10080) $minutes = 10080; // 7d

$min_ips = 3;
$min_fps = 4;
$min_err = 5;
$stream_thr = max(20, (int)floor($minutes * 0.2)); // ~12/hr baseline

// request_logs timestamp column compatibility: ts OR created_at
$tsCol = 'created_at';
try {
  $pdo->query("SELECT ts FROM request_logs LIMIT 1");
  $tsCol = 'ts';
} catch (Throwable $e) {
  $tsCol = 'created_at';
}

$tot_hits = 0;
$tot_bad  = 0;

try {
  $st = $pdo->prepare("\n    SELECT\n      COUNT(*) AS hits,\n      SUM(CASE WHEN IFNULL(reason,'ok') <> 'ok' OR IFNULL(status_code,200) >= 400 THEN 1 ELSE 0 END) AS bad_hits\n    FROM request_logs\n    WHERE $tsCol >= (NOW() - INTERVAL ? MINUTE)\n      AND user_id IS NOT NULL\n  ");
  $st->execute([$minutes]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $tot_hits = (int)($r['hits'] ?? 0);
  $tot_bad  = (int)($r['bad_hits'] ?? 0);
} catch (Throwable $e) {
  _gc_json(['ok'=>false,'error'=>'request_logs missing or DB error'], 500);
}

// Aggregate per user within window.
$rows = [];
try {
  $st = $pdo->prepare("\n    SELECT\n      agg.user_id,\n      u.username AS username,\n      u.reseller_id AS reseller_id,\n      r.username AS reseller_name,\n      agg.hits AS hits,\n      agg.unique_ips AS unique_ips,\n      agg.unique_fps AS unique_fps,\n      agg.error_hits AS error_hits,\n      agg.stream_starts AS stream_starts,\n      agg.first_seen AS first_seen,\n      agg.last_seen AS last_seen\n    FROM (\n      SELECT\n        user_id,\n        COUNT(*) AS hits,\n        COUNT(DISTINCT ip) AS unique_ips,\n        COUNT(DISTINCT device_fp) AS unique_fps,\n        SUM(CASE WHEN IFNULL(reason,'ok') <> 'ok' OR IFNULL(status_code,200) >= 400 THEN 1 ELSE 0 END) AS error_hits,\n        SUM(CASE WHEN endpoint='stream' THEN 1 ELSE 0 END) AS stream_starts,\n        MIN($tsCol) AS first_seen,\n        MAX($tsCol) AS last_seen\n      FROM request_logs\n      WHERE $tsCol >= (NOW() - INTERVAL ? MINUTE)\n        AND user_id IS NOT NULL\n      GROUP BY user_id\n      HAVING\n        unique_ips >= ?\n        OR unique_fps >= ?\n        OR error_hits >= ?\n        OR stream_starts >= ?\n      ORDER BY last_seen DESC\n      LIMIT 100\n    ) agg\n    LEFT JOIN users u ON u.id = agg.user_id\n    LEFT JOIN resellers r ON r.id = u.reseller_id\n    ORDER BY agg.last_seen DESC\n  ");
  $st->execute([$minutes, $min_ips, $min_fps, $min_err, $stream_thr]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $rows = [];
}

$out = [];
foreach ($rows as $r) {
  $uid = (int)($r['user_id'] ?? 0);
  if ($uid <= 0) continue;

  $uips = (int)($r['unique_ips'] ?? 0);
  $ufps = (int)($r['unique_fps'] ?? 0);
  $errs = (int)($r['error_hits'] ?? 0);
  $streams = (int)($r['stream_starts'] ?? 0);
  $hits = (int)($r['hits'] ?? 0);

  $reasons = [];
  if ($uips >= $min_ips) {
    $reasons[] = 'Multiple IPs (' . $uips . ')';
  }
  if ($ufps >= $min_fps) {
    $reasons[] = 'Multiple device fingerprints (' . $ufps . ')';
  }
  if ($errs >= $min_err) {
    $reasons[] = 'High error rate (' . $errs . ' errors)';
  }
  if ($streams >= $stream_thr) {
    $reasons[] = 'High stream starts (' . $streams . ')';
  }

  if (!$reasons) continue;

  $out[] = [
    'user_id' => $uid,
    'username' => (string)($r['username'] ?? ''),
    'reseller_id' => (int)($r['reseller_id'] ?? 0),
    'reseller_name' => (string)($r['reseller_name'] ?? ''),
    'hits' => $hits,
    'unique_ips' => $uips,
    'unique_fps' => $ufps,
    'error_hits' => $errs,
    'stream_starts' => $streams,
    'first_seen' => (string)($r['first_seen'] ?? ''),
    'last_seen' => (string)($r['last_seen'] ?? ''),
    'reasons' => $reasons,
  ];
}

_gc_json([
  'ok' => true,
  'minutes' => $minutes,
  'ts' => gmdate('c'),
  'thresholds' => [
    'min_ips' => $min_ips,
    'min_device_fps' => $min_fps,
    'min_errors' => $min_err,
    'stream_starts' => $stream_thr,
  ],
  'totals' => [
    'flagged' => count($out),
    'hits' => $tot_hits,
    'bad_hits' => $tot_bad,
  ],
  'rows' => $out,
]);
