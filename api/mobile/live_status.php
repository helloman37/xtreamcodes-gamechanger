<?php
require_once __DIR__ . '/_common.php';

try {
  $pdo = db();
  $admin = _gc_mobile_require_admin($pdo);

  $online = [
    'sessions' => (int)$pdo->query("SELECT COUNT(*) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
    'users' => (int)$pdo->query("SELECT COUNT(DISTINCT user_id) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
    'connections' => (int)$pdo->query("SELECT COUNT(DISTINCT ip) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
  ];

  $minutes = 180;

  // request_logs timestamp column compatibility: ts OR created_at
  $tsCol = 'ts';
  try {
    $pdo->query("SELECT ts FROM request_logs LIMIT 1");
  } catch (Throwable $e) {
    $tsCol = 'created_at';
  }

  $st = $pdo->prepare("
    SELECT
      COUNT(*) AS hits,
      SUM(CASE WHEN IFNULL(reason,'ok') <> 'ok' OR IFNULL(status_code,200) >= 400 THEN 1 ELSE 0 END) AS bad
    FROM request_logs
    WHERE $tsCol >= (NOW() - INTERVAL ? MINUTE)
  ");
  $st->execute([$minutes]);
  $agg = $st->fetch(PDO::FETCH_ASSOC) ?: ['hits'=>0,'bad'=>0];

  $st2 = $pdo->prepare("
    SELECT COUNT(DISTINCT user_id) AS c
    FROM request_logs
    WHERE $tsCol >= (NOW() - INTERVAL ? MINUTE)
      AND (IFNULL(reason,'ok') <> 'ok' OR IFNULL(status_code,200) >= 400)
      AND user_id IS NOT NULL
  ");
  $st2->execute([$minutes]);
  $anomUsers = (int)($st2->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

  _gc_json([
    'ok' => true,
    'active_users' => (int)$online['users'],
    'active_sessions' => (int)$online['sessions'],
    'active_connections' => (int)$online['connections'],
    'anomaly_users' => $anomUsers,
    'requests_total' => (int)$agg['hits'],
    'requests_bad' => (int)$agg['bad'],
    'window_minutes' => $minutes
  ]);

} catch (Throwable $e) {
  _gc_json(['ok'=>false,'error'=>'server_error'], 500);
}
