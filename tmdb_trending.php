<?php
// tmdb_trending.php
// Public-safe TMDB Trending mix (movies + TV) for the storefront homepage carousel.
// Returns JSON and NEVER exposes the TMDB API key.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Helpers (HTTP + image URL builder)
require_once __DIR__ . '/hero_wall.php';

function gc_tmdb_trending_cache_file(): string {
  $dir = __DIR__ . '/cache/tmdb';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir . '/trending_all_day.json';
}

function gc_tmdb_trending_map(array $rows, int $limit): array {
  $out = [];
  $seen = [];

  foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $type = (string)($r['media_type'] ?? '');
    if ($type === '') $type = !empty($r['first_air_date']) ? 'tv' : 'movie';
    if ($type !== 'movie' && $type !== 'tv') continue;

    $id = (int)($r['id'] ?? 0);
    if ($id <= 0) continue;
    $key = $type . ':' . $id;
    if (isset($seen[$key])) continue;
    $seen[$key] = true;

    $title = (string)($r['title'] ?? $r['name'] ?? '');
    $date  = (string)($r['release_date'] ?? $r['first_air_date'] ?? '');

    $posterPath = (string)($r['poster_path'] ?? '');
    $backdropPath = (string)($r['backdrop_path'] ?? '');

    $posterUrl = $posterPath !== '' ? gc_hero_wall_tmdb_image($posterPath, 'w342') : '';
    if ($posterUrl === '' && $backdropPath !== '') $posterUrl = gc_hero_wall_tmdb_image($backdropPath, 'w342');

    $tmdbUrl = 'https://www.themoviedb.org/' . ($type === 'tv' ? 'tv' : 'movie') . '/' . $id;

    $out[] = [
      'tmdb_id' => $id,
      'type' => $type,
      'title' => $title,
      'year' => $date ? substr($date, 0, 4) : '',
      'rating' => (string)($r['vote_average'] ?? ''),
      'poster_url' => $posterUrl ?: '/tv_icon.png',
      'tmdb_url' => $tmdbUrl,
    ];

    if (count($out) >= $limit) break;
  }

  return $out;
}

try {
  $cfg = @require __DIR__ . '/config.php';
  if (!is_array($cfg)) $cfg = [];

  $key = trim((string)($cfg['tmdb_api_key'] ?? ''));
  if ($key === '') {
    echo json_encode(['ok' => false, 'error' => 'tmdb_key_missing', 'items' => []]);
    exit;
  }

  $limit = (int)($_GET['limit'] ?? 24);
  if ($limit < 6) $limit = 6;
  if ($limit > 36) $limit = 36;

  $ttl = 900; // 15 minutes
  $cacheFile = gc_tmdb_trending_cache_file();
  if (is_file($cacheFile)) {
    $age = time() - (int)@filemtime($cacheFile);
    if ($age >= 0 && $age <= $ttl) {
      $raw = (string)@file_get_contents($cacheFile);
      $j = json_decode($raw, true);
      if (is_array($j) && isset($j['results']) && is_array($j['results'])) {
        $items = gc_tmdb_trending_map((array)$j['results'], $limit);
        echo json_encode(['ok' => true, 'cached' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
        exit;
      }
    }
  }

  $lang = (string)($cfg['tmdb_language'] ?? 'en-US');
  $region = (string)($cfg['tmdb_region'] ?? 'US');

  $base = 'https://api.themoviedb.org/3';
  // "Trending now" - day endpoint gives freshest mix; keep cached to reduce calls.
  $qs = http_build_query([
    'api_key' => $key,
    'language' => $lang,
    'region' => $region,
    'include_adult' => 'false',
    'page' => 1,
  ]);
  $url = $base . '/trending/all/day?' . $qs;

  [$ok, $body, $err] = gc_hero_wall_tmdb_http_get($url, 12);
  if (!$ok || !is_string($body) || $body === '') {
    echo json_encode(['ok' => false, 'error' => $err ?: 'tmdb_http_error', 'items' => []]);
    exit;
  }

  $j = json_decode($body, true);
  if (!is_array($j) || !isset($j['results']) || !is_array($j['results'])) {
    echo json_encode(['ok' => false, 'error' => 'tmdb_bad_json', 'items' => []]);
    exit;
  }

  // Cache raw results (no key in here)
  @file_put_contents($cacheFile, json_encode(['results' => (array)$j['results']], JSON_UNESCAPED_SLASHES));

  $items = gc_tmdb_trending_map((array)$j['results'], $limit);
  echo json_encode(['ok' => true, 'cached' => false, 'items' => $items], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'server_error', 'items' => []]);
}
