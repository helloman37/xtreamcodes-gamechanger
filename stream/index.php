<?php
require_once __DIR__ . '/../api_common.php';

$config   = require __DIR__ . '/../config.php';
$base_url = rtrim($config['base_url'], '/');

header("Access-Control-Allow-Origin: *");

// -----------------------------------------------------------------------------
// Optional "fail video" redirects: if configured in admin, redirect failed
// stream attempts to a custom video URL instead of returning a text/JSON error.
// Keys: fail_video_{live|vod}_{invalid_login|expired|banned|limit|offline}
// -----------------------------------------------------------------------------
function _fail_video_url(PDO $pdo, string $type, string $reason): string {
  // Prefer the matching kind (live vs vod), but if it's not set, fall back to the other kind.
  // This prevents misconfiguration (e.g. only VOD set) from disabling redirects.
  $kind = ($type === 'live') ? 'live' : 'vod';
  $primary = (string)system_setting_get($pdo, "fail_video_{$kind}_{$reason}", '');
  if ($primary !== '') return $primary;
  $other = ($kind === 'live') ? 'vod' : 'live';
  return (string)system_setting_get($pdo, "fail_video_{$other}_{$reason}", '');
}
function _redirect_fail_video(string $url): void {
  http_response_code(302);
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Location: ' . $url);
  exit;
}

// Base64url helpers (keeps segment URLs out of querystring patterns that trigger some WAF rules)
function b64url_encode_str(string $s): string {
  return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}


$u  = (string)($_GET['u'] ?? '');
$p  = (string)($_GET['p'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$type = strtolower((string)($_GET['type'] ?? 'live'));
if (!in_array($type, ['live','movie','episode'], true)) { http_response_code(400); exit('Bad type'); }

// Request telemetry (admin -> Telemetry)
telemetry_init('stream', $type);
telemetry_meta(['id'=>$id]);

$exp   = (int)($_GET['exp'] ?? 0);
$token = (string)($_GET['token'] ?? '');

if ($u === '' || $id < 1) {
  telemetry_reason('bad_params');
  http_response_code(400);
  exit("Bad params");
}

$pdo = db();
$ip = get_client_ip();
$ban = abuse_ip_ban_lookup($pdo, $ip);
if ($ban) {
  audit_log('ban_block', null, ['ban_type'=>'ip','ip'=>$ip]);
  telemetry_reason('banned_ip');
  $url = _fail_video_url($pdo, $type, 'banned');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(403);
  exit('Banned');
}

ensure_categories($pdo);

$ip = get_client_ip();
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 250);
$device_fp = get_device_fingerprint();
$device_id_raw = trim($_GET['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));
$device_id_raw = substr($device_id_raw, 0, 128);
if ($device_fp==='' && strict_device_id_enabled()) {
  telemetry_reason('device_id_required');
  http_response_code(403);
  exit('device_id_required');
}

/* user */
$st = $pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
$st->execute([$u]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
  telemetry_reason('user_not_found', ['username'=>$u]);
  $url = _fail_video_url($pdo, $type, 'invalid_login');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(401);
  exit("Invalid user");
}

telemetry_set_user((int)$user['id'], (string)$user['username']);

/* token OR password */
$token_ok = ($token && $exp && verify_token($u, $id, $exp, $token, $type));
$pass_ok  = ($p !== '' && password_verify($p, $user['password_hash']));
if (!$token_ok && !$pass_ok) {
  audit_log('stream_auth_fail', (int)$user['id'], ['channel_id'=>$id]);
  telemetry_reason('auth_fail', ['id'=>$id]);
  $url = _fail_video_url($pdo, $type, 'invalid_login');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(401);
  exit("Invalid credentials");
}


// Hard bans (user)
$ban = abuse_user_ban_lookup($pdo, (int)$user['id']);
if ($ban) {
  audit_log('ban_block_user', (int)$user['id'], ['ban_type'=>'user','ip'=>$ip]);
  telemetry_reason('banned_user');
  $url = _fail_video_url($pdo, $type, 'banned');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(403);
  exit('Banned');
}


// Policy: IP allow/deny
if (!ip_allowed($ip, $user['ip_allowlist'] ?? null, $user['ip_denylist'] ?? null)) {
  audit_log('ip_block', (int)$user['id'], ['ip'=>$ip,'channel_id'=>$id]);
  telemetry_reason('ip_not_allowed', ['ip'=>$ip,'id'=>$id]);
  $url = _fail_video_url($pdo, $type, 'banned');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(403);
  exit("IP not allowed");
}

/* active sub + plan (cached) */
$sub_cache_ttl = (int)($config['sub_cache_ttl'] ?? 60);
$sub = iptv_cached_active_subscription($pdo, (int)$user['id'], $sub_cache_ttl);
if (!$sub) {
  audit_log('expired_block', (int)$user['id'], ['type'=>$type,'id'=>$id]);
  telemetry_reason('expired');
  $url = _fail_video_url($pdo, $type, 'expired');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(403);
  exit('Expired');
}

/* cleanup old sessions */
$pdo->exec("DELETE FROM stream_sessions WHERE last_seen < (NOW() - INTERVAL 2 DAY)");

/* ---------- active device/stream enforcement ---------- */
$dev_win  = (int)($config['device_window'] ?? 300);
if ($dev_win < 120) $dev_win = 120;

/*
  CONCURRENT LIMIT MODEL (FIXES CHANNEL-SWITCH FALSE POSITIVES):
  - We treat one *device* as one active session in the window.
  - Changing channels on the same device UPDATES the session (doesn't consume a new slot).
  - max_streams = concurrent active sessions (devices actively streaming).
  - max_devices = concurrent distinct devices actively streaming.
*/

$device_active = false;
$session_id = 0;
$session_token = '';

try {
  if ($device_fp !== '') {
    // Reuse the most recent active session for this device
    $st = $pdo->prepare("
      SELECT id, IFNULL(session_token,'') AS session_token
      FROM stream_sessions
      WHERE user_id=?
        AND device_fp=?
        AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')
        AND last_seen > (NOW() - INTERVAL ? SECOND)
      ORDER BY last_seen DESC LIMIT 1
    ");
    $st->execute([(int)$user['id'], $device_fp, $dev_win]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $session_id = (int)($row['id'] ?? 0);
    $session_token = (string)($row['session_token'] ?? '');
    $device_active = ($session_id > 0);

    // Defensive: kill any other active sessions for this same device in the window (prevents stacking)
    if ($session_id > 0) {
      $pdo->prepare("
        UPDATE stream_sessions
        SET killed_at=NOW()
        WHERE user_id=?
          AND device_fp=?
          AND id<>?
          AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')
          AND last_seen > (NOW() - INTERVAL ? SECOND)
      ")->execute([(int)$user['id'], $device_fp, $session_id, $dev_win]);
    }
  } else {
    // Fallback identity when no device_fp is available
    $st = $pdo->prepare("
      SELECT id, IFNULL(session_token,'') AS session_token
      FROM stream_sessions
      WHERE user_id=?
        AND ip=?
        AND user_agent=?
        AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')
        AND last_seen > (NOW() - INTERVAL ? SECOND)
      ORDER BY last_seen DESC LIMIT 1
    ");
    $st->execute([(int)$user['id'], $ip, $ua, $dev_win]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $session_id = (int)($row['id'] ?? 0);
    $session_token = (string)($row['session_token'] ?? '');
    $device_active = ($session_id > 0);

    if ($session_id > 0) {
      $pdo->prepare("
        UPDATE stream_sessions
        SET killed_at=NOW()
        WHERE user_id=?
          AND ip=?
          AND user_agent=?
          AND id<>?
          AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')
          AND last_seen > (NOW() - INTERVAL ? SECOND)
      ")->execute([(int)$user['id'], $ip, $ua, $session_id, $dev_win]);
    }
  }
} catch (Throwable $e) {
  // Older schema fallback (no killed_at/session_token/etc)
  $device_active = false;
  $session_id = 0;
  $session_token = '';
}

$need_device = $device_active ? 0 : 1;
// Stream slots track concurrent devices streaming (one slot per device)
$need_stream = $device_active ? 0 : 1;

// Count active devices in window (stable: device_fp when present; otherwise ip+ua)
$active_devices = 0;
$active_streams = 0;

try {
  $st = $pdo->prepare("
    SELECT COUNT(DISTINCT
      CASE
        WHEN device_fp IS NOT NULL AND device_fp<>'' THEN device_fp
        ELSE CONCAT('ip:',ip,'|ua:',user_agent)
      END
    ) AS c
    FROM stream_sessions
    WHERE user_id=?
      AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')
      AND last_seen > (NOW() - INTERVAL ? SECOND)
  ");
  $st->execute([(int)$user['id'], $dev_win]);
  $active_devices = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

  // In this model, active streams == active devices (one active session per device)
  $active_streams = $active_devices;
} catch (Throwable $e) {
  // Older schema fallback
  $st = $pdo->prepare("
    SELECT COUNT(DISTINCT CONCAT('ip:',ip,'|ua:',user_agent)) AS c
    FROM stream_sessions
    WHERE user_id=?
      AND last_seen > (NOW() - INTERVAL ? SECOND)
  ");
  $st->execute([(int)$user['id'], $dev_win]);
  $active_devices = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
  $active_streams = $active_devices;
}

// Enforce plan max_devices (concurrent active devices)
if ($max_devices > 0 && ($active_devices + $need_device) > $max_devices) {
  audit_log('max_devices', (int)$user['id'], ['active'=>$active_devices,'max'=>$max_devices]);
  telemetry_reason('max_devices', ['active'=>$active_devices,'max'=>$max_devices]);
  $url = _fail_video_url($pdo, $type, 'limit');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(403);
  exit('max_devices_reached');
}

// Enforce plan max_streams (concurrent sessions / devices actively streaming)
if ($max_streams > 0 && ($active_streams + $need_stream) > $max_streams) {
  audit_log('max_connections', (int)$user['id'], ['id'=>$id,'active'=>$active_streams,'max'=>$max_streams]);
  telemetry_reason('max_connections', ['active'=>$active_streams,'max'=>$max_streams,'id'=>$id]);
  $url = _fail_video_url($pdo, $type, 'limit');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(429);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(["error"=>"max_connections_reached","active"=>$active_streams,"max"=>$max_streams]);
  exit;
}

/* ---------- session tracking (NO token rotation on same device) ---------- */
if ($session_token === '') {
  $session_token = random_hex_token(16);
}

if ($session_id > 0) {
  // Reuse the device session and simply update what it's currently watching.
  try {
    $pdo->prepare("
      UPDATE stream_sessions
      SET channel_id=?,
          item_id=?,
          stream_type=?,
          ip=?,
          user_agent=?,
          device_fp=?,
          session_token=?,
          killed_at=NULL,
          last_seen=NOW()
      WHERE id=?
    ")->execute([$id, $id, $type, $ip, $ua, $device_fp, $session_token, $session_id]);
  } catch (Throwable $e) {
    // Backward compatible update
    $pdo->prepare("UPDATE stream_sessions SET channel_id=?, ip=?, user_agent=?, device_fp=?, last_seen=NOW() WHERE id=?")
        ->execute([$id, $ip, $ua, $device_fp, $session_id]);
  }
} else {
  // New device session
  try {
    $pdo->prepare("
      INSERT INTO stream_sessions (user_id, channel_id, item_id, stream_type, ip, user_agent, device_fp, session_token, last_seen)
      VALUES (?,?,?,?,?,?,?,?, NOW())
    ")->execute([(int)$user['id'], $id, $id, $type, $ip, $ua, $device_fp, $session_token]);
    $session_id = (int)$pdo->lastInsertId();
  } catch (Throwable $e) {
    // Backward compatible insert
    $pdo->prepare("
      INSERT INTO stream_sessions (user_id, channel_id, ip, user_agent, device_fp, last_seen)
      VALUES (?,?,?,?,?, NOW())
    ")->execute([(int)$user['id'], $id, $ip, $ua, $device_fp]);
    $session_id = (int)$pdo->lastInsertId();
  }
}

/* anti-restream: too many IP swaps in window */
$max_ip_changes = (int)($user['max_ip_changes'] ?? ($config['max_ip_changes'] ?? 3));
$max_ip_window  = (int)($user['max_ip_window']  ?? ($config['max_ip_window']  ?? 600));

if ($max_ip_changes > 0 && $max_ip_window > 0) {
  $st = $pdo->prepare("
    SELECT COUNT(DISTINCT ip) AS c
    FROM stream_sessions
    WHERE user_id=?
      AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')
      AND last_seen > (NOW() - INTERVAL ? SECOND)
  ");
  $st->execute([(int)$user['id'], $max_ip_window]);
  $ip_count = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
  if ($ip_count > $max_ip_changes) {
    audit_log('restream_detected', (int)$user['id'], ['channel_id'=>$id,'ip_count'=>$ip_count,'window'=>$max_ip_window]);
    telemetry_reason('restream_detected', ['ip_count'=>$ip_count,'window'=>$max_ip_window,'id'=>$id]);
    http_response_code(403);
    exit("Restream detected");
  }
}

/* stream item lookup */
$channel = null;
if ($type === 'live') {
  $st = $pdo->prepare("SELECT id,stream_url,sources_json,direct_play, IFNULL(is_adult,0) AS is_adult FROM channels WHERE id=?");
  $st->execute([$id]);
  $channel = $st->fetch(PDO::FETCH_ASSOC);
  if (!$channel) { http_response_code(404); exit("Channel not found"); }
  if (empty($user["allow_adult"]) && (int)$channel["is_adult"] === 1) {
    http_response_code(403); exit("Adult content not allowed");
  }
} elseif ($type === 'movie') {
  $st = $pdo->prepare("SELECT id, stream_url, NULL AS sources_json, 0 AS direct_play, IFNULL(is_adult,0) AS is_adult, container_ext, poster_url AS tvg_logo FROM movies WHERE id=?");
  $st->execute([$id]);
  $channel = $st->fetch(PDO::FETCH_ASSOC);
  if (!$channel) { http_response_code(404); exit("Movie not found"); }
  if (empty($user["allow_adult"]) && (int)$channel["is_adult"] === 1) {
    http_response_code(403); exit("Adult content not allowed");
  }
} else { // episode
  $st = $pdo->prepare("SELECT e.id, e.stream_url, NULL AS sources_json, 0 AS direct_play, IFNULL(s.is_adult,0) AS is_adult, e.container_ext
                      FROM series_episodes e
                      JOIN series s ON s.id=e.series_id
                      WHERE e.id=?");
  $st->execute([$id]);
  $channel = $st->fetch(PDO::FETCH_ASSOC);
  if (!$channel) { http_response_code(404); exit("Episode not found"); }
  if (empty($user["allow_adult"]) && (int)$channel["is_adult"] === 1) {
    http_response_code(403); exit("Adult content not allowed");
  }
}

/* sources (failover) */
$sources = [];
if (!empty($channel['sources_json'])) {
  $j = json_decode($channel['sources_json'], true);
  if (is_array($j)) $sources = $j;
}
if (!$sources) {
  $raw = (string)$channel['stream_url'];
  if (strpos($raw, '||') !== false) {
    $sources = array_values(array_filter(array_map('trim', explode('||', $raw))));
  } else {
    $sources = [$raw];
  }
}

// direct play bypass
if (!empty($channel['direct_play'])) {
  header("Location: ".$sources[0], true, 302);
  exit;
}

/* ---------- upstream request headers ---------- */
$client_ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0';
$up_headers = ['Accept: */*', 'Connection: keep-alive'];
if (!empty($_SERVER['HTTP_REFERER'])) $up_headers[] = 'Referer: '.$_SERVER['HTTP_REFERER'];
if (!empty($_SERVER['HTTP_ORIGIN']))  $up_headers[] = 'Origin: '.$_SERVER['HTTP_ORIGIN'];
if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $up_headers[] = 'Accept-Language: '.$_SERVER['HTTP_ACCEPT_LANGUAGE'];

function fetch_url(string $url, array $headers, string $ua, int $timeout=20): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING => "",
    CURLOPT_USERAGENT => $ua,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => $timeout
  ]);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $eff  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  $err  = curl_error($ch);
  curl_close($ch);
  return ['body'=>$body, 'code'=>$code, 'effective'=>$eff ?: $url, 'error'=>$err];
}

// Tiny probe for non-HLS sources (keeps "channel offline" detection fast without downloading full media)
function probe_url(string $url, array $headers, string $ua, int $timeout=10): array {
  $ch = curl_init($url);
  $h = $headers;
  // Request just a couple bytes where supported (MP4/VOD); safe if ignored (live TS)
  $h[] = 'Range: bytes=0-1';
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING => "",
    CURLOPT_USERAGENT => $ua,
    CURLOPT_HTTPHEADER => $h,
    CURLOPT_TIMEOUT => $timeout
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $eff  = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
  $err  = (string)curl_error($ch);
  curl_close($ch);
  return ['body'=>$body, 'code'=>$code, 'effective'=>$eff, 'error'=>$err];
}

/* choose working source (failover) */
$chosen = $sources[0] ?? '';
$playlist = null;
$effective = $chosen;
$chosen_code = 0;

foreach ($sources as $src) {
  if (!$src) continue;
  if (!preg_match('/\.m3u8(\?|$)/i', $src)) {
    $chosen = $src;
    break;
  }
  $r = fetch_url($src, $up_headers, $client_ua, 20);
  $chosen_code = (int)$r['code'];
  if ($r['body'] !== false && trim((string)$r['body']) !== '' && $chosen_code >= 200 && $chosen_code < 500) {
    $chosen = $src;
    $playlist = (string)$r['body'];
    $effective = (string)$r['effective'];
    break;
  }
}

if ($chosen === '') {
  telemetry_reason('no_upstream');
  $url = _fail_video_url($pdo, $type, 'offline');
  if ($url !== '') _redirect_fail_video($url);
  http_response_code(502);
  exit("No upstream");
}

/* if not HLS: proxy bytes to keep URL hidden (or redirect if upstream blocks) */
// ---- streaming tune-ups (important for .ts and long-running streams) ----
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);
@set_time_limit(0);
@ignore_user_abort(true);
header('X-Accel-Buffering: no'); // disables nginx proxy buffering when supported

// If the client asked for .ts, some players expect a TS MIME-type.
if (preg_match('/\.ts(\?|$)/i', $chosen)) {
  header('Content-Type: video/mp2t');
}
if (!preg_match('/\.m3u8(\?|$)/i', $chosen)) {
  // Optional: if admin configured "Channel offline" fail video, probe the upstream first.
  $offline_url = _fail_video_url($pdo, $type, 'offline');
  if ($offline_url !== '') {
    $pr = probe_url($chosen, $up_headers, $client_ua, 12);
    $pc = (int)($pr['code'] ?? 0);
    $perr = (string)($pr['error'] ?? '');
    // Treat any 4xx/5xx/timeout as offline/unreachable for this feature.
    if ($pc === 0 || $pc >= 400) {
      telemetry_reason('upstream_offline', ['code'=>$pc, 'err'=>$perr]);
      _redirect_fail_video($offline_url);
    }
  }

  // Keep last_seen fresh for long-running non-HLS streams (near real-time max streams/devices)
  $__sid = (int)$session_id;
  $__ip = $ip;
  $__ua = $ua;
  $__dfp = $device_fp;
  $__pdo = $pdo;
  $__last_ping = 0;
  $__ping_every = 8; // seconds

  $ch = curl_init($chosen);
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING => "",
    CURLOPT_USERAGENT => $client_ua,
    CURLOPT_HTTPHEADER => $up_headers,
    CURLOPT_HEADERFUNCTION => function($curl, $header) {
      $len = strlen($header);
// Forward upstream HTTP status (helps players fail fast on 403/404)
if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $mm)) {
  http_response_code((int)$mm[1]);
}
      if (stripos($header, 'Content-Type:') === 0) header(trim($header));
      if (stripos($header, 'Content-Length:') === 0) header(trim($header));
      if (stripos($header, 'Accept-Ranges:') === 0) header(trim($header));
      return $len;
    },
    CURLOPT_WRITEFUNCTION => function($curl, $data) use ($__pdo, $__sid, $__ip, $__ua, $__dfp, $__ping_every, &$__last_ping) {
      if ($__sid > 0) {
        $now = time();
        if ($__last_ping === 0 || ($now - $__last_ping) >= $__ping_every) {
          $__last_ping = $now;
          try {
            $__pdo->prepare("UPDATE stream_sessions SET last_seen=NOW(), ip=?, user_agent=?, device_fp=? WHERE id=?")
                 ->execute([$__ip, $__ua, $__dfp, $__sid]);
          } catch (Throwable $e) {}
        }
      }
      echo $data;
      @flush();
      return strlen($data);
    }
  ]);
  // forward Range for VOD/MP4
  if (!empty($_SERVER['HTTP_RANGE'])) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($up_headers, ['Range: '.$_SERVER['HTTP_RANGE']]));
  }
  curl_exec($ch);
  curl_close($ch);
  exit;
}

// If we didn't prefetch playlist above, fetch now
if ($playlist === null) {
  $r = fetch_url($chosen, $up_headers, $client_ua, 20);
  $playlist = (string)($r['body'] ?? '');
  $effective = (string)($r['effective'] ?? $chosen);
  if (trim($playlist) === '') {
    telemetry_reason('upstream_offline', ['code'=>(int)($r['code'] ?? 0), 'err'=>(string)($r['error'] ?? '')]);
    $url = _fail_video_url($pdo, $type, 'offline');
    if ($url !== '') _redirect_fail_video($url);
    header("Location: ".$chosen, true, 302);
    exit;
  }
}

/* base for relative URLs */
$base_dir = preg_replace('~/[^/]*$~', '/', $effective);
$lines = preg_split("/\r\n|\n|\r/", $playlist);

header("Content-Type: application/vnd.apple.mpegurl; charset=utf-8");

// Build segment rewrite base
if ($token_ok) {
  $seg_base = $base_url . "/seg/" . rawurlencode($u) . "/" . rawurlencode($token) . "/" . $id;
  $seg_tail = "exp=" . $exp . "&type=" . rawurlencode($type) . "&st=" . rawurlencode($session_token);
} else {
  $seg_base = $base_url . "/seg/" . rawurlencode($u) . "/" . rawurlencode($p) . "/" . $id;
  $seg_tail = "type=" . rawurlencode($type) . "&st=" . rawurlencode($session_token);
}

if ($device_id_raw !== '') {
  $seg_tail .= "&device_id=" . rawurlencode($device_id_raw);
}
if ($device_fp !== '') {
  $seg_tail .= "&dfp=" . rawurlencode($device_fp);
}

$out = [];
foreach ($lines as $line) {
  $trim = trim($line);

  if ($trim === '' || str_starts_with($trim, '#')) {
    $out[] = $line;
    continue;
  }

  if (!preg_match('~^https?://~i', $trim)) {
    $trim = $base_dir . ltrim($trim, '/');
  }

  $src_enc = b64url_encode_str($trim);
  $q = "src=" . rawurlencode($src_enc);
  if ($seg_tail !== '') $q .= "&" . $seg_tail;
  if ($token_ok) $q .= "&token=" . rawurlencode($token);

  // Signed segment URLs to avoid DB hits per segment (verified in stream/segment.php)
  if (!empty($config['secret_key'])) {
    $seg_cred = $token_ok ? $token : $p;
    $payload = $u . "|" . $seg_cred . "|" . (string)$id . "|" . $type . "|" . $session_token . "|" . (string)$exp . "|" . (string)$device_id_raw . "|" . (string)$device_fp . "|" . $src_enc;
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $config['secret_key'], true)), '+/', '-_'), '=');
    $q .= "&sig=" . rawurlencode($sig);
  }

  $out[] = $seg_base . "?" . $q;
}

echo implode("\n", $out);