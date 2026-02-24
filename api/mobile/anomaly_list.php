<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

$minutes = (int)($_GET['minutes'] ?? 180);
if ($minutes < 5) $minutes = 5;
if ($minutes > 10080) $minutes = 10080;

$min_ips = 3;
$min_fps = 4;
$min_err = 5;
$stream_thr = max(20, (int)floor($minutes * 0.2));

try {
  $st = $pdo->prepare("
    SELECT
      agg.user_id,
      u.username AS username,
      COUNT(DISTINCT agg.ip) AS ip_count,
      COUNT(DISTINCT agg.fp) AS fp_count,
      SUM(agg.err) AS err_count,
      SUM(agg.hits) AS hits
    FROM (
      SELECT
        rl.user_id,
        rl.ip,
        IFNULL(rl.device_fp,'') AS fp,
        SUM(CASE WHEN IFNULL(rl.reason,'ok') <> 'ok' OR IFNULL(rl.status_code,200) >= 400 THEN 1 ELSE 0 END) AS err,
        COUNT(*) AS hits
      FROM request_logs rl
      WHERE rl.created_at >= (NOW() - INTERVAL ? MINUTE)
        AND rl.user_id IS NOT NULL
      GROUP BY rl.user_id, rl.ip, fp
    ) agg
    LEFT JOIN users u ON u.id = agg.user_id
    GROUP BY agg.user_id
    HAVING (ip_count >= ? OR fp_count >= ? OR err_count >= ? OR hits >= ?)
    ORDER BY (err_count*10 + ip_count*3 + fp_count*2 + hits) DESC
    LIMIT 200
  ");
  $st->execute([$minutes, $min_ips, $min_fps, $min_err, $stream_thr]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  _gc_json(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}

_gc_json(['ok'=>true,'items'=>$rows,'count'=>count($rows),'window_minutes'=>$minutes]);
