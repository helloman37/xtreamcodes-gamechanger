<?php
require_once __DIR__ . '/../../api_common.php';
require_once __DIR__ . '/../../auth.php';
require_admin();

header('Content-Type: application/json');

$pdo = db();

function _lm_normalize_ip(string $raw): string {
  $raw = trim($raw);
  if ($raw === '') return '';
  // Take first from XFF list
  if (strpos($raw, ',') !== false) $raw = trim(explode(',', $raw, 2)[0]);
  // Strip [IPv6]:port
  if (preg_match('/^\[(.+)\]:(\d+)$/', $raw, $m)) $raw = $m[1];
  // Strip IPv4:port
  if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):(\d+)$/', $raw, $m)) $raw = $m[1];
  $raw = trim($raw);
  return (filter_var($raw, FILTER_VALIDATE_IP)) ? $raw : '';
}

function _lm_is_public_ip(string $ip): bool {
  if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
  // Exclude private + reserved
  return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function _lm_ensure_cache_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
      ip VARCHAR(45) PRIMARY KEY,
      lat DOUBLE NULL,
      lon DOUBLE NULL,
      city VARCHAR(120) NULL,
      region VARCHAR(120) NULL,
      country VARCHAR(120) NULL,
      isp VARCHAR(190) NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'new',
      message VARCHAR(255) NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function _lm_cache_get(PDO $pdo, string $ip): ?array {
  try {
    $st = $pdo->prepare("SELECT * FROM ip_geo_cache WHERE ip=? LIMIT 1");
    $st->execute([$ip]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function _lm_cache_put(PDO $pdo, string $ip, array $geo): void {
  try {
    $st = $pdo->prepare("INSERT INTO ip_geo_cache (ip,lat,lon,city,region,country,isp,status,message,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE
        lat=VALUES(lat), lon=VALUES(lon), city=VALUES(city), region=VALUES(region), country=VALUES(country), isp=VALUES(isp),
        status=VALUES(status), message=VALUES(message), updated_at=NOW()" );
    $st->execute([
      $ip,
      $geo['lat'] ?? null,
      $geo['lon'] ?? null,
      $geo['city'] ?? null,
      $geo['region'] ?? null,
      $geo['country'] ?? null,
      $geo['isp'] ?? null,
      $geo['status'] ?? 'fail',
      $geo['message'] ?? null,
    ]);
  } catch (Throwable $e) {
    // ignore cache write errors
  }
}

function _lm_geo_lookup_ipwhois(string $ip): array {
  $url = 'https://ipwho.is/' . rawurlencode($ip);
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => 4,
      'header' => "User-Agent: XTREAMui/1.0\r\n",
    ],
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
    ]
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  if (!$raw) return ['status'=>'fail','message'=>'lookup_failed'];
  $j = json_decode($raw, true);
  if (!is_array($j)) return ['status'=>'fail','message'=>'bad_json'];
  if (!empty($j['success'])) {
    return [
      'status' => 'ok',
      'lat' => $j['latitude'] ?? null,
      'lon' => $j['longitude'] ?? null,
      'city' => $j['city'] ?? null,
      'region' => $j['region'] ?? null,
      'country' => $j['country'] ?? null,
      'isp' => $j['isp'] ?? null,
      'message' => null,
    ];
  }
  return ['status'=>'fail','message'=> (string)($j['message'] ?? 'not_success')];
}

$window = (int)($_GET['window'] ?? 300);
if ($window < 10) $window = 10;
if ($window > 3600) $window = 3600;

$type = trim((string)($_GET['type'] ?? ''));
if ($type !== '' && !in_array($type, ['live','vod','series'], true)) $type = '';

// Make sure cache table exists.
try { _lm_ensure_cache_table($pdo); } catch (Throwable $e) {}

// Fetch sessions.
$rows = [];
$sessions = 0;
try {
  // Prefer the newer schema if available.
  $sql = "SELECT ip, stream_type, item_id, user_id, channel_id, last_seen
          FROM stream_sessions
          WHERE last_seen >= (NOW() - INTERVAL ? SECOND)
            AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')";
  $params = [$window];
  if ($type !== '') { $sql .= " AND stream_type=?"; $params[] = $type; }
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // Fallback to older schema.
  try {
    $sql = "SELECT ip, user_id, channel_id, last_seen
            FROM stream_sessions
            WHERE last_seen >= (NOW() - INTERVAL ? SECOND)";
    $st = $pdo->prepare($sql);
    $st->execute([$window]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e2) {
    echo json_encode(['ok'=>false,'error'=>'query_failed']);
    exit;
  }
}

$sessions = count($rows);

$sessions = count($rows);

// Pins per session (connection).
$pins = [];
$lookups = 0;

// Cache TTLs
$ttl_ok = 7 * 24 * 3600;
$ttl_fail = 24 * 3600;

foreach ($rows as $r) {

  $ip = _lm_normalize_ip((string)($r['ip'] ?? ''));
  if ($ip === '') continue;

  // Private/reserved IPs cannot be geolocated.
  if (!_lm_is_public_ip($ip)) continue;

  $geo = _lm_cache_get($pdo, $ip);
  $stale = true;

  if ($geo && !empty($geo['updated_at'])) {
    $ts = strtotime((string)$geo['updated_at']);
    if ($ts) {
      $age = time() - $ts;
      if (($geo['status'] ?? '') === 'ok') $stale = ($age > $ttl_ok);
      else $stale = ($age > $ttl_fail);
    }
  }

  // Throttle live lookups.
  if ((!$geo || $stale) && $lookups < 40) {
    $liveGeo = _lm_geo_lookup_ipwhois($ip);
    $lookups++;
    _lm_cache_put($pdo, $ip, $liveGeo);
    $geo = array_merge($geo ?: [], $liveGeo);
  }

  $lat = isset($geo['lat']) ? (float)$geo['lat'] : null;
  $lon = isset($geo['lon']) ? (float)$geo['lon'] : null;

  if ($lat === null || $lon === null) continue;
  if (abs($lat) < 0.00001 && abs($lon) < 0.00001) continue;

  $t = (string)($r['stream_type'] ?? '');
  $pins[] = [
    'ip' => $ip,
    'lat' => $lat,
    'lon' => $lon,
    'city' => $geo['city'] ?? null,
    'region' => $geo['region'] ?? null,
    'country' => $geo['country'] ?? null,
    'isp' => $geo['isp'] ?? null,
    'sessions' => 1,
    'types' => ($t !== '' ? [ $t => 1 ] : []),
  ];
}

echo json_encode([
  'ok' => true,
  'ts' => gmdate('c'),
  'window' => $window,
  'stats' => [
    'pins' => count($pins),
    'ips' => $uniqueIps,
    'sessions' => $sessions,
    'lookups' => $lookups,
  ],
  'pins' => $pins,
]);
