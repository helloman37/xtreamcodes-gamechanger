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

header("Content-Type: application/json; charset=utf-8");

$ip = get_client_ip();

// Request telemetry (admin -> Telemetry)
$__action = strtolower((string)($_GET['action'] ?? ''));
telemetry_init('player_api', $__action);

// Basic rate limiting (helps stop brute force / app spam)
if (!rate_limit('player_api_ip_' . $ip, 120, 60)) {
  telemetry_reason('rate_limited');
  http_response_code(429);
  echo json_encode(["error"=>"rate_limited"]);
  exit;
}

$username = trim($_GET['username'] ?? '');
$password = (string)($_GET['password'] ?? '');
$action   = strtolower($_GET['action'] ?? '');

if ($username === '' || $password === '') {
  telemetry_reason('missing_credentials');
  echo json_encode(["user_info"=>["auth"=>0],"error"=>"Missing credentials"]);
  exit;
}

$pdo = db();
ensure_categories($pdo);

// Global maintenance mode
// Requirement: when maintenance is ON, do NOT deliver LIVE TV streams.
// - Live playback is blocked/redirected in stream/index.php.
// - Here, we also avoid leaking upstream "direct_source" URLs.
$maint_enabled = (system_setting_get($pdo, 'maintenance_mode', '0') === '1');
$maint_streams = (system_setting_get($pdo, 'maintenance_streams_mode', '0') === '1');
$maint_msg = (string)system_setting_get(
  $pdo,
  'maintenance_message',
  'Service is temporarily under maintenance. Please try again later.'
);
$maint_video = trim((string)system_setting_get($pdo, 'maintenance_video_url', ''));

// Hard bans (IP)
$ban = abuse_ip_ban_lookup($pdo, $ip);
if ($ban) {
  audit_log('ban_block', null, ['ban_type'=>'ip','ip'=>$ip]);
  telemetry_reason('banned_ip');
  http_response_code(403);
  echo json_encode(["user_info"=>["auth"=>0],"error"=>"banned"]);
  exit;
}

/* ---------- AUTH ---------- */
$st = $pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
$st->execute([$username]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
  audit_log('auth_fail', null, ['u'=>$username]);
  telemetry_reason('auth_fail', ['username'=>$username]);
  echo json_encode(["user_info"=>["auth"=>0],"error"=>"Invalid credentials"]);
  exit;
}

telemetry_set_user((int)$user['id'], (string)$user['username']);

// Email verification gate (optional)
try {
  if (gc_email_verification_required($pdo) && !gc_email_user_is_verified($user)) {
    $em = trim((string)($user['email'] ?? ''));
    if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
      telemetry_reason('email_verification_required');
      http_response_code(403);
      echo json_encode(["user_info"=>["auth"=>0],"error"=>"email_verification_required"]);
      exit;
    }
  }
} catch (Throwable $e) {}

// Hard bans (user)
$ban = abuse_user_ban_lookup($pdo, (int)$user['id']);
if ($ban) {
  audit_log('ban_block_user', (int)$user['id'], ['ban_type'=>'user','ip'=>$ip]);
  telemetry_reason('banned_user');
  http_response_code(403);
  echo json_encode(["user_info"=>["auth"=>0],"error"=>"banned"]);
  exit;
}

// User policy: IP allow/deny
if (!ip_allowed($ip, $user['ip_allowlist'] ?? null, $user['ip_denylist'] ?? null)) {
  audit_log('ip_block', (int)$user['id'], ['ip'=>$ip]);
  telemetry_reason('ip_not_allowed');
  http_response_code(403);
  echo json_encode(["user_info"=>["auth"=>0],"error"=>"ip_not_allowed"]);
  exit;
}

/* active sub + plan */
$st = $pdo->prepare("
  SELECT s.*, p.max_streams, p.duration_days, p.name AS plan_name
  FROM subscriptions s
  JOIN plans p ON p.id=s.plan_id
  WHERE s.user_id=? AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())
  ORDER BY s.ends_at DESC LIMIT 1
");
$st->execute([(int)$user['id']]);
$sub = $st->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
  telemetry_reason('no_subscription');
  echo json_encode(["user_info"=>["auth"=>0],"error"=>"No active subscription"]);
  exit;
}

$adult_ok = !empty($user["allow_adult"]);
$pkg_ids  = user_package_ids($pdo, (int)$user['id']);

// Detect optional categories.is_adult column (avoid fatal SQL errors if schema is older)
$has_cat_adult = false;
try {
  $chk = $pdo->query("SHOW COLUMNS FROM categories LIKE 'is_adult'");
  $has_cat_adult = (bool)$chk->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $has_cat_adult = false;
}


/* ---------- BASE RESPONSE (no action) ---------- */
if ($action === '') {
  $now = time();
  $exp = strtotime($sub['ends_at']);

  echo json_encode([
    "user_info" => [
      "auth" => 1,
      "username" => $username,
      "password" => $password,
      // Maintenance hints (apps may ignore unknown keys; safe additive change)
      "maintenance" => $maint_enabled ? 1 : 0,
      "maintenance_streams" => ($maint_enabled && $maint_streams) ? 1 : 0,
      "maintenance_message" => $maint_enabled ? $maint_msg : "",
      "maintenance_video_url" => $maint_video,
      "status" => "Active",
      "exp_date" => (string)$exp,
      "is_trial" => "0",
      "active_cons" => (string)$sub['max_streams'],
      "created_at" => (string)strtotime($user['created_at']),
      "max_connections" => (string)$sub['max_streams'],
      "allowed_output_formats" => ["m3u8","ts"],
      "device_lock" => (int)($user['device_lock'] ?? 0),
      "packages_assigned" => count($pkg_ids)
    ],
    "server_info" => [
      "url" => parse_url($base_url, PHP_URL_HOST),
      "port" => parse_url($base_url, PHP_URL_PORT) ?: (parse_url($base_url, PHP_URL_SCHEME)==='https' ? 443 : 80),
      "https_port" => 443,
      "server_protocol" => parse_url($base_url, PHP_URL_SCHEME) ?: "http",
      "rtmp_port" => "",
      "timezone" => date_default_timezone_get(),
      "timestamp_now" => $now,
      "time_now" => date("Y-m-d H:i:s",$now)
    ]
  ]);
  exit;
}

/* ---------- LIVE CATEGORIES ---------- */
if ($action === 'get_live_categories') {
  [$pkg_sql, $pkg_params] = package_filter_sql($pkg_ids, 'c');

  $sql = "
    SELECT cat.id, cat.name
    FROM categories cat
    JOIN channels c ON c.category_id=cat.id
    WHERE 1=1
      ".($adult_ok ? "" : (" AND IFNULL(c.is_adult,0)=0 " . ($has_cat_adult ? " AND IFNULL(cat.is_adult,0)=0 " : "")))."
      $pkg_sql
    GROUP BY cat.id, cat.name, cat.sort_order
    ORDER BY cat.sort_order, cat.id
  ";
  $st = $pdo->prepare($sql);
  $st->execute($pkg_params);
  $cats = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach($cats as $c){
    $out[] = [
      "category_id" => (string)$c['id'],
      "category_name" => $c['name'],
      "parent_id" => 0
    ];
  }
  echo json_encode($out);
  exit;
}

/* ---------- LIVE STREAMS ---------- */
if ($action === 'get_live_streams') {
  $category_id = (int)($_GET['category_id'] ?? 0);
  [$pkg_sql, $pkg_params] = package_filter_sql($pkg_ids, 'c');

  $params = $pkg_params;
  $where_cat = "";
  if ($category_id > 0) {
    $where_cat = " AND c.category_id=? ";
    $params[] = $category_id;
  }

  $sql = "
    SELECT c.id,c.category_id,c.name,c.group_title,c.tvg_id,c.tvg_name,c.tvg_logo,c.stream_url,c.direct_play,c.container_ext,c.created_at
    FROM channels c
    LEFT JOIN categories cat ON cat.id = c.category_id
    WHERE 1=1
      ".($adult_ok ? "" : (" AND IFNULL(c.is_adult,0)=0 " . ($has_cat_adult ? " AND IFNULL(cat.is_adult,0)=0 " : "")))."
      $pkg_sql
      $where_cat
    ORDER BY IFNULL(c.sort_order, c.id), c.id
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $channels = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach($channels as $c){
    $ext = $c['container_ext'];
    if (!$ext) $ext = preg_match("/\.m3u8(\?|$)/i",(string)$c["stream_url"]) ? "m3u8" : "ts";

    $out[] = [
      "num" => (int)$c['id'],
      "name" => $c['name'],
      "stream_type" => "live",
      "stream_id" => (int)$c['id'],
      "stream_icon" => $c['tvg_logo'] ?: "",
      "epg_channel_id" => $c['tvg_id'] ?: "",
      "added" => (string)strtotime($c['created_at']),
      "category_id" => (string)((int)$category_id > 0 ? $category_id : (int)($c['category_id'] ?? 0)),
      "custom_sid" => "",
      "tv_archive" => 0,
      // During maintenance, never leak upstream stream_url. Optionally point to the
      // configured maintenance video so apps can play it immediately.
      "direct_source" => ($maint_enabled && $maint_streams)
        ? ($maint_video !== '' ? $maint_video : '')
        : (((int)$c["direct_play"]===1) ? $c["stream_url"] : ""),
      "container_extension" => $ext
    ];
  }

  echo json_encode($out);
  exit;
}

/* ---------- EPG (DB-backed) ---------- */
if ($action === 'get_short_epg' || $action === 'get_simple_data_table') {
  $stream_id = (int)($_GET['stream_id'] ?? ($_GET['id'] ?? 0));
  $limit = (int)($_GET['limit'] ?? 12);
  if ($limit < 1) $limit = 12;
  if ($limit > 48) $limit = 48;

  $tvg = '';
  if ($stream_id > 0) {
    $st = $pdo->prepare("SELECT tvg_id FROM channels WHERE id=? LIMIT 1");
    $st->execute([$stream_id]);
    $tvg = (string)($st->fetch(PDO::FETCH_ASSOC)['tvg_id'] ?? '');
  }

  if ($tvg === '') {
    echo json_encode(["epg_listings"=>[]]);
    exit;
  }

  // Return programs around now -> next X entries
  $st = $pdo->prepare("
    SELECT start_utc, stop_utc, title, descr
    FROM epg_programs
    WHERE channel_xmltv_id=?
      AND stop_utc > (UTC_TIMESTAMP() - INTERVAL 6 HOUR)
      AND start_utc < (UTC_TIMESTAMP() + INTERVAL 2 DAY)
    ORDER BY start_utc
    LIMIT $limit
  ");
  $st->execute([$tvg]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $list = [];
  foreach ($rows as $r) {
    $start = strtotime($r['start_utc'].' UTC');
    $stop  = strtotime($r['stop_utc'].' UTC');
    $list[] = [
      "start" => $start,
      "end" => $stop,
      "title" => $r['title'],
      "description" => $r['descr'] ?? "",
      "start_timestamp" => (string)$start,
      "stop_timestamp" => (string)$stop,
      "start_time" => gmdate("Y-m-d H:i:s", $start),
      "end_time" => gmdate("Y-m-d H:i:s", $stop)
    ];
  }

  echo json_encode(["epg_listings"=>$list]);
  exit;
}

/* ---------- VOD CATEGORIES ---------- */
if ($action === 'get_vod_categories') {
  // If packages are assigned, only show categories that contain accessible movies.
  [$pkg_sql, $pkg_params] = package_filter_sql_movies($pkg_ids, 'm');

  $sql = "
    SELECT DISTINCT vc.id, vc.name
    FROM vod_categories vc
    JOIN movies m ON m.category_id=vc.id
    WHERE 1=1
      ".($adult_ok ? "" : " AND IFNULL(m.is_adult,0)=0 ")."
      $pkg_sql
    ORDER BY vc.name
  ";
  $st = $pdo->prepare($sql);
  $st->execute($pkg_params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach ($rows as $r) {
    $out[] = [
      "category_id" => (string)$r["id"],
      "category_name" => $r["name"],
      "parent_id" => 0
    ];
  }
  echo json_encode($out);
  exit;
}

/* ---------- VOD STREAMS ---------- */
if ($action === 'get_vod_streams') {
  $category_id = (int)($_GET['category_id'] ?? 0);

  [$pkg_sql, $pkg_params] = package_filter_sql_movies($pkg_ids, 'm');

  $params = $pkg_params;
  $where = " WHERE 1=1 ";
  if (!$adult_ok) $where .= " AND IFNULL(m.is_adult,0)=0 ";
  $where .= $pkg_sql;
  if ($category_id > 0) { $where .= " AND m.category_id=? "; $params[] = $category_id; }

  $st = $pdo->prepare("
    SELECT m.id,m.category_id,m.name,m.poster_url,m.stream_url,m.container_ext,m.rating,m.created_at
    FROM movies m
    $where
    ORDER BY m.name
  ");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach ($rows as $m) {
    $ext = $m['container_ext'];
    if (!$ext) $ext = preg_match("/\.m3u8(\?|$)/i",(string)$m["stream_url"]) ? "m3u8" : "mp4";

    $out[] = [
      "num" => (int)$m["id"],
      "name" => $m["name"],
      "stream_type" => "movie",
      "stream_id" => (int)$m["id"],
      "stream_icon" => $m["poster_url"] ?: "",
      "rating" => (string)($m["rating"] ?? ""),
      "added" => (string)strtotime($m["created_at"]),
      "category_id" => (string)((int)($m["category_id"] ?? 0)),
      "container_extension" => $ext,
      "direct_source" => ""
    ];
  }

  echo json_encode($out);
  exit;
}

/* ---------- VOD INFO ---------- */
if ($action === 'get_vod_info') {
  $vod_id = (int)($_GET['vod_id'] ?? ($_GET['id'] ?? 0));
  if ($vod_id < 1) { echo json_encode(["error"=>"missing_vod_id"]); exit; }

  $st = $pdo->prepare("SELECT * FROM movies WHERE id=? LIMIT 1");
  $st->execute([$vod_id]);
  $m = $st->fetch(PDO::FETCH_ASSOC);
  if (!$m) { echo json_encode(["error"=>"not_found"]); exit; }
  if (!$adult_ok && (int)($m["is_adult"] ?? 0) === 1) { http_response_code(403); echo json_encode(["error"=>"adult_block"]); exit; }

  // Package restriction for VOD
  if ($pkg_ids) {
    $in = implode(',', array_fill(0, count($pkg_ids), '?'));
    $params = array_merge([$vod_id], $pkg_ids);
    $st = $pdo->prepare("SELECT 1 FROM package_movies pm WHERE pm.movie_id=? AND pm.package_id IN ($in) LIMIT 1");
    $st->execute($params);
    if (!$st->fetch()) {
      http_response_code(403);
      echo json_encode(["error"=>"not_in_package"]);
      exit;
    }
  }

  $ext = $m['container_ext'];
  if (!$ext) $ext = preg_match("/\.m3u8(\?|$)/i",(string)$m["stream_url"]) ? "m3u8" : "mp4";

  echo json_encode([
    "info" => [
      "movie_image" => $m["poster_url"] ?: "",
      "backdrop_path" => $m["backdrop_url"] ?: "",
      "plot" => $m["plot"] ?: "",
      "rating" => (string)($m["rating"] ?? ""),
      "releasedate" => $m["release_date"] ?: "",
      "tmdb_id" => (string)($m["tmdb_id"] ?? "")
    ],
    "movie_data" => [
      "stream_id" => (int)$m["id"],
      "name" => $m["name"],
      "added" => (string)strtotime($m["created_at"]),
      "category_id" => (string)((int)($m["category_id"] ?? 0)),
      "container_extension" => $ext,
      "direct_source" => ""
    ]
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

/* ---------- SERIES CATEGORIES ---------- */
if ($action === 'get_series_categories') {
  // If packages are assigned, only show categories that contain accessible series.
  [$pkg_sql, $pkg_params] = package_filter_sql_series($pkg_ids, 's');

  $sql = "
    SELECT DISTINCT sc.id, sc.name
    FROM series_categories sc
    JOIN series s ON s.category_id=sc.id
    WHERE 1=1
      ".($adult_ok ? "" : " AND IFNULL(s.is_adult,0)=0 ")."
      $pkg_sql
    ORDER BY sc.name
  ";
  $st = $pdo->prepare($sql);
  $st->execute($pkg_params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach ($rows as $r) {
    $out[] = [
      "category_id" => (string)$r["id"],
      "category_name" => $r["name"],
      "parent_id" => 0
    ];
  }
  echo json_encode($out);
  exit;
}

/* ---------- SERIES LIST ---------- */
if ($action === 'get_series') {
  $category_id = (int)($_GET['category_id'] ?? 0);

  [$pkg_sql, $pkg_params] = package_filter_sql_series($pkg_ids, 's');

  $params = $pkg_params;
  $where = " WHERE 1=1 ";
  if (!$adult_ok) $where .= " AND IFNULL(s.is_adult,0)=0 ";
  $where .= $pkg_sql;
  if ($category_id > 0) { $where .= " AND s.category_id=? "; $params[] = $category_id; }

  $st = $pdo->prepare("
    SELECT s.id,s.category_id,s.name,s.cover_url,s.rating,s.created_at
    FROM series s
    $where
    ORDER BY s.name
  ");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  foreach ($rows as $s) {
    $out[] = [
      "num" => (int)$s["id"],
      "name" => $s["name"],
      "series_id" => (int)$s["id"],
      "cover" => $s["cover_url"] ?: "",
      "rating" => (string)($s["rating"] ?? ""),
      "added" => (string)strtotime($s["created_at"]),
      "category_id" => (string)((int)($s["category_id"] ?? 0))
    ];
  }

  echo json_encode($out);
  exit;
}

/* ---------- SERIES INFO ---------- */
if ($action === 'get_series_info') {
  $series_id = (int)($_GET['series_id'] ?? ($_GET['id'] ?? 0));
  if ($series_id < 1) { echo json_encode(["error"=>"missing_series_id"]); exit; }

  $st = $pdo->prepare("SELECT * FROM series WHERE id=? LIMIT 1");
  $st->execute([$series_id]);
  $s = $st->fetch(PDO::FETCH_ASSOC);
  if (!$s) { echo json_encode(["error"=>"not_found"]); exit; }
  if (!$adult_ok && (int)($s["is_adult"] ?? 0) === 1) { http_response_code(403); echo json_encode(["error"=>"adult_block"]); exit; }

  // Package restriction for Series
  if ($pkg_ids) {
    $in = implode(',', array_fill(0, count($pkg_ids), '?'));
    $params = array_merge([$series_id], $pkg_ids);
    $st = $pdo->prepare("SELECT 1 FROM package_series ps WHERE ps.series_id=? AND ps.package_id IN ($in) LIMIT 1");
    $st->execute($params);
    if (!$st->fetch()) {
      http_response_code(403);
      echo json_encode(["error"=>"not_in_package"]);
      exit;
    }
  }

  $st = $pdo->prepare("
    SELECT id,season_num,episode_num,title,stream_url,container_ext,created_at
    FROM series_episodes
    WHERE series_id=?
    ORDER BY season_num, episode_num
  ");
  $st->execute([$series_id]);
  $eps = $st->fetchAll(PDO::FETCH_ASSOC);

  $episodes_by_season = [];
  foreach ($eps as $e) {
    $season = (string)(int)$e["season_num"];
    if (!isset($episodes_by_season[$season])) $episodes_by_season[$season] = [];

    $ext = $e["container_ext"];
    if (!$ext) $ext = preg_match("/\.m3u8(\?|$)/i",(string)$e["stream_url"]) ? "m3u8" : "mp4";

    $episodes_by_season[$season][] = [
      "id" => (int)$e["id"],
      "episode_num" => (int)$e["episode_num"],
      "title" => $e["title"],
      "container_extension" => $ext,
      "added" => (string)strtotime($e["created_at"]),
      "direct_source" => ""
    ];
  }

  echo json_encode([
    "info" => [
      "name" => $s["name"],
      "cover" => $s["cover_url"] ?: "",
      "plot" => $s["plot"] ?: "",
      "rating" => (string)($s["rating"] ?? ""),
      "release_date" => $s["release_date"] ?: "",
      "tmdb_id" => (string)($s["tmdb_id"] ?? "")
    ],
    "episodes" => $episodes_by_season
  ], JSON_UNESCAPED_SLASHES);
  exit;
}

/* fallback */
echo json_encode(["error"=>"Unknown action"]);
