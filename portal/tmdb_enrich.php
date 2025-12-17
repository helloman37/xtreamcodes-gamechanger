<?php
// portal/tmdb_enrich.php
// On-demand TMDB enrichment used by portal/assets/portal.js.
// If no TMDB key is configured, this returns an empty payload (safe no-op).

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['store_user'])) {
  http_response_code(401);
  echo json_encode(['error'=>'not_logged_in']);
  exit;
}

$pdo = db();

$userId = is_array($_SESSION['store_user']) ? (int)($_SESSION['store_user']['id'] ?? 0) : (int)$_SESSION['store_user'];
$st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$user = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$kind = strtolower(trim((string)($_GET['kind'] ?? '')));
if (!in_array($kind, ['movie','series'], true)) {
  http_response_code(400);
  echo json_encode(['error'=>'bad_kind']);
  exit;
}

$ids = trim((string)($_GET['ids'] ?? ''));
if ($ids === '') {
  echo json_encode(['items'=>[]]);
  exit;
}

$idList = array_values(array_filter(array_map('intval', explode(',', $ids)), fn($x) => $x > 0));
if (!$idList) {
  echo json_encode(['items'=>[]]);
  exit;
}

// Resolve TMDB key (skip if missing)
function _portal_tmdb_key(PDO $pdo, array $user): string {
  $k = trim((string)($user['tmdb_api_key'] ?? ''));
  if ($k !== '') return $k;
  $k = trim((string)system_setting_get($pdo, 'tmdb_api_key', ''));
  if ($k !== '') return $k;
  $cfg = require __DIR__ . '/../config.php';
  return trim((string)($cfg['tmdb_api_key'] ?? ''));
}

$key = _portal_tmdb_key($pdo, $user);
if ($key === '') {
  // Key not set: safe no-op.
  echo json_encode(['items'=>[], 'tmdb'=>'missing_key']);
  exit;
}

$lang = trim((string)($user['tmdb_language'] ?? ''));
if ($lang === '') $lang = trim((string)system_setting_get($pdo, 'tmdb_language', 'en-US'));

$region = trim((string)($user['tmdb_region'] ?? ''));
if ($region === '') $region = trim((string)system_setting_get($pdo, 'tmdb_region', ''));

// Lightweight curl helper
function _http_get_json(string $url): ?array {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_TIMEOUT => 8,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_USERAGENT => 'XTREAM-ui-GameChanger/1.0',
    ]);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!$out || $code < 200 || $code >= 300) return null;
    $j = json_decode($out, true);
    return is_array($j) ? $j : null;
  }

  // Fallback
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 8,
      'header' => "User-Agent: XTREAM-ui-GameChanger/1.0\r\n",
    ]
  ]);
  $out = @file_get_contents($url, false, $ctx);
  if (!$out) return null;
  $j = json_decode($out, true);
  return is_array($j) ? $j : null;
}

// Cache dir (optional)
$cacheDir = __DIR__ . '/../cache/tmdb';
@mkdir($cacheDir, 0777, true);

function _cache_get(string $path, int $ttl=86400): ?array {
  if (!is_file($path)) return null;
  if (filemtime($path) < (time() - $ttl)) return null;
  $raw = @file_get_contents($path);
  if (!$raw) return null;
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}

function _cache_set(string $path, array $data): void {
  @file_put_contents($path, json_encode($data));
}

// Load internal records with tmdb_id
$table = ($kind === 'movie') ? 'movies' : 'series';
$idIn = implode(',', array_fill(0, count($idList), '?'));
if ($kind === 'movie') {
  $rows = $pdo->prepare("SELECT id, tmdb_id, poster_url, backdrop_url, plot, rating, release_date FROM movies WHERE id IN ({$idIn})");
} else {
  $rows = $pdo->prepare("SELECT id, tmdb_id, cover_url, backdrop_url, plot, rating, release_date FROM series WHERE id IN ({$idIn})");
}
$rows->execute($idList);
$map = [];
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $map[(int)$r['id']] = $r;
}

$items = [];

foreach ($idList as $internalId) {
  $r = $map[$internalId] ?? null;
  if (!$r) continue;
  $tmdbId = (int)($r['tmdb_id'] ?? 0);
  if ($tmdbId < 1) continue;

  $cachePath = $cacheDir . '/' . $kind . '_' . $tmdbId . '.json';
  $data = _cache_get($cachePath, 86400);
  if (!$data) {
    $endpoint = ($kind === 'movie') ? 'movie' : 'tv';
    $url = "https://api.themoviedb.org/3/{$endpoint}/{$tmdbId}?api_key=" . rawurlencode($key);
    if ($lang !== '') $url .= "&language=" . rawurlencode($lang);
    if ($region !== '') $url .= "&region=" . rawurlencode($region);
    $data = _http_get_json($url);
    if (!$data) continue;
    _cache_set($cachePath, $data);
  }

  $poster_path = (string)($data['poster_path'] ?? '');
  $backdrop_path = (string)($data['backdrop_path'] ?? '');
  $plot = (string)($data['overview'] ?? '');
  $rating = isset($data['vote_average']) ? (float)$data['vote_average'] : null;
  $release = (string)($data['release_date'] ?? ($data['first_air_date'] ?? ''));

  $posterUrl = $poster_path ? ('https://image.tmdb.org/t/p/w500' . $poster_path) : '';
  $backdropUrl = $backdrop_path ? ('https://image.tmdb.org/t/p/w1280' . $backdrop_path) : '';

  // Persist enrichment (only fill blanks)
  try {
    if ($kind === 'movie') {
      $pdo->prepare("UPDATE movies SET poster_url=COALESCE(NULLIF(poster_url,''),?), backdrop_url=COALESCE(NULLIF(backdrop_url,''),?), plot=COALESCE(NULLIF(plot,''),?), rating=COALESCE(rating,?), release_date=COALESCE(NULLIF(release_date,''),?) WHERE id=?")
        ->execute([$posterUrl ?: null, $backdropUrl ?: null, $plot ?: null, $rating, $release ?: null, $internalId]);
    } else {
      // series: cover_url is the poster
      $pdo->prepare("UPDATE series SET cover_url=COALESCE(NULLIF(cover_url,''),?), backdrop_url=COALESCE(NULLIF(backdrop_url,''),?), plot=COALESCE(NULLIF(plot,''),?), rating=COALESCE(rating,?), release_date=COALESCE(NULLIF(release_date,''),?) WHERE id=?")
        ->execute([$posterUrl ?: null, $backdropUrl ?: null, $plot ?: null, $rating, $release ?: null, $internalId]);
    }
  } catch (Throwable $e) {
    // ignore write errors
  }

  $items[] = [
    'id' => $internalId,
    'poster_url' => $posterUrl,
    'backdrop_url' => $backdropUrl,
    'plot' => $plot,
    'rating' => $rating,
    'release_date' => $release,
  ];
}

echo json_encode(['items'=>$items]);
