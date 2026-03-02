<?php
// hero_wall.php
// Shared config + renderer for the sliding background wall used on home pages.

function gc_hero_wall_config_path(): string {
  return __DIR__ . '/cache/hero_wall.json';
}

function gc_hero_wall_upload_dir_fs(): string {
  return __DIR__ . '/uploads/hero_wall';
}

function gc_hero_wall_upload_dir_web(): string {
  return '/uploads/hero_wall';
}

/**
 * @return array{top: string[], mid: string[], bottom: string[]}
 */
function gc_hero_wall_get(): array {
  $path = gc_hero_wall_config_path();
  $cfg = ['top'=>[], 'mid'=>[], 'bottom'=>[]];
  if (!is_file($path)) return $cfg;
  $raw = @file_get_contents($path);
  if (!is_string($raw) || $raw === '') return $cfg;
  $json = json_decode($raw, true);
  if (!is_array($json)) return $cfg;

  foreach (['top','mid','bottom'] as $k) {
    $arr = $json[$k] ?? [];
    if (!is_array($arr)) $arr = [];
    $out = [];
    foreach ($arr as $v) {
      $v = trim((string)$v);
      if ($v === '') continue;
      // Keep as-is for absolute URLs; normalize local paths.
      if (!preg_match('~^https?://~i', $v) && $v[0] !== '/') $v = '/' . $v;
      $out[] = $v;
      if (count($out) >= 5) break;
    }
    $cfg[$k] = $out;
  }
  return $cfg;
}

function gc_hero_wall_tmdb_cache_path(): string {
  return __DIR__ . '/cache/hero_wall_tmdb.json';
}

function gc_hero_wall_tmdb_http_get(string $url, int $timeout = 10): array {
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
      CURLOPT_USERAGENT      => 'XTREAMui-GameChanger-HeroWall/1.0',
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
      'header' => "User-Agent: XTREAMui-GameChanger-HeroWall/1.0\r\n",
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

function gc_hero_wall_tmdb_image(?string $path, string $size = 'w780'): string {
  $p = trim((string)$path);
  if ($p === '') return '';
  if (str_starts_with($p, 'http')) return $p;
  return 'https://image.tmdb.org/t/p/' . $size . $p;
}

/**
 * Fallback images when admin didn't configure any.
 * Returns up to 15 TMDB backdrop images (split 5/5/5 across rows).
 * Cached on disk to avoid hitting TMDB on every page load.
 *
 * @return array{top: string[], mid: string[], bottom: string[]}
 */
function gc_hero_wall_tmdb_fallback(int $ttl = 21600): array {
  $empty = ['top'=>[], 'mid'=>[], 'bottom'=>[]];

  // Cache hit
  $cacheFile = gc_hero_wall_tmdb_cache_path();
  if ($ttl > 0 && is_file($cacheFile)) {
    $age = time() - (int)@filemtime($cacheFile);
    if ($age >= 0 && $age <= $ttl) {
      $raw = (string)@file_get_contents($cacheFile);
      $j = json_decode($raw, true);
      if (is_array($j) && isset($j['top'], $j['mid'], $j['bottom'])) {
        return [
          'top' => array_values(array_slice((array)$j['top'], 0, 5)),
          'mid' => array_values(array_slice((array)$j['mid'], 0, 5)),
          'bottom' => array_values(array_slice((array)$j['bottom'], 0, 5)),
        ];
      }
    }
  }

  // Resolve key from config.php (supports config.local.php overrides)
  $cfg = @require __DIR__ . '/config.php';
  if (!is_array($cfg)) $cfg = [];
  $key = trim((string)($cfg['tmdb_api_key'] ?? ''));
  if ($key === '') return $empty;

  $lang = (string)($cfg['tmdb_language'] ?? 'en-US');
  $region = (string)($cfg['tmdb_region'] ?? 'US');

  $base = 'https://api.themoviedb.org/3';
  // Trending all/week gives a mix of movies + TV.
  $qs = http_build_query([
    'api_key' => $key,
    'language' => $lang,
    'region' => $region,
    'include_adult' => 'false',
    'page' => 1,
  ]);
  $url = $base . '/trending/all/week?' . $qs;

  [$ok, $body] = gc_hero_wall_tmdb_http_get($url, 12);
  if (!$ok) return $empty;
  $j = json_decode($body, true);
  if (!is_array($j)) return $empty;

  $rows = (array)($j['results'] ?? []);
  $imgs = [];
  $seen = [];
  foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $p = (string)($r['backdrop_path'] ?? '');
    if ($p === '') $p = (string)($r['poster_path'] ?? '');
    if ($p === '') continue;
    if (isset($seen[$p])) continue;
    $seen[$p] = true;

    $img = gc_hero_wall_tmdb_image($p, 'w780');
    if ($img === '') continue;
    $imgs[] = $img;
    if (count($imgs) >= 15) break;
  }
  if (!$imgs) return $empty;

  // Ensure we have 15 by looping (so animations look consistent)
  $baseCount = max(1, count($imgs));
  while (count($imgs) < 15) {
    $imgs[] = $imgs[count($imgs) % $baseCount];
    if (count($imgs) > 30) break;
  }

  $out = [
    'top' => array_slice($imgs, 0, 5),
    'mid' => array_slice($imgs, 5, 5),
    'bottom' => array_slice($imgs, 10, 5),
  ];

  $dir = dirname($cacheFile);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($cacheFile, json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  return $out;
}

/**
 * Save config. Input arrays are truncated to 5 items and normalized.
 */
function gc_hero_wall_save(array $cfg): bool {
  $out = ['top'=>[], 'mid'=>[], 'bottom'=>[]];
  foreach (['top','mid','bottom'] as $k) {
    $arr = $cfg[$k] ?? [];
    if (!is_array($arr)) $arr = [];
    foreach ($arr as $v) {
      $v = trim((string)$v);
      if ($v === '') continue;
      if (!preg_match('~^https?://~i', $v) && $v[0] !== '/') $v = '/' . $v;
      $out[$k][] = $v;
      if (count($out[$k]) >= 5) break;
    }
  }

  $dir = dirname(gc_hero_wall_config_path());
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return (bool)@file_put_contents(gc_hero_wall_config_path(), json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

/**
 * Render the 3-row sliding wall. Returns empty string if no images configured.
 */
function gc_hero_wall_render(): string {
  $cfg = gc_hero_wall_get();
  $has = (bool)(count($cfg['top']) || count($cfg['mid']) || count($cfg['bottom']));
  if (!$has) {
    // If admin didn't configure any images, auto-populate from TMDB Trending.
    $cfg = gc_hero_wall_tmdb_fallback();
    $has = (bool)(count($cfg['top']) || count($cfg['mid']) || count($cfg['bottom']));
    if (!$has) return '';
  }

  // Helper: render a row with duplicated tracks for seamless looping.
  $row = function(string $rowName, array $imgs): string {
    if (!$imgs) return '';
    $safe = [];
    foreach ($imgs as $src) {
      $safe[] = '<div class="bgcell"><img class="bgimg" src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt=""></div>';
    }
    $cells = implode('', $safe);
    // Duplicate once to create a continuous loop.
    return '<div class="bgrow ' . htmlspecialchars($rowName, ENT_QUOTES) . '"><div class="bgtrack">' . $cells . $cells . '</div></div>';
  };

  return '<div class="bgwall" aria-hidden="true">'
    . $row('top', $cfg['top'])
    . $row('mid', $cfg['mid'])
    . $row('bottom', $cfg['bottom'])
    . '</div>';
}
