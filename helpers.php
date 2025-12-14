<?php
// helpers.php

// -----------------------------------------------------------------------------
// Install guard: if the app isn't configured yet, redirect to /install.
// (Some public pages do not touch db.php, so we guard here too.)
// Define IPTV_NO_INSTALL_GUARD before requiring helpers.php to bypass.
// -----------------------------------------------------------------------------
if (!defined('IPTV_NO_INSTALL_GUARD') && PHP_SAPI !== 'cli') {
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
  $lock = __DIR__ . '/install/installed.lock';

  $installed = false;

  // Primary: installer lock
  if (is_file($lock)) $installed = true;

  // Back-compat: old local override file
  if (!$installed && is_file(__DIR__ . '/config.local.php')) $installed = true;

  // Fallback: config.php has non-empty DB name + user
  if (!$installed && is_file(__DIR__ . '/config.php')) {
    try {
      $cfg = require __DIR__ . '/config.php';
      if (is_array($cfg) && !empty($cfg['db']['name']) && !empty($cfg['db']['user'])) {
        $installed = true;
      }
    } catch (Throwable $e) {
      // ignore
    }
  }

  if (!$installed && !str_starts_with($path, '/install')) {
    // Redirect to installer using the current app base path (supports subfolder installs).
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    // Normalize Windows-style backslashes to forward slashes.
    $base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $dest   = ($base ? $base : '') . '/install/';
    header('Location: ' . $dest);
    exit;
  }
}

function e($str) {
  return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function flash_set($msg, $type='info') {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $_SESSION['flash'] = ['msg'=>$msg, 'type'=>$type];
}

function flash_show() {
  if (!empty($_SESSION['flash'])) {
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo "<div style='padding:8px;border:1px solid #333;margin:10px 0;background:#0d0f14;color:#e6f1ff'>
      <b>".e($f['type']).":</b> ".e($f['msg'])."
    </div>";
  }
}

// --- CSRF helpers (admin forms) ---
function csrf_token(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}
function csrf_input(): string {
  return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">';
}
function csrf_validate(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $sent = $_POST['csrf_token'] ?? '';
  $good = $_SESSION['csrf_token'] ?? '';
  if (!$sent || !$good || !hash_equals($good, $sent)) {
    http_response_code(403);
    die('Forbidden (CSRF)');
  }
}
// --- end CSRF helpers ---
/* ---------- CREDENTIAL + LINK UTILITIES (ADMIN) ---------- */
function iptv_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  try {
    $cfg = require __DIR__ . '/config.php';
    if (!is_array($cfg)) $cfg = [];
  } catch (Throwable $e) {
    $cfg = [];
  }
  return $cfg;
}

function iptv_base_url(): string {
  $cfg = iptv_config();
  $base = trim((string)($cfg['base_url'] ?? ''));
  if ($base !== '' && $base !== 'http://' && $base !== 'https://') {
    return rtrim($base, '/');
  }

  // Fallback: infer from request
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

  $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
  $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
  return $scheme . '://' . $host . ($dir ? $dir : '');
}

function iptv_numeric_string(int $len=10): string {
  $out = '';
  for ($i=0; $i<$len; $i++) {
    $out .= (string)random_int(0, 9);
  }
  return $out;
}

function iptv_unique_numeric_username(PDO $pdo, int $len=10, int $tries=30): string {
  for ($i=0; $i<$tries; $i++) {
    $u = iptv_numeric_string($len);
    $st = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $st->execute([$u]);
    if (!$st->fetch()) return $u;
  }
  // fallback: append time fragment
  return iptv_numeric_string(max(4, $len-4)) . substr((string)time(), -4);
}

/**
 * Encrypt plaintext (used only so admins can view a user's generated password).
 * Format: base64( IV(16) || HMAC(32) || CIPHERTEXT )
 */
function iptv_encrypt(string $plain): string {
  if ($plain === '') return '';
  if (!function_exists('openssl_encrypt')) return '';
  $cfg = iptv_config();
  $secret = (string)($cfg['secret_key'] ?? '');
  $key = hash('sha256', $secret, true); // 32 bytes
  $iv = random_bytes(16);
  $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
  if ($cipher === false) return '';
  $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
  return base64_encode($iv . $mac . $cipher);
}

function iptv_decrypt(?string $enc): string {
  if (!$enc) return '';
  if (!function_exists('openssl_decrypt')) return '';
  $raw = base64_decode($enc, true);
  if ($raw === false || strlen($raw) < (16+32+1)) return '';
  $iv = substr($raw, 0, 16);
  $mac = substr($raw, 16, 32);
  $cipher = substr($raw, 48);

  $cfg = iptv_config();
  $secret = (string)($cfg['secret_key'] ?? '');
  $key = hash('sha256', $secret, true);

  $calc = hash_hmac('sha256', $iv . $cipher, $key, true);
  if (!hash_equals($mac, $calc)) return '';

  $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
  return ($plain === false) ? '' : (string)$plain;
}

function iptv_m3u_link(string $username, string $password): string {
  $base = iptv_base_url();
  return $base . '/get.php?username=' . urlencode($username) . '&password=' . urlencode($password) . '&type=m3u';
}
/* ---------- END CREDENTIAL + LINK UTILITIES ---------- */


function parse_m3u(string $content): array {
  $lines = preg_split("/\r\n|\n|\r/", $content);
  $channels = [];
  $current = null;

  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#EXTM3U')) continue;

    if (str_starts_with($line, '#EXTINF')) {
      $current = [
        'name' => '',
        'group_title' => null,
        'tvg_id' => null,
        'tvg_name' => null,
        'tvg_logo' => null,
        'stream_url' => null,
        'epg_url' => null
      ];

      preg_match_all('/([a-zA-Z0-9\-\_]+)="([^"]*)"/', $line, $matches, PREG_SET_ORDER);
      foreach ($matches as $m) {
        $key = strtolower($m[1]);
        $val = $m[2];
        if ($key === 'group-title') $current['group_title'] = $val;
        if ($key === 'tvg-id') $current['tvg_id'] = $val;
        if ($key === 'tvg-name') $current['tvg_name'] = $val;
        if ($key === 'tvg-logo') $current['tvg_logo'] = $val;
      }

      $parts = explode(',', $line, 2);
      $current['name'] = trim($parts[1] ?? 'Unknown');
    } else if ($current && !str_starts_with($line, '#')) {
      $current['stream_url'] = $line;
      $channels[] = $current;
      $current = null;
    }
  }

  return $channels;
}

function check_stream_url(string $url, int $timeout=8): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => $timeout,
    CURLOPT_USERAGENT => 'IPTV-Admin-Checker/1.0'
  ]);
  curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  $works = ($code >= 200 && $code < 400);
  return ['code'=>$code, 'works'=>$works, 'error'=>$err];
}

/* ---------- SECURITY + LOGGING HELPERS ---------- */

function get_client_ip(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function strict_device_id_enabled(): bool {
  try {
    $config = require __DIR__ . '/config.php';
    return !empty($config['strict_device_id']);
  } catch (Throwable $e) {
    return false;
  }
}

function get_device_fingerprint(): string {
  // Prefer explicit device id (apps can send ?device_id= or X-Device-ID)
  $dev = trim($_GET['device_id'] ?? ($_SERVER['HTTP_X_DEVICE_ID'] ?? ''));

  if ($dev !== '') {
    return substr(hash('sha256', $dev), 0, 32);
  }

  // If strict mode is on, do NOT fall back to UA (forces real device_id from your app)
  if (strict_device_id_enabled()) {
    return '';
  }

  // If the stream proxy passed a fingerprint forward, use it (stabilizes device identity)
  $dfp = trim($_GET['dfp'] ?? '');
  if ($dfp !== '' && preg_match('/^[a-f0-9]{32}$/i', $dfp)) {
    return strtolower($dfp);
  }

  $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
  $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
  return substr(hash('sha256', $ua.'|'.$lang), 0, 32);
}


function rate_limit(string $key, int $limit, int $window_seconds): bool {
  // Returns true if allowed, false if rate-limited.
  $dir = __DIR__ . '/tmp/ratelimit';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);

  $f = $dir . '/' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $key) . '.json';
  $now = time();

  $data = ['t' => $now, 'hits' => []];
  if (is_file($f)) {
    $raw = @file_get_contents($f);
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j)) $data = $j;
  }

  // prune
  $hits = array_values(array_filter($data['hits'] ?? [], fn($t) => ($t >= $now - $window_seconds)));
  if (count($hits) >= $limit) {
    $data['hits'] = $hits;
    @file_put_contents($f, json_encode($data));
    return false;
  }
  $hits[] = $now;
  $data['hits'] = $hits;
  @file_put_contents($f, json_encode($data));
  return true;
}

function parse_cidr_list(?string $txt): array {
  if (!$txt) return [];
  $parts = preg_split('/[\s,;]+/', trim($txt));
  return array_values(array_filter(array_map('trim', $parts)));
}

function ip_in_cidr(string $ip, string $cidr): bool {
  if (strpos($cidr, '/') === false) {
    return $ip === $cidr;
  }
  [$subnet, $mask] = explode('/', $cidr, 2);
  $mask = (int)$mask;
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $ip_long = ip2long($ip);
    $sub_long = ip2long($subnet);
    $mask_long = -1 << (32 - $mask);
    return ($ip_long & $mask_long) === ($sub_long & $mask_long);
  }
  // IPv6 CIDR not supported in this minimal helper
  return false;
}

function ip_allowed(string $ip, ?string $allowlist, ?string $denylist): bool {
  $deny = parse_cidr_list($denylist);
  foreach ($deny as $cidr) {
    if (ip_in_cidr($ip, $cidr)) return false;
  }
  $allow = parse_cidr_list($allowlist);
  if (!$allow) return true;
  foreach ($allow as $cidr) {
    if (ip_in_cidr($ip, $cidr)) return true;
  }
  return false;
}

/* ---------- ABUSE BANS ---------- */
/**
 * Returns the active ban row if the IP or user is currently banned, else null.
 * Table: abuse_bans (created by runtime migrations).
 */
function abuse_ban_lookup(PDO $pdo, string $ip, ?int $user_id=null): ?array {
  try {
    // IP ban (takes priority)
    $st = $pdo->prepare("
      SELECT * FROM abuse_bans
      WHERE ban_type='ip' AND ip=?
        AND (expires_at IS NULL OR expires_at > NOW())
      ORDER BY (expires_at IS NULL) DESC, expires_at DESC, id DESC
      LIMIT 1
    ");
    $st->execute([$ip]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // User ban
    if ($user_id) {
      $st = $pdo->prepare("
        SELECT * FROM abuse_bans
        WHERE ban_type='user' AND user_id=?
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY (expires_at IS NULL) DESC, expires_at DESC, id DESC
        LIMIT 1
      ");
      $st->execute([(int)$user_id]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row;
    }
  } catch (Throwable $e) {
    return null;
  }
  return null;
}


function audit_log(string $event, ?int $user_id=null, array $meta=[], ?int $reseller_id=null): void {
  try {
    $pdo = db();
    $pdo->prepare('INSERT INTO audit_logs (user_id,reseller_id,ip,event,meta_json) VALUES (?,?,?,?,?)')
        ->execute([$user_id, $reseller_id, get_client_ip(), $event, $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null]);
  } catch (Throwable $e) {
    // ignore
  }

  // Optional webhook
  try {
    $config = require __DIR__ . '/config.php';
    $hook = $config['webhook_url'] ?? '';
    if ($hook) {
      $payload = json_encode([
        'event' => $event,
        'user_id' => $user_id,
        'reseller_id' => $reseller_id,
        'ip' => get_client_ip(),
        'meta' => $meta,
        'ts' => time()
      ], JSON_UNESCAPED_SLASHES);
      $ch = curl_init($hook);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3
      ]);
      @curl_exec($ch);
      @curl_close($ch);
    }
  } catch (Throwable $e) {
    // ignore
  }
}

/* ---------- REQUEST TELEMETRY (API + STREAM) ---------- */
// Usage:
//   telemetry_init('player_api', $action);
//   telemetry_set_user($user['id'], $user['username']);
//   telemetry_reason('auth_fail');
// A shutdown handler writes to request_logs.

function telemetry_init(string $endpoint, string $action=''): void {
  if (PHP_SAPI === 'cli') return;
  // Avoid logging installer pages.
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
  if (str_starts_with($path, '/install')) return;

  $GLOBALS['__telemetry'] = [
    't0' => microtime(true),
    'endpoint' => substr($endpoint, 0, 64),
    'action' => substr($action, 0, 64),
    'user_id' => null,
    'reseller_id' => null,
    'username' => null,
    'reason' => 'ok',
    'meta' => []
  ];

  register_shutdown_function(function() {
    $t = $GLOBALS['__telemetry'] ?? null;
    if (!is_array($t) || empty($t['endpoint'])) return;

    $dur = (int)round((microtime(true) - (float)($t['t0'] ?? microtime(true))) * 1000);
    $status = http_response_code();
    if (!$status) $status = 200;

    $ip = get_client_ip();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 250);
    $dev = '';
    try { $dev = get_device_fingerprint(); } catch (Throwable $e) { $dev = ''; }

    $meta = $t['meta'] ?? [];

    try {
      $pdo = db();
      $pdo->prepare("\n        INSERT INTO request_logs\n          (endpoint, action, user_id, reseller_id, username, ip, user_agent, device_fp, status_code, duration_ms, reason, meta_json)\n        VALUES\n          (?,?,?,?,?,?,?,?,?,?,?,?)\n      ")->execute([
        $t['endpoint'],
        ($t['action'] !== '' ? $t['action'] : null),
        $t['user_id'],
        $t['reseller_id'],
        $t['username'],
        $ip,
        $ua,
        ($dev !== '' ? $dev : null),
        $status,
        $dur,
        ($t['reason'] !== '' ? $t['reason'] : null),
        (!empty($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null)
      ]);
    } catch (Throwable $e) {
      // ignore
    }
  });
}

function telemetry_set_user(int $user_id, string $username='', ?int $reseller_id=null): void {
  if (!isset($GLOBALS['__telemetry']) || !is_array($GLOBALS['__telemetry'])) return;
  $GLOBALS['__telemetry']['user_id'] = $user_id;
  if ($username !== '') $GLOBALS['__telemetry']['username'] = substr($username, 0, 64);
  if ($reseller_id !== null) $GLOBALS['__telemetry']['reseller_id'] = (int)$reseller_id;
}

function telemetry_reason(string $reason, array $meta_add=[]): void {
  if (!isset($GLOBALS['__telemetry']) || !is_array($GLOBALS['__telemetry'])) return;
  $GLOBALS['__telemetry']['reason'] = substr($reason, 0, 64);
  if ($meta_add) {
    $GLOBALS['__telemetry']['meta'] = array_merge($GLOBALS['__telemetry']['meta'] ?? [], $meta_add);
  }
}

function telemetry_meta(array $meta_add): void {
  if (!isset($GLOBALS['__telemetry']) || !is_array($GLOBALS['__telemetry'])) return;
  if (!$meta_add) return;
  $GLOBALS['__telemetry']['meta'] = array_merge($GLOBALS['__telemetry']['meta'] ?? [], $meta_add);
}

/* ---------- TOKENS ---------- */
function random_hex_token(int $bytes=16): string {
  return bin2hex(random_bytes($bytes));
}


function make_token(string $username, int $item_id, int $exp, string $type='live'): string {
  $config = require __DIR__ . '/config.php';
  $data = $type . '|' . $username . '|' . $item_id . '|' . $exp;
  return hash_hmac('sha256', $data, $config['secret_key']);
}

function verify_token(string $username, int $item_id, int $exp, string $token, string $type='live'): bool {
  if ($exp < time()) return false;

  // New typed token
  $good = make_token($username, $item_id, $exp, $type);
  if (hash_equals($good, $token)) return true;

  // Backward compatibility: old tokens were username|id|exp without type
  $config = require __DIR__ . '/config.php';
  $legacy_data = $username . '|' . $item_id . '|' . $exp;
  $legacy = hash_hmac('sha256', $legacy_data, $config['secret_key']);
  return hash_equals($legacy, $token);
}

// -------------------- Reseller auth (v10+) --------------------
if (!function_exists('reseller_login')) {
  function reseller_login($username, $password) {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT * FROM resellers WHERE username = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$username]);
    $reseller = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reseller) return false;
    if (!password_verify($password, $reseller['password_hash'])) return false;

    $_SESSION['reseller_id'] = $reseller['id'];
    $_SESSION['reseller_username'] = $reseller['username'];
    return true;
  }
}

if (!function_exists('reseller_auth')) {
  function reseller_auth() {
    return isset($_SESSION['reseller_id']);
  }
}



/* ---------- SYSTEM SETTINGS (key/value) ---------- */

function system_setting_get(PDO $pdo, string $key, ?string $default = null): ?string {
  try {
    $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? LIMIT 1");
    $st->execute([$key]);
    $val = $st->fetchColumn();
    if ($val === false || $val === null) return $default;
    return (string)$val;
  } catch (Throwable $e) {
    return $default;
  }
}

function system_setting_set(PDO $pdo, string $key, ?string $value): void {
  try {
    if ($value === null || $value === '') {
      $pdo->prepare("DELETE FROM system_settings WHERE setting_key=?")->execute([$key]);
      return;
    }
    $pdo->prepare("
      INSERT INTO system_settings (setting_key, setting_value)
      VALUES (?,?)
      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
    ")->execute([$key, $value]);
  } catch (Throwable $e) {
    // ignore
  }
}
