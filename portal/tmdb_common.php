<?php
// portal/tmdb_common.php
// Small TMDB client + caching helpers for the subscriber portal.

require_once __DIR__ . '/_init.php';

function portal_tmdb_cfg(PDO $pdo, array $user): array {
  $cfg = require __DIR__ . '/../config.php';
  return [
    'key'      => portal_tmdb_key($pdo, $user),
    'region'   => (string)($cfg['tmdb_region'] ?? 'US'),
    'language' => (string)($cfg['tmdb_language'] ?? 'en-US'),
  ];
}

function portal_tmdb_cache_dir(): string {
  $dir = __DIR__ . '/../cache/tmdb/api';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return $dir;
}

function portal_tmdb_http_get(string $url, int $timeout = 10): array {
  // Returns [ok(bool), body(string), err(string), status(int)]
  $status = 0;

  // Prefer curl if available.
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT        => $timeout,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT      => 'XTREAMui-GameChanger-Portal/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return [false, '', $err ?: 'curl_error', $status];
    return [($status >= 200 && $status < 300), (string)$body, ($status >= 200 && $status < 300) ? '' : ('http_' . $status), $status];
  }

  // Fallback: file_get_contents
  $ctx = stream_context_create([
    'http' => [
      'timeout' => $timeout,
      'ignore_errors' => true,
      'header' => "User-Agent: XTREAMui-GameChanger-Portal/1.0\r\n",
    ],
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
    ]
  ]);
  $body = @file_get_contents($url, false, $ctx);
  if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $h) {
      if (preg_match('~^HTTP/\S+\s+(\d+)~i', $h, $m)) { $status = (int)$m[1]; break; }
    }
  }
  if ($body === false) return [false, '', 'http_fetch_failed', $status];
  return [($status >= 200 && $status < 300), (string)$body, ($status >= 200 && $status < 300) ? '' : ('http_' . $status), $status];
}

function portal_tmdb_api(string $path, array $params, int $ttl = 3600): array {
  global $pdo, $user, $allowAdult;

  $cfg = portal_tmdb_cfg($pdo, $user);
  $key = trim((string)$cfg['key']);
  if ($key === '') {
    return ['ok' => false, 'error' => 'tmdb_key_missing', 'items' => []];
  }

  $base = 'https://api.themoviedb.org/3';
  $params['api_key'] = $key;
  $params['language'] = $params['language'] ?? $cfg['language'];
  $params['region']   = $params['region']   ?? $cfg['region'];
  if (!isset($params['include_adult'])) $params['include_adult'] = $allowAdult ? 'true' : 'false';

  $qs = http_build_query($params);
  $url = $base . $path . (str_contains($path, '?') ? '&' : '?') . $qs;

  $cacheKey = sha1($url);
  $cacheFile = portal_tmdb_cache_dir() . '/' . $cacheKey . '.json';
  if ($ttl > 0 && is_file($cacheFile)) {
    $age = time() - (int)filemtime($cacheFile);
    if ($age >= 0 && $age <= $ttl) {
      $raw = (string)@file_get_contents($cacheFile);
      $j = json_decode($raw, true);
      if (is_array($j)) return ['ok' => true, 'data' => $j, 'cached' => true];
    }
  }

  [$ok, $body, $err, $status] = portal_tmdb_http_get($url, 12);
  if (!$ok) {
    return ['ok' => false, 'error' => $err ?: 'tmdb_http_error', 'status' => $status];
  }
  $j = json_decode($body, true);
  if (!is_array($j)) {
    return ['ok' => false, 'error' => 'tmdb_bad_json'];
  }
  if ($ttl > 0) @file_put_contents($cacheFile, $body);
  return ['ok' => true, 'data' => $j, 'cached' => false];
}

function portal_tmdb_image(?string $path, string $size = 'w342'): string {
  $p = trim((string)$path);
  if ($p === '') return '';
  if (str_starts_with($p, 'http')) return $p;
  return 'https://image.tmdb.org/t/p/' . $size . $p;
}

function portal_tmdb_map_items(array $rows, string $forceType = ''): array {
  $out = [];
  foreach ($rows as $r) {
    $type = $forceType ?: (string)($r['media_type'] ?? '');
    if ($type === '') $type = !empty($r['first_air_date']) ? 'tv' : 'movie';

    $title = (string)($r['title'] ?? $r['name'] ?? '');
    $date  = (string)($r['release_date'] ?? $r['first_air_date'] ?? '');

    $out[] = [
      'tmdb_id' => (int)($r['id'] ?? 0),
      'type' => $type,
      'title' => $title,
      'plot' => (string)($r['overview'] ?? ''),
      'rating' => (string)($r['vote_average'] ?? ''),
      'year' => $date ? substr($date, 0, 4) : '',
      'poster_url' => portal_tmdb_image($r['poster_path'] ?? '', 'w342') ?: '/tv_icon.png',
      'backdrop_url' => portal_tmdb_image($r['backdrop_path'] ?? '', 'w780'),
    ];
  }
  return $out;
}

// Pick a single hero item (movie/tv/all) for a banner background.
// Returns: ['ok'=>bool, ...mapped fields]
function portal_tmdb_pick_hero(string $type = 'movie'): array {
  $type = in_array($type, ['movie','tv','all'], true) ? $type : 'movie';
  $r = portal_tmdb_api('/trending/' . $type . '/week', ['page' => 1], 1800);
  if (empty($r['ok'])) {
    return ['ok' => false, 'error' => (string)($r['error'] ?? 'tmdb_error')];
  }
  $rows = (array)($r['data']['results'] ?? []);
  $items = portal_tmdb_map_items($rows, $type === 'all' ? '' : $type);
  foreach ($items as $it) {
    if (!empty($it['backdrop_url'])) {
      return ['ok' => true] + $it;
    }
  }
  if (!empty($items[0])) return ['ok' => true] + (array)$items[0];
  return ['ok' => false, 'error' => 'tmdb_empty'];
}
