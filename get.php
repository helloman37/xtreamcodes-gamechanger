<?php
require_once __DIR__ . '/api_common.php';
require_once __DIR__ . '/email_lib.php';

$config   = require __DIR__ . '/config.php';
$base_url = rtrim($config['base_url'], '/');

// CORS for browser-based webplayers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

// Derive site root URL (base_url may include /public)
$site_url = rtrim($base_url, '/');
if (preg_match('~/public$~', $site_url)) {
  $site_url = preg_replace('~/public$~', '', $site_url);
}

$ttl      = (int)($config['token_ttl'] ?? 3600);

$username = trim($_GET['username'] ?? '');
$password = (string)($_GET['password'] ?? '');
// Default to m3u_plus so VOD/Series are included even if a client uses the legacy
// get.php URL without specifying &type=...
$type     = strtolower($_GET['type'] ?? 'm3u_plus');
// Backwards compat: many apps/providers still send type=m3u; treat it as m3u_plus.
// Use type=live if you explicitly want Live-only.
if ($type === 'm3u') {
  $type = 'm3u_plus';
}

// auto|direct_protected|standard_protected|token_protected
$link_type = strtolower($_GET['link'] ?? 'token_protected');

// Request telemetry (admin -> Telemetry)
telemetry_init('get', $type);
telemetry_meta(['link'=>$link_type]);
// Capture attempted username even before auth so Telemetry can show who is hitting get.php.
// (Avoids logging raw password/querystring.)
if (function_exists('telemetry_set_username')) {
  telemetry_set_username($username);
}

// -----------------------------------------------------------------------------
// Optional fail-video redirect (System -> Fail Videos).
// NOTE: Some IPTV apps expect get.php to return text/HTTP errors during handshake.
// This build defaults to redirecting to Fail Videos when configured.
// Set config.local.php:  'enable_get_fail_videos' => false  to force legacy text errors.
$ENABLE_GET_FAIL_VIDEOS = (bool)($config['enable_get_fail_videos'] ?? true);

// For get.php, we only redirect on non-config requests, since config expects JSON.
// Kind mapping: m3u_plus => vod, otherwise live.
// -----------------------------------------------------------------------------
function _get_fail_kind(string $type): string {
  return ($type === 'm3u_plus') ? 'vod' : 'live';
}
function _get_fail_video_url(PDO $pdo, string $kind, string $reason): string {
  // Prefer exact kind, but fall back to the other kind if not set.
  $primary = (string)system_setting_get($pdo, "fail_video_{$kind}_{$reason}", '');
  if ($primary !== '') return $primary;
  $other = ($kind === 'live') ? 'vod' : 'live';
  return (string)system_setting_get($pdo, "fail_video_{$other}_{$reason}", '');
}
function _get_redirect_fail_video(string $url): void {
  http_response_code(302);
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Location: ' . $url);
  exit;
}

if ($username === '' || $password === '') {
  telemetry_reason('missing_credentials');
  http_response_code(400);
  echo "Missing username or password";
  exit;
}

$pdo = db();

// -----------------------------------------------------------------------------
// Load balancers (reverse proxies) for protected stream links.
// If no enabled LBs exist, we fall back to $site_url automatically.
// This does NOT affect direct_play links (upstream URLs) when link=auto.
// -----------------------------------------------------------------------------
function _lb_cache_get(string $key, bool &$hit): ?string {
  $hit = false;
  if (function_exists('apcu_fetch')) {
    $ok = false;
    $val = apcu_fetch($key, $ok);
    if ($ok) { $hit = true; return is_string($val) ? $val : null; }
  }
  $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
  if (is_file($file) && (time() - (int)@filemtime($file) < 60)) {
    $hit = true;
    return (string)@file_get_contents($file);
  }
  return null;
}

function _lb_cache_set(string $key, string $val): void {
  if (function_exists('apcu_store')) {
    @apcu_store($key, $val, 60);
    return;
  }
  $file = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $key . '.json';
  @file_put_contents($file, $val, LOCK_EX);
}

function _lb_active_pool(PDO $pdo): array {
  $key = 'iptv_lb_pool';
  $hit = false;
  $cached = _lb_cache_get($key, $hit);
  if ($hit && $cached !== null) {
    $arr = json_decode($cached, true);
    if (is_array($arr)) return $arr;
  }

  $rows = [];
  try {
    $rows = $pdo->query("SELECT base_url, weight FROM lb_servers WHERE enabled=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $rows = [];
  }

  $pool = [];
  foreach ($rows as $r) {
    $u = rtrim(trim((string)($r['base_url'] ?? '')), '/');
    if ($u === '') continue;
    $w = (int)($r['weight'] ?? 1);
    if ($w < 1) $w = 1;
    // weight by duplication (simple + fast)
    for ($i=0; $i<$w; $i++) $pool[] = $u;
  }

  _lb_cache_set($key, json_encode($pool, JSON_UNESCAPED_SLASHES));
  return $pool;
}

function _stream_site_url(PDO $pdo, string $fallback, string $username): string {
  $lbs = _lb_active_pool($pdo);
  if (!$lbs) return $fallback;

  // Manual override for testing: &lb=1 (or 2/3/etc)
  $force = (int)($_GET['lb'] ?? 0);
  if ($force >= 1 && $force <= count($lbs)) {
    return $lbs[$force - 1];
  }

  $idx = (int)(sprintf('%u', crc32($username)) % count($lbs));
  return $lbs[$idx];
}

$stream_site_url = _stream_site_url($pdo, $site_url, $username);

// Global maintenance mode
$maint_enabled = system_setting_get($pdo, 'maintenance_mode', '0') === '1';
$maint_streams = (system_setting_get($pdo, 'maintenance_streams_mode', '0') === '1');
$maint_msg = system_setting_get(
  $pdo,
  'maintenance_message',
  'Service is temporarily under maintenance. Please try again later.'
);
$maint_video = trim((string)system_setting_get($pdo, 'maintenance_video_url', ''));

// NOTE:
// Maintenance mode should NOT deliver live TV streams.
// We still allow playlist/API to load so apps can authenticate and list channels,
// but the actual live stream endpoints will be blocked/redirected (see stream/index.php).
// Also, during maintenance we MUST avoid leaking upstream URLs (direct_play) in the playlist.

ensure_categories($pdo);

/* user */
$st = $pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
$st->execute([$username]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
  telemetry_reason('auth_fail', ['username'=>$username]);
  // If configured, redirect to a custom fail video instead of returning text.
  if ($ENABLE_GET_FAIL_VIDEOS && $type !== 'config') {
    $kind = _get_fail_kind($type);
    $fv = _get_fail_video_url($pdo, $kind, 'invalid_login');
    if ($fv !== '') _get_redirect_fail_video($fv);
  }
  http_response_code(401);
  echo "Invalid credentials";
  exit;
}

telemetry_set_user((int)$user['id'], (string)$user['username']);

// If email verification is required, block playlist/API until verified.
try {
  if (gc_email_verification_required($pdo) && !gc_email_user_is_verified($user)) {
    $em = trim((string)($user['email'] ?? ''));
    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
      telemetry_reason('email_verification_required');
      if ($type === 'config') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['user_info'=>['auth'=>0],'error'=>'email_verification_required']);
        exit;
      }
      http_response_code(403);
      echo "Email verification required";
      exit;
    }
  }
} catch (Throwable $e) {}

// Policy: IP allow/deny
$ip = get_client_ip();
// Hard bans (IP)
$ban = abuse_ip_ban_lookup($pdo, $ip);
if ($ban) {
  audit_log('ban_block', (int)$user['id'], ['ban_type'=>'ip','ip'=>$ip]);
  telemetry_reason('banned_ip');
  if ($ENABLE_GET_FAIL_VIDEOS && $type !== 'config') {
    $kind = _get_fail_kind($type);
    $fv = _get_fail_video_url($pdo, $kind, 'banned');
    if ($fv !== '') _get_redirect_fail_video($fv);
  }
  http_response_code(403);
  echo "Banned";
  exit;
}

// Hard bans (user)
$ban = abuse_user_ban_lookup($pdo, (int)$user['id']);
if ($ban) {
  audit_log('ban_block_user', (int)$user['id'], ['ban_type'=>'user','ip'=>$ip]);
  telemetry_reason('banned_user');
  if ($ENABLE_GET_FAIL_VIDEOS && $type !== 'config') {
    $kind = _get_fail_kind($type);
    $fv = _get_fail_video_url($pdo, $kind, 'banned');
    if ($fv !== '') _get_redirect_fail_video($fv);
  }
  http_response_code(403);
  echo "Banned";
  exit;
}

if (!ip_allowed($ip, $user['ip_allowlist'] ?? null, $user['ip_denylist'] ?? null)) {
  telemetry_reason('ip_not_allowed');
  if ($ENABLE_GET_FAIL_VIDEOS && $type !== 'config') {
    $kind = _get_fail_kind($type);
    $fv = _get_fail_video_url($pdo, $kind, 'banned');
    if ($fv !== '') _get_redirect_fail_video($fv);
  }
  http_response_code(403);
  echo "IP not allowed";
  exit;
}

/* subscription+plan */
$st = $pdo->prepare("
  SELECT s.*, p.max_streams
  FROM subscriptions s
  JOIN plans p ON p.id=s.plan_id
  WHERE s.user_id=? AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())
  ORDER BY s.ends_at DESC LIMIT 1
");
$st->execute([(int)$user['id']]);
$sub = $st->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
  telemetry_reason('no_subscription');
  if ($ENABLE_GET_FAIL_VIDEOS && $type !== 'config') {
    $kind = _get_fail_kind($type);
    $fv = _get_fail_video_url($pdo, $kind, 'expired');
    if ($fv !== '') _get_redirect_fail_video($fv);
  }
  http_response_code(403);
  echo "No active subscription";
  exit;
}

// Token expiry: keep tokens valid for the duration of the active subscription when possible.
// If the subscription has no end (lifetime) or parsing fails, fall back to token_ttl.
$now = time();
$exp_global = $now + $ttl;
if (!empty($sub['ends_at'])) {
  $ts = strtotime((string)$sub['ends_at']);
  if ($ts !== false && $ts > $now) {
    $exp_global = (int)$ts;
  }
}


/* app config */
$user_tmdb = $user['tmdb_api_key'] ?? '';
$user_logo = $user['app_logo_url'] ?? '';
$user_region = $user['tmdb_region'] ?? '';

$tmdb_api_key = $user_tmdb ?: ($config['tmdb_api_key'] ?? '');
$app_logo_url = $user_logo ?: ($config['app_logo_url'] ?? '');
$tmdb_region  = $user_region ?: ($config['tmdb_region'] ?? 'US');

if ($type === 'config') {
  telemetry_reason('config');
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'tmdb_api_key' => $tmdb_api_key,
    'app_logo_url' => $app_logo_url,
    'tmdb_region'  => $tmdb_region,
    'site_url'     => $site_url,
    'token_ttl'    => $ttl,
    'maintenance'  => $maint_enabled,
    'maintenance_streams' => ($maint_enabled && $maint_streams),
    'message'      => $maint_enabled ? $maint_msg : '',
    'video_url'    => $maint_video
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

$adult_ok = !empty($user["allow_adult"]);
$pkg_ids  = user_package_ids($pdo, (int)$user['id']);
[$pkg_sql, $pkg_params] = package_filter_sql($pkg_ids, 'c');

/* channels */
$sql = "
  SELECT c.id,c.name,c.group_title,c.tvg_id,c.tvg_name,c.tvg_logo,c.stream_url,c.direct_play,c.container_ext,
         IFNULL(cat.sort_order, 999999) AS cat_sort,
         IFNULL(c.sort_order, c.id) AS ch_sort
  FROM channels c
  LEFT JOIN categories cat ON cat.id=c.category_id
  WHERE 1=1
    ".($adult_ok ? "" : " AND IFNULL(c.is_adult,0)=0 AND IFNULL(cat.is_adult,0)=0 ")."
    $pkg_sql
  ORDER BY cat_sort, ch_sort, c.id
";
$st = $pdo->prepare($sql);
$st->execute($pkg_params);
$channels = $st->fetchAll(PDO::FETCH_ASSOC);

header("Content-Type: application/x-mpegURL; charset=utf-8");
header('Content-Disposition: attachment; filename="playlist_'.$username.'.m3u"');

echo "#EXTM3U\n";

foreach ($channels as $c) {
  $group = $c['group_title'] ? ' group-title="'.e($c['group_title']).'"' : '';
  $tvgId = $c['tvg_id'] ? ' tvg-id="'.e($c['tvg_id']).'"' : '';
  $tvgNm = $c['tvg_name'] ? ' tvg-name="'.e($c['tvg_name']).'"' : '';
  $logo  = $c['tvg_logo'] ? ' tvg-logo="'.e($c['tvg_logo']).'"' : '';

  echo '#EXTINF:-1'.$tvgId.$tvgNm.$logo.$group.','.$c['name']."\n";

  // Decide extension: per-channel override or detect
  $ext = $c['container_ext'];
  if (!$ext) $ext = preg_match('/\.m3u8(\?|$)/i', (string)$c['stream_url']) ? 'm3u8' : 'ts';

  $exp = $exp_global;
  $token = make_token($username, (int)$c['id'], $exp, 'live');

  // Token-only URLs (no password leak)
  $p_empty = ''; // kept for backward compatibility

  if ($link_type === 'direct_protected') {
    // stream proxy URL (querystring)
    $hidden = $stream_site_url."/stream/index.php?u=".rawurlencode($username)."&p=".rawurlencode($p_empty)."&id=".$c['id'];
    $hidden .= "&exp=".$exp."&token=".$token;

  } elseif ($link_type === 'standard_protected') {
    // /live/u/p/id.ext (legacy password in path)
    $hidden = $stream_site_url."/live/".rawurlencode($username)."/".rawurlencode($password)."/".$c['id'].".".$ext;
    $hidden .= "?exp=".$exp."&token=".$token;

  } elseif ($link_type === 'auto') {
    // Never leak upstream URLs while maintenance mode is enabled.
    if ((int)$c['direct_play'] === 1 && !($maint_enabled && $maint_streams)) {
      echo $c['stream_url']."\n";
      continue;
    }
    $hidden = $stream_site_url."/stream/index.php?u=".rawurlencode($username)."&p=".rawurlencode($p_empty)."&id=".$c['id'];
    $hidden .= "&exp=".$exp."&token=".$token;

  } else { // token_protected (recommended)
    // /live/u/token/id.ext?exp=...
    $hidden = $stream_site_url."/live/".rawurlencode($username)."/".rawurlencode($token)."/".$c['id'].".".$ext;
    $hidden .= "?exp=".$exp;
  }

  echo $hidden."\n";
}

/* ---------- VOD + SERIES (m3u_plus) ---------- */
if ($type === 'm3u_plus') {
  // Movies (VOD)
  [$vod_pkg_sql, $vod_pkg_params] = package_filter_sql_movies($pkg_ids, 'm');
  $st = $pdo->prepare("
    SELECT m.id,m.name,m.stream_url,m.poster_url,m.container_ext,IFNULL(m.is_adult,0) AS is_adult, vc.name AS cat_name
    FROM movies m
    LEFT JOIN vod_categories vc ON vc.id=m.category_id
    WHERE 1=1
      ".($adult_ok ? "" : " AND IFNULL(m.is_adult,0)=0 ")."
      $vod_pkg_sql
    ORDER BY vc.name, m.name
  ");
  $st->execute($vod_pkg_params);
  $movies = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($movies as $m) {
    $group = ' group-title="'.e($m['cat_name'] ?: 'VOD').'"';
    $logo  = $m['poster_url'] ? ' tvg-logo="'.e($m['poster_url']).'"' : '';
    echo '#EXTINF:-1'.$logo.$group.','.$m['name']."\n";

    // Extension matters for many IPTV players (some pick demuxer from URL).
    // Prefer DB override; otherwise infer from stream_url path; fallback to mp4.
    $ext = (string)($m['container_ext'] ?? '');
    if ($ext === '') {
      $src = (string)($m['stream_url'] ?? '');
      if (preg_match('/\.m3u8(\?|$)/i', $src)) {
        $ext = 'm3u8';
      } else {
        $path = (string)(parse_url($src, PHP_URL_PATH) ?? '');
        $cand = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        // Allow common containers only
        if ($cand !== '' && preg_match('/^(mp4|mkv|m4v|mov|avi|wmv|flv|webm|ts)$/i', $cand)) {
          $ext = $cand;
        } else {
          $ext = 'mp4';
        }
      }
    } else {
      $ext = strtolower($ext);
    }

    $exp = $exp_global;
    $tok = make_token($username, (int)$m['id'], $exp, 'movie');

    if ($link_type === 'direct_protected') {
      $url = $stream_site_url."/stream/index.php?u=".rawurlencode($username)."&p=&id=".$m['id']."&type=movie&exp=".$exp."&token=".$tok;
    } elseif ($link_type === 'standard_protected') {
      $url = $stream_site_url."/movie/".rawurlencode($username)."/".rawurlencode($password)."/".$m['id'].".".$ext."?exp=".$exp."&token=".$tok;
    } else {
      $url = $stream_site_url."/movie/".rawurlencode($username)."/".rawurlencode($tok)."/".$m['id'].".".$ext."?exp=".$exp;
    }

    echo $url."\n";
  }

  // Series episodes (flattened playlist)
  [$ser_pkg_sql, $ser_pkg_params] = package_filter_sql_series($pkg_ids, 's');
  $st = $pdo->prepare("
    SELECT e.id, e.title, e.season_num, e.episode_num, e.container_ext,
           s.name AS series_name, s.cover_url, sc.name AS cat_name
    FROM series_episodes e
    JOIN series s ON s.id=e.series_id
    LEFT JOIN series_categories sc ON sc.id=s.category_id
    WHERE 1=1
      ".($adult_ok ? "" : " AND IFNULL(s.is_adult,0)=0 ")."
      $ser_pkg_sql
    ORDER BY sc.name, s.name, e.season_num, e.episode_num
  ");
  $st->execute($ser_pkg_params);
  $eps = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($eps as $e) {
    $group = ' group-title="'.e($e['cat_name'] ?: 'Series').'"';
    $logo  = $e['cover_url'] ? ' tvg-logo="'.e($e['cover_url']).'"' : '';
    $label = $e['series_name']." - S".str_pad((string)$e['season_num'],2,'0',STR_PAD_LEFT)."E".str_pad((string)$e['episode_num'],2,'0',STR_PAD_LEFT)." - ".$e['title'];
    echo '#EXTINF:-1'.$logo.$group.','.e($label)."\n";

    $ext = $e['container_ext'] ?: 'mp4';
    $exp = $exp_global;
    $tok = make_token($username, (int)$e['id'], $exp, 'episode');

    if ($link_type === 'direct_protected') {
      $url = $stream_site_url."/stream/index.php?u=".rawurlencode($username)."&p=&id=".$e['id']."&type=episode&exp=".$exp."&token=".$tok;
    } elseif ($link_type === 'standard_protected') {
      $url = $stream_site_url."/series/".rawurlencode($username)."/".rawurlencode($password)."/".$e['id'].".".$ext."?exp=".$exp."&token=".$tok;
    } else {
      $url = $stream_site_url."/series/".rawurlencode($username)."/".rawurlencode($tok)."/".$e['id'].".".$ext."?exp=".$exp;
    }
    echo $url."\n";
  }
}