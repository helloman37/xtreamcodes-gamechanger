<?php
require_once __DIR__ . '/../../api_common.php';
require_once __DIR__ . '/../../auth.php';
require_admin();

$pdo = db();

$hours = (int)($_GET['hours'] ?? 24);
if ($hours < 1) $hours = 24;
if ($hours > 720) $hours = 720;

$bucket = (int)($_GET['bucket'] ?? 5); // minutes
if ($bucket < 1) $bucket = 5;
if ($bucket > 60) $bucket = 60;

// --- Active sessions time series (bucketed by last_seen)
$st = $pdo->prepare("
  SELECT
    DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(ss.last_seen)/(?*60))*(?*60)), '%Y-%m-%d %H:%i') AS t,
    COUNT(*) AS c
  FROM stream_sessions ss
  WHERE ss.last_seen >= (NOW() - INTERVAL ? HOUR)
  GROUP BY t
  ORDER BY t ASC
");
$st->execute([$bucket, $bucket, $hours]);
$sess_rows = $st->fetchAll(PDO::FETCH_ASSOC);

// --- Request logs time series (total + bad)
$st = $pdo->prepare("
  SELECT
    DATE_FORMAT(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(rl.created_at)/(?*60))*(?*60)), '%Y-%m-%d %H:%i') AS t,
    COUNT(*) AS total,
    SUM(CASE WHEN IFNULL(rl.reason,'ok') <> 'ok' OR IFNULL(rl.status_code,200) >= 400 THEN 1 ELSE 0 END) AS bad
  FROM request_logs rl
  WHERE rl.created_at >= (NOW() - INTERVAL ? HOUR)
  GROUP BY t
  ORDER BY t ASC
");
$st->execute([$bucket, $bucket, $hours]);
$req_rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Build a unified label axis (so charts align)
$labels = [];
$sess_map = [];
$req_total_map = [];
$req_bad_map = [];

foreach ($sess_rows as $r) {
  $t = (string)$r['t'];
  $labels[$t] = 1;
  $sess_map[$t] = (int)$r['c'];
}
foreach ($req_rows as $r) {
  $t = (string)$r['t'];
  $labels[$t] = 1;
  $req_total_map[$t] = (int)$r['total'];
  $req_bad_map[$t] = (int)$r['bad'];
}

ksort($labels);
$labels = array_keys($labels);

$sessions = [];
$req_total = [];
$req_bad = [];
foreach ($labels as $t) {
  $sessions[] = (int)($sess_map[$t] ?? 0);
  $req_total[] = (int)($req_total_map[$t] ?? 0);
  $req_bad[]   = (int)($req_bad_map[$t] ?? 0);
}

// --- Device breakdown (based on user_agent from stream_sessions)
function ua_bucket(string $ua): string {
  $u = strtolower($ua);
  if ($u === '') return 'Unknown';
  if (strpos($u, 'aft') !== false || strpos($u, 'fire') !== false) return 'Fire TV';
  if (strpos($u, 'android tv') !== false || strpos($u, 'androidtv') !== false) return 'Android TV';
  if (strpos($u, 'android') !== false) return 'Android';
  if (strpos($u, 'iphone') !== false || strpos($u, 'ipad') !== false || strpos($u, 'ios') !== false) return 'iOS';
  if (strpos($u, 'tizen') !== false || strpos($u, 'webos') !== false || strpos($u, 'smart-tv') !== false) return 'Smart TV';
  if (strpos($u, 'vlc') !== false) return 'VLC';
  if (strpos($u, 'exoplayer') !== false) return 'ExoPlayer';
  if (strpos($u, 'windows') !== false || strpos($u, 'mac os') !== false || strpos($u, 'linux') !== false) return 'Desktop';
  if (strpos($u, 'mozilla') !== false || strpos($u, 'chrome') !== false || strpos($u, 'safari') !== false) return 'Web';
  return 'Other';
}

$st = $pdo->prepare("SELECT user_agent FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL ? HOUR)");
$st->execute([$hours]);
$device_counts = [];
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  $b = ua_bucket((string)($row['user_agent'] ?? ''));
  $device_counts[$b] = ($device_counts[$b] ?? 0) + 1;
}
arsort($device_counts);

$dev_labels = array_slice(array_keys($device_counts), 0, 10);
$dev_values = [];
foreach ($dev_labels as $k) $dev_values[] = (int)$device_counts[$k];

// --- Top reasons (request_logs)
$st = $pdo->prepare("
  SELECT IFNULL(reason,'ok') AS reason, COUNT(*) AS c
  FROM request_logs
  WHERE created_at >= (NOW() - INTERVAL ? HOUR)
  GROUP BY IFNULL(reason,'ok')
  ORDER BY c DESC
  LIMIT 10
");
$st->execute([$hours]);
$reasons_rows = $st->fetchAll(PDO::FETCH_ASSOC);
$reason_labels = [];
$reason_values = [];
foreach ($reasons_rows as $r) {
  $reason_labels[] = (string)$r['reason'];
  $reason_values[] = (int)$r['c'];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'hours' => $hours,
  'bucket_minutes' => $bucket,
  'labels' => $labels,
  'series' => [
    'active_sessions' => $sessions,
    'requests_total' => $req_total,
    'requests_bad' => $req_bad,
  ],
  'devices' => [
    'labels' => $dev_labels,
    'values' => $dev_values,
  ],
  'reasons' => [
    'labels' => $reason_labels,
    'values' => $reason_values,
  ],
], JSON_UNESCAPED_SLASHES);
