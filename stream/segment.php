<?php
require_once __DIR__ . '/../api_common.php';

// -----------------------------------------------------------------------------
// Segment requests are usually .ts bytes. Redirecting to arbitrary media types can
// break some players, so we only redirect here when the configured fail video
// ends with .ts (best compatibility).
// -----------------------------------------------------------------------------
function _seg_fail_video_url(PDO $pdo, string $type, string $reason): string {
  $kind = ($type === 'live') ? 'live' : 'vod';
  $primary = (string)system_setting_get($pdo, "fail_video_{$kind}_{$reason}", '');
  if ($primary !== '') return $primary;
  $other = ($kind === 'live') ? 'vod' : 'live';
  return (string)system_setting_get($pdo, "fail_video_{$other}_{$reason}", '');
}
function _seg_try_redirect_ts(string $url): void {
  if ($url === '') return;
  if (!preg_match('/\.ts(\?|$)/i', $url)) return;
  http_response_code(302);
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Location: ' . $url);
  exit;
}

// Base64url decode helper (pairs with stream/index.php src=...)
function b64url_decode_str(string $s): string {
  $s = strtr($s, '-_', '+/');
  $pad = strlen($s) % 4;
  if ($pad) $s .= str_repeat('=', 4 - $pad);
  $out = base64_decode($s, true);
  return ($out === false) ? '' : $out;
}

$u  = (string)($_GET['u'] ?? '');
$p  = (string)($_GET['p'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$type = strtolower((string)($_GET['type'] ?? 'live'));
if (!in_array($type, ['live','movie','episode'], true)) { http_response_code(400); exit('Bad type'); }
$stoken = (string)($_GET['st'] ?? '');

$exp   = (int)($_GET['exp'] ?? 0);
$token = (string)($_GET['token'] ?? '');

$src = (string)($_GET['src'] ?? '');
$url = $src !== '' ? b64url_decode_str($src) : (string)($_GET['url'] ?? '');

if ($u==='' || $id<1 || $url==='') {
  http_response_code(400); exit("Bad params");
}

$pdo = db();
$ip = get_client_ip();
$ban = abuse_ban_lookup($pdo, $ip, null);
if ($ban) {
  audit_log('ban_block', null, ['ban_type'=>'ip','ip'=>$ip]);
  $fv = _seg_fail_video_url($pdo, $type, 'banned');
  _seg_try_redirect_ts($fv);
  http_response_code(403);
  exit('Banned');
}

ensure_categories($pdo);

$ip = get_client_ip();
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 250);
$device_fp = get_device_fingerprint();
if ($device_fp==='' && strict_device_id_enabled()) { http_response_code(403); exit('device_id_required'); }

/* user */
$st = $pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
$st->execute([$u]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
  $fv = _seg_fail_video_url($pdo, $type, 'invalid_login');
  _seg_try_redirect_ts($fv);
  http_response_code(401);
  exit("Invalid user");
}

/* token OR password */
$token_ok = ($token && $exp && verify_token($u, $id, $exp, $token, $type));
$pass_ok  = ($p !== '' && password_verify($p, $user['password_hash']));
if (!$token_ok && !$pass_ok) {
  $fv = _seg_fail_video_url($pdo, $type, 'invalid_login');
  _seg_try_redirect_ts($fv);
  http_response_code(401);
  exit("Invalid credentials");
}

// Policy: IP allow/deny
if (!ip_allowed($ip, $user['ip_allowlist'] ?? null, $user['ip_denylist'] ?? null)) {
  $fv = _seg_fail_video_url($pdo, $type, 'banned');
  _seg_try_redirect_ts($fv);
  http_response_code(403);
  exit("IP not allowed");
}

/* adult filter */
if ($type === 'live') {
  $st = $pdo->prepare("SELECT IFNULL(is_adult,0) AS is_adult FROM channels WHERE id=?");
  $st->execute([$id]);
  $ch = $st->fetch(PDO::FETCH_ASSOC);
  if ($ch && empty($user['allow_adult']) && (int)$ch['is_adult']===1) {
    http_response_code(403); exit("Adult content not allowed");
  }
} elseif ($type === 'movie') {
  $st = $pdo->prepare("SELECT IFNULL(is_adult,0) AS is_adult FROM movies WHERE id=?");
  $st->execute([$id]);
  $ch = $st->fetch(PDO::FETCH_ASSOC);
  if ($ch && empty($user['allow_adult']) && (int)$ch['is_adult']===1) {
    http_response_code(403); exit("Adult content not allowed");
  }
} else { // episode
  $st = $pdo->prepare("SELECT IFNULL(s.is_adult,0) AS is_adult FROM series_episodes e JOIN series s ON s.id=e.series_id WHERE e.id=?");
  $st->execute([$id]);
  $ch = $st->fetch(PDO::FETCH_ASSOC);
  if ($ch && empty($user['allow_adult']) && (int)$ch['is_adult']===1) {
    http_response_code(403); exit("Adult content not allowed");
  }
}

/* package/bouquet enforcement (live only) */
$pkg_ids = user_package_ids($pdo, (int)$user['id']);
if ($type !== 'live') { $pkg_ids = []; }

if ($pkg_ids) {
  $in = implode(',', array_fill(0, count($pkg_ids), '?'));
  $params = array_merge([$id], $pkg_ids);
  $st = $pdo->prepare("SELECT 1 FROM package_channels pc WHERE pc.channel_id=? AND pc.package_id IN ($in) LIMIT 1");
  $st->execute($params);
  if (!$st->fetch()) {
    http_response_code(403);
    exit("Not in your package");
  }
}

/* sub */
$st = $pdo->prepare("
  SELECT s.*
  FROM subscriptions s
  WHERE s.user_id=? AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())
  ORDER BY s.ends_at DESC LIMIT 1
");
$st->execute([(int)$user['id']]);
$sub = $st->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
  $fv = _seg_fail_video_url($pdo, $type, 'expired');
  _seg_try_redirect_ts($fv);
  http_response_code(403);
  exit("No active subscription");
}

/* Require an active recent session for this stream (anti-hotlink) */
$config = require __DIR__ . '/../config.php';
$dev_win = (int)($config['device_window'] ?? 120);

if ($stoken === '') {
  http_response_code(401);
  exit('missing_session_token');
}

$st = $pdo->prepare("
  SELECT id FROM stream_sessions
  WHERE user_id=?
    AND stream_type=?
    AND item_id=?
    AND ip=?
    AND session_token=?
    AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')
    AND last_seen > (NOW() - INTERVAL ? SECOND)
  ORDER BY last_seen DESC LIMIT 1
");
$st->execute([(int)$user['id'], $type, $id, $ip, $stoken, $dev_win]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) {
  http_response_code(429);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(["error"=>"no_active_session"]);
  exit;
}

// refresh last_seen
$pdo->prepare("UPDATE stream_sessions SET last_seen=NOW(), user_agent=?, device_fp=? WHERE id=?")
    ->execute([$ua, $device_fp, (int)$session['id']]);

/* let upstream dictate content-type */
// ---- streaming tune-ups (important for .ts segments) ----
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);
@set_time_limit(0);
@ignore_user_abort(true);
header('X-Accel-Buffering: no'); // disables nginx proxy buffering when supported
header_remove("Content-Type");
header('Content-Type: video/mp2t');

/* ---------- stream segment bytes ---------- */
$ch = curl_init($url);
$headers = [
  'Accept: */*',
  'Connection: keep-alive'
];
if (!empty($_SERVER['HTTP_REFERER'])) $headers[] = 'Referer: '.$_SERVER['HTTP_REFERER'];
if (!empty($_SERVER['HTTP_ORIGIN']))  $headers[] = 'Origin: '.$_SERVER['HTTP_ORIGIN'];
if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $headers[] = 'Accept-Language: '.$_SERVER['HTTP_ACCEPT_LANGUAGE'];

if (!empty($_SERVER['HTTP_RANGE'])) $headers[] = 'Range: '.$_SERVER['HTTP_RANGE'];

curl_setopt_array($ch, [
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_RETURNTRANSFER => false,
  CURLOPT_SSL_VERIFYPEER => false,
  CURLOPT_SSL_VERIFYHOST => false,
  CURLOPT_ENCODING => "",
  CURLOPT_USERAGENT => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
  CURLOPT_HTTPHEADER => $headers,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_HEADERFUNCTION => function($curl, $header) {
    $len = strlen($header);
// Forward upstream HTTP status (helps players fail fast on 403/404)
if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $mm)) {
  http_response_code((int)$mm[1]);
}
    if (stripos($header, 'Content-Type:') === 0) header(trim($header));
    if (stripos($header, 'Content-Length:') === 0) header(trim($header));
    if (stripos($header, 'Accept-Ranges:') === 0) header(trim($header));
    if (stripos($header, 'Content-Range:') === 0) header(trim($header));
    return $len;
  }
]);
curl_exec($ch);
curl_close($ch);
