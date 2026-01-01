<?php
// Start output buffering early so redirects/session cookies can be set even if templates echo later.
if (PHP_SAPI !== 'cli' && ob_get_level() === 0) {
  @ob_start();
}

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
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();

  // Bootstrap-style Toasts (lightweight, no Bootstrap dependency)
  static $toast_inited = false;
  if (!$toast_inited) {
    $toast_inited = true;
    echo <<<HTML
<div id="iptvToastHost" class="iptv-toast-host" aria-live="polite" aria-atomic="true"></div>
<style>
  /* --- IPTV Toasts (scoped, theme-aware) --- */
  #iptvToastHost.iptv-toast-host{
    position:fixed;
    top: max(14px, env(safe-area-inset-top));
    right: max(14px, env(safe-area-inset-right));
    z-index:99999;
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:10px;
    pointer-events:none;
    max-width: min(92vw, 460px);
    background: transparent !important;
    border: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    box-shadow: none !important;
  }
  #iptvToastHost.iptv-toast-host:empty{ display:none !important; }

  #iptvToastHost .iptv-toast{
    pointer-events:auto;
    width: min(440px, 92vw);
    border-radius: 14px;
    border: 1px solid var(--line, rgba(255,255,255,.14));
    background: var(--card, rgba(13,15,20,.98));
    color: var(--text, #e6f1ff);
    box-shadow: var(--shadow, 0 14px 38px rgba(0,0,0,.55));
    overflow: hidden;

    opacity: 0;
    transform: translate3d(10px,-4px,0);
    transition: opacity .18s ease, transform .18s ease;
  }
  #iptvToastHost .iptv-toast.show{ opacity:1; transform: translate3d(0,0,0); }

  #iptvToastHost .iptv-toast .inner{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding: 12px 12px;
  }
  #iptvToastHost .iptv-toast .icon{
    width:20px; height:20px;
    flex:0 0 20px;
    margin-top:2px;
    color: var(--toast-accent, #60a5fa);
  }
  #iptvToastHost .iptv-toast .content{ flex:1; min-width:0; }
  #iptvToastHost .iptv-toast .title{
    font-size:12px;
    font-weight:850;
    letter-spacing:.02em;
    margin:0 0 2px 0;
  }
  #iptvToastHost .iptv-toast .msg{
    font-size:13px;
    line-height:1.35;
    color: var(--muted, rgba(230,241,255,.78));
    white-space: pre-wrap;
    word-break: break-word;
  }
  #iptvToastHost .iptv-toast .close{
    appearance:none;
    border:0;
    background:transparent;
    color: var(--muted, rgba(230,241,255,.75));
    width:30px; height:30px;
    border-radius: 10px;
    display:grid; place-items:center;
    cursor:pointer;
    margin-left:4px;
  }
  #iptvToastHost .iptv-toast .close:hover{
    background: rgba(0,0,0,.06);
    color: var(--text, #e6f1ff);
  }

  #iptvToastHost .iptv-toast .progress{
    height: 3px;
    width: 100%;
    background: var(--toast-accent, #60a5fa);
    opacity: .9;
    transform-origin: left;
    transform: scaleX(1);
  }

  #iptvToastHost .iptv-toast.success{ --toast-accent: var(--green, #22c55e); }
  #iptvToastHost .iptv-toast.info{    --toast-accent: var(--blue,  #60a5fa); }
  #iptvToastHost .iptv-toast.warning{ --toast-accent: var(--orange,#f59e0b); }
  #iptvToastHost .iptv-toast.danger{  --toast-accent: var(--red,   #ef4444); }

  @media (prefers-reduced-motion: reduce){
    #iptvToastHost .iptv-toast{ transition:none; transform:none; }
    #iptvToastHost .iptv-toast.show{ transform:none; }
    #iptvToastHost .iptv-toast .progress{ transition:none !important; }
  }
  /* --- end toasts --- */
</style>
<script>
(function(){
  if (window.IPTVToast) return;

  function host(){
    var h = document.getElementById('iptvToastHost');
    if(!h){
      h = document.createElement('div');
      h.id = 'iptvToastHost';
      h.className = 'iptv-toast-host';
      document.body.appendChild(h);
    }
    return h;
  }

  function normType(t){
    t = (t || 'info').toString().toLowerCase();
    if (t === 'error') return 'danger';
    if (t === 'warn') return 'warning';
    if (t === 'ok') return 'success';
    if (t === 'primary' || t === 'secondary') return 'info';
    if (['success','info','warning','danger'].indexOf(t) !== -1) return t;
    return 'info';
  }

  function defaultTitle(type){
    if (type === 'success') return 'Success';
    if (type === 'warning') return 'Warning';
    if (type === 'danger')  return 'Error';
    return 'Notice';
  }

  function iconSvg(type){
    // Simple, crisp, no external deps.
    if (type === 'success'){
      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.1v.9a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg>';
    }
    if (type === 'warning'){
      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.3 3.3 1.7 18a2 2 0 0 0 1.7 3h17.2a2 2 0 0 0 1.7-3L13.7 3.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';
    }
    if (type === 'danger'){
      return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6"/><path d="M9 9l6 6"/></svg>';
    }
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>';
  }

  function toast(type, message, opts){
    type = normType(type);
    message = (message == null) ? '' : String(message);
    opts = opts || {};

    var total = Number(opts.delay || 4200);
    if (!isFinite(total) || total < 800) total = 4200;

    var ttl = (opts.title === false) ? '' : String(opts.title || defaultTitle(type));
    var max = Number(opts.max || 6);
    if (!isFinite(max) || max < 1) max = 6;

    var el = document.createElement('div');
    el.className = 'iptv-toast ' + type;
    el.setAttribute('role','alert');
    el.setAttribute('aria-live','assertive');
    el.setAttribute('aria-atomic','true');

    el.innerHTML =
      '<div class="inner">' +
        '<div class="icon" aria-hidden="true">' + iconSvg(type) + '</div>' +
        '<div class="content">' +
          '<div class="title"></div>' +
          '<div class="msg"></div>' +
        '</div>' +
        '<button class="close" type="button" aria-label="Close">' +
          '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="M6 6l12 12"/></svg>' +
        '</button>' +
      '</div>' +
      '<div class="progress"></div>';

    var titleEl = el.querySelector('.title');
    var msgEl   = el.querySelector('.msg');
    var closeBtn= el.querySelector('.close');
    var bar     = el.querySelector('.progress');

    if (titleEl){
      titleEl.textContent = ttl;
      if (!ttl) titleEl.style.display = 'none';
    }
    if (msgEl) msgEl.textContent = message;

    var h = host();
    while (h.children.length >= max){
      h.removeChild(h.firstElementChild);
    }
    h.appendChild(el);

    // animate in
    requestAnimationFrame(function(){ el.classList.add('show'); });

    var killed = false;
    var remaining = total;
    var startedAt = 0;
    var tmr = 0;

    function setBar(ratio){
      if (!bar) return;
      ratio = Math.max(0, Math.min(1, ratio));
      bar.style.transition = 'none';
      bar.style.transform = 'scaleX(' + ratio + ')';
    }
    function animateBar(ms){
      if (!bar) return;
      bar.style.transition = 'transform ' + ms + 'ms linear';
      bar.style.transform = 'scaleX(0)';
    }

    function remove(){
      if (killed) return; killed = true;
      el.classList.remove('show');
      setTimeout(function(){
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }, 220);
    }

    function startTimer(){
      clearTimeout(tmr);
      startedAt = (window.performance && performance.now) ? performance.now() : Date.now();
      tmr = setTimeout(remove, remaining);

      // progress bar
      setBar(remaining / total);
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          animateBar(remaining);
        });
      });
    }

    function pauseTimer(){
      clearTimeout(tmr);
      var now = (window.performance && performance.now) ? performance.now() : Date.now();
      var elapsed = Math.max(0, now - startedAt);
      remaining = Math.max(0, remaining - elapsed);
      setBar(Math.max(0.02, remaining / total));
    }

    startTimer();

    if (closeBtn){
      closeBtn.addEventListener('click', function(e){
        e.stopPropagation();
        clearTimeout(tmr);
        remove();
      });
    }

    // pause on hover (resume from where it left off)
    el.addEventListener('mouseenter', function(){
      if (killed) return;
      pauseTimer();
    });
    el.addEventListener('mouseleave', function(){
      if (killed) return;
      if (remaining < 900) remaining = 900; // avoid insta-disappear after hover
      startTimer();
    });

    // optional click-to-close
    if (opts.clickToClose){
      el.addEventListener('click', function(e){
        if (e.target && (e.target.closest && e.target.closest('.close'))) return;
        clearTimeout(tmr);
        remove();
      });
    }
  }

  // global helper for AJAX / buttons
  window.IPTVToast = toast;
})();
</script>
HTML;
  }

  // Render session flash as a toast (and clear it).
  if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    // Support either a single flash or an array of flashes.
    $flashes = [];
    if (is_array($flash) && array_key_exists('msg', $flash)) {
      $flashes[] = $flash;
    } elseif (is_array($flash)) {
      foreach ($flash as $f) {
        if (is_array($f) && array_key_exists('msg', $f)) $flashes[] = $f;
      }
    }

    foreach ($flashes as $f) {
      $t = (string)($f['type'] ?? 'info');
      $m = (string)($f['msg'] ?? '');
      $payload = json_encode(['type'=>$t,'msg'=>$m], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      echo "<script>(function(p){ try{ if(window.IPTVToast){ window.IPTVToast(p.type, p.msg); } }catch(e){} })($payload);</script>";
    }
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

  // If the POST body got dropped (most commonly because post_max_size/client body limits were exceeded),
  // $_POST/$_FILES will be empty and the CSRF token will look 'missing'. Return a proper 413 instead.
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($cl > 0 && empty($_POST) && empty($_FILES)) {
      $pm = (string)ini_get('post_max_size');
      $um = (string)ini_get('upload_max_filesize');
      http_response_code(413);
      die('Upload too large or request body rejected. Increase post_max_size (' . $pm . ') and upload_max_filesize (' . $um . ') (and server body limits if applicable).');
    }
  }

  $sent = $_POST['csrf_token'] ?? '';
  $good = $_SESSION['csrf_token'] ?? '';
  if (!$sent || !$good || !hash_equals($good, $sent)) {
    http_response_code(403);
    die('Forbidden (CSRF)');
  }
}
// --- end CSRF helpers ---


/* ---------- AVATAR HELPERS (PORTAL + STOREFRONT) ---------- */
function gc_avatar_dir(): string {
  return __DIR__ . '/uploads/avatars';
}
function gc_avatar_url(int $userId): ?string {
  if ($userId <= 0) return null;
  $dir = gc_avatar_dir();
  $candidates = ['webp','png','jpg','jpeg'];
  foreach ($candidates as $ext) {
    $fs = $dir . '/u' . $userId . '.' . $ext;
    if (is_file($fs)) {
      $v = @filemtime($fs) ?: time();
      return '/uploads/avatars/u' . $userId . '.' . $ext . '?v=' . $v;
    }
  }
  return null;
}
function gc_delete_avatar_files(int $userId): void {
  if ($userId <= 0) return;
  $dir = gc_avatar_dir();
  foreach (['webp','png','jpg','jpeg'] as $ext) {
    $fs = $dir . '/u' . $userId . '.' . $ext;
    if (is_file($fs)) @unlink($fs);
  }
}
/* ---------- end AVATAR HELPERS ---------- */


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

function iptv_secret_key(): string {
  static $cached = null;
  if ($cached !== null) return $cached;

  $cfg = iptv_config();
  $cfg_key = (string)($cfg['secret_key'] ?? '');

  // Prefer DB-persisted secret key so reinstalling/overwriting files doesn't break decryption.
  $db_key = '';
  if (function_exists('db')) {
    try {
      $pdo = db();
      // Ensure table exists (older installs may not have it yet).
      $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(190) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='secret_key' LIMIT 1");
      $st->execute();
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row && !empty($row['setting_value'])) {
        $db_key = (string)$row['setting_value'];
      } else {
        // First run: seed DB with config secret (or generate one). Do NOT overwrite if it already exists.
        $seed = $cfg_key;
        if ($seed === '') {
          // 64 chars printable
          $seed = bin2hex(random_bytes(32));
        }
        $ins = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value)
          VALUES ('secret_key', ?)
          ON DUPLICATE KEY UPDATE setting_value = setting_value");
        $ins->execute([$seed]);
        $db_key = $seed;
      }
    } catch (Throwable $e) {
      // ignore and fall back
      $db_key = '';
    }
  }

  $cached = ($db_key !== '') ? $db_key : $cfg_key;
  return $cached;
}

function iptv_encrypt(string $plain): string {
  if ($plain === '') return '';
  if (!function_exists('openssl_encrypt')) return '';
  $secret = iptv_secret_key();
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

  $secret = iptv_secret_key();
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

/**
 * Fetch a remote URL to a temporary file with sane limits.
 * Returns: ['ok'=>bool,'tmp'=>path,'name'=>filename,'code'=>httpCode,'effective'=>url,'error'=>string]
 */
function iptv_fetch_url_to_temp(string $url, int $maxBytes = 52428800, int $timeout = 20): array {
  $url = trim($url);
  if ($url === '') return ['ok'=>false,'tmp'=>'','name'=>'','code'=>0,'effective'=>'','error'=>'Empty URL'];
  $parts = @parse_url($url);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  if (!in_array($scheme, ['http','https'], true)) {
    return ['ok'=>false,'tmp'=>'','name'=>'','code'=>0,'effective'=>'','error'=>'Only http/https URLs are allowed'];
  }

  $path = (string)($parts['path'] ?? '');
  $name = basename($path);
  if ($name === '' || $name === '/' || $name === '.' || $name === '..') $name = 'remote.m3u';
  // keep filename simple
  $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
  if (!preg_match('/\.m3u8?($|\.)/i', $name)) $name .= '.m3u';

  $tmp = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
         'iptv_m3u_' . bin2hex(random_bytes(8)) . '.tmp';

  $code = 0;
  $effective = $url;
  $err = '';

  // Prefer cURL when available
  if (function_exists('curl_init')) {
    $fh = @fopen($tmp, 'wb');
    if (!$fh) return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>0,'effective'=>$url,'error'=>'Failed to create temp file'];

    $downloaded = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
      CURLOPT_USERAGENT => 'IPTV-Panel-M3U-Importer/1.0',
      CURLOPT_FILE => $fh,
      CURLOPT_RETURNTRANSFER => false,
      CURLOPT_HEADER => false,
      CURLOPT_ENCODING => '',
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_NOPROGRESS => false,
      CURLOPT_PROGRESSFUNCTION => function($resource, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$downloaded, $maxBytes) {
        $downloaded = (int)$dlNow;
        if ($maxBytes > 0 && $downloaded > $maxBytes) return 1; // abort
        return 0;
      }
    ]);

    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effective = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
    $err = (string)curl_error($ch);
    curl_close($ch);
    @fclose($fh);

    if ($ok === false) {
      @unlink($tmp);
      if ($downloaded > $maxBytes) {
        return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>$code,'effective'=>$effective,'error'=>'Remote file too large'];
      }
      return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>$code,'effective'=>$effective,'error'=>($err ?: 'Failed to download URL')];
    }
    if ($code >= 400 || @filesize($tmp) === 0) {
      @unlink($tmp);
      return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>$code,'effective'=>$effective,'error'=>'Remote server returned HTTP '.$code];
    }
  } else {
    // Fallback: streams (requires allow_url_fopen)
    $ctx = stream_context_create([
      'http' => [
        'timeout' => $timeout,
        'follow_location' => 1,
        'user_agent' => 'IPTV-Panel-M3U-Importer/1.0',
      ],
      'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
      ]
    ]);
    $in = @fopen($url, 'rb', false, $ctx);
    if (!$in) return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>0,'effective'=>$url,'error'=>'Failed to open URL (allow_url_fopen disabled?)'];
    $out = @fopen($tmp, 'wb');
    if (!$out) { @fclose($in); return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>0,'effective'=>$url,'error'=>'Failed to create temp file']; }

    $downloaded = 0;
    while (!feof($in)) {
      $buf = fread($in, 8192);
      if ($buf === false) break;
      $downloaded += strlen($buf);
      if ($maxBytes > 0 && $downloaded > $maxBytes) {
        @fclose($in); @fclose($out);
        @unlink($tmp);
        return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>0,'effective'=>$url,'error'=>'Remote file too large'];
      }
      fwrite($out, $buf);
    }
    @fclose($in);
    @fclose($out);

    if (@filesize($tmp) === 0) { @unlink($tmp); return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>0,'effective'=>$url,'error'=>'Empty download']; }
  }

  // quick signature check (don’t hard-fail on weird spacing)
  $head = @file_get_contents($tmp, false, null, 0, 256);
  if ($head === false) $head = '';
  $headTrim = ltrim($head);
  if ($headTrim !== '' && !str_starts_with($headTrim, '#EXTM3U') && !str_contains($headTrim, '#EXTINF')) {
    // Not necessarily fatal, but most bad URLs will be HTML or JSON.
    // Keep it strict: fail to avoid importing garbage.
    @unlink($tmp);
    return ['ok'=>false,'tmp'=>'','name'=>$name,'code'=>$code,'effective'=>$effective,'error'=>'URL did not look like an M3U playlist'];
  }

  return ['ok'=>true,'tmp'=>$tmp,'name'=>$name,'code'=>$code,'effective'=>$effective,'error'=>''];
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
  return substr(hash('sha256', $ua), 0, 32);
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
 * Abuse bans are stored in abuse_bans.
 * We keep IP bans and User bans as separate concepts:
 *  - IP ban: blocks an IP address regardless of account.
 *  - User ban: blocks a specific account regardless of IP.
 * Table: abuse_bans (created by runtime migrations).
 */
function abuse_ip_ban_lookup(PDO $pdo, string $ip): ?array {
  try {
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
  } catch (Throwable $e) {
    return null;
  }
  return null;
}

function abuse_user_ban_lookup(PDO $pdo, int $user_id): ?array {
  if ($user_id < 1) return null;
  try {
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
  } catch (Throwable $e) {
    return null;
  }
  return null;
}

/**
 * Back-compat helper: returns the first active ban encountered.
 * Prefer calling abuse_ip_ban_lookup() and abuse_user_ban_lookup() separately.
 */
function abuse_ban_lookup(PDO $pdo, string $ip, ?int $user_id=null): ?array {
  $row = abuse_ip_ban_lookup($pdo, $ip);
  if ($row) return $row;
  if ($user_id) {
    $row = abuse_user_ban_lookup($pdo, (int)$user_id);
    if ($row) return $row;
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
  // Token signatures are validated with HMAC.
  // By default, we do NOT hard-fail on exp<now because subscription enforcement is authoritative.
  // This prevents "invalid login" fail videos when clients replay old URLs while the subscription is still active.
  // To restore strict token expiry behavior, set config.local.php: ['enforce_token_exp' => true]
  $cfg = require __DIR__ . '/config.php';
  $enforce_exp = (bool)($cfg['enforce_token_exp'] ?? false);
  if ($enforce_exp && $exp < time()) return false;

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



/* ---------- MAINTENANCE MODE (frontend + portal) ---------- */

function gc_maintenance_get(PDO $pdo): array {
  $enabled = (system_setting_get($pdo, 'maintenance_mode', '0') === '1');
  $message = (string)system_setting_get(
    $pdo,
    'maintenance_message',
    'Service is temporarily under maintenance. Please try again later.'
  );
  $video_url = trim((string)system_setting_get($pdo, 'maintenance_video_url', ''));
  $custom_html = (string)system_setting_get($pdo, 'maintenance_custom_html', '');
  return [
    'enabled' => $enabled,
    'message' => $message,
    'video_url' => $video_url,
    'custom_html' => $custom_html,
  ];
}

function gc_is_json_request(): bool {
  $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  if ($accept !== '' && strpos($accept, 'application/json') !== false) return true;

  $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
  if ($xhr === 'xmlhttprequest') return true;

  $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
  if ($uri !== '' && preg_match('~/(api|ajax)~i', $uri)) return true;

  return false;
}

function gc_render_maintenance_html(string $message, string $video_url = ''): void {
  $title = 'Maintenance Mode';
  $msg = htmlspecialchars((string)$message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $vid = trim((string)$video_url);
  $vidEsc = htmlspecialchars($vid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  http_response_code(503);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Retry-After: 300');

  echo "<!doctype html>\n";
  echo "<html><head>\n";
  echo "  <meta charset=\"utf-8\">\n";
  echo "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
  echo "  <title>{$title}</title>\n";
  echo "  <link rel=\"icon\" href=\"/favicon.ico\">\n";
  echo "  <link rel=\"stylesheet\" href=\"/portal/assets/portal.css\">\n";
  echo "  <link rel=\"stylesheet\" href=\"/portal/assets/public.css\">\n";
  echo "  <style>\n";
  echo "    .gc-maint-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}\n";
  echo "    .gc-maint-card{max-width:760px;width:100%;}\n";
  echo "    .gc-maint-card .btnrow{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;}\n";
  echo "    .gc-maint-card .btnrow a{display:inline-flex;align-items:center;justify-content:center;}\n";
  echo "  </style>\n";
  echo "</head><body>\n";
  echo "<div class=\"gc-maint-wrap\">\n";
  echo "  <div class=\"card hero gc-maint-card\">\n";
  echo "    <h1>We&rsquo;ll be back soon</h1>\n";
  echo "    <p class=\"muted\">{$msg}</p>\n";
  if ($vid !== '') {
    echo "    <div class=\"btnrow\">\n";
    echo "      <a class=\"btn primary\" href=\"{$vidEsc}\" target=\"_blank\" rel=\"noopener\">Open maintenance video</a>\n";
    echo "      <a class=\"btn\" href=\"/\">Refresh</a>\n";
    echo "    </div>\n";
  } else {
    echo "    <div class=\"btnrow\">\n";
    echo "      <a class=\"btn primary\" href=\"/\">Refresh</a>\n";
    echo "    </div>\n";
  }
  echo "    <div class=\"hero-sub\" style=\"margin-top:14px;\">\n";
  echo "      <span class=\"chip\">" . gc_svg_icon('wrench', 'chip-ico') . " Maintenance</span>\n";
  echo "      <span class=\"chip\">" . gc_svg_icon('clock', 'chip-ico') . " Please try again soon</span>\n";
  echo "    </div>\n";
  echo "  </div>\n";
  echo "</div>\n";
  echo "</body></html>\n";
}


function gc_render_maintenance_custom_html(string $custom_html, string $message, string $video_url = ''): void {
  $html = (string)$custom_html;
  $msg = (string)$message;
  $vid = trim((string)$video_url);

  // Allow simple placeholders in the custom HTML:
  //  - {{message}} (escaped)
  //  - {{video_url}} (escaped)
  //  - {{message_raw}} (raw)
  //  - {{video_url_raw}} (raw)
  $msgEsc = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $vidEsc = htmlspecialchars($vid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  $out = str_replace(
    ['{{message}}','{{video_url}}','{{message_raw}}','{{video_url_raw}}'],
    [$msgEsc, $vidEsc, $msg, $vid],
    $html
  );

  http_response_code(503);
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Retry-After: 300');

  echo $out;
}



function gc_enforce_maintenance(PDO $pdo, array $opts = []): void {
  $m = gc_maintenance_get($pdo);
  if (empty($m['enabled'])) return;

  $format = $opts['format'] ?? null;
  if ($format === null) {
    $format = gc_is_json_request() ? 'json' : 'html';
  }

  if ($format === 'json') {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Retry-After: 300');
    echo json_encode([
      'maintenance' => true,
      'message' => (string)($m['message'] ?? 'Maintenance mode'),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }

  $custom = trim((string)($m['custom_html'] ?? ''));
  if ($custom !== '') {
    gc_render_maintenance_custom_html($custom, (string)($m['message'] ?? ''), (string)($m['video_url'] ?? ''));
    exit;
  }

  gc_render_maintenance_html((string)($m['message'] ?? ''), (string)($m['video_url'] ?? ''));
  exit;
}



/* ---------- SUBSCRIPTIONS (single active subscription guard) ---------- */

/**
 * Fetch the most relevant active subscription for a user.
 * Active = status='active' AND ends_at in the future (or NULL).
 */
function iptv_active_subscription(PDO $pdo, int $user_id): ?array {
  try {
    $st = $pdo->prepare("\n      SELECT s.*,\n             p.name AS plan_name,\n             p.duration_days,\n             p.max_streams,\n             p.max_devices\n      FROM subscriptions s\n      JOIN plans p ON p.id=s.plan_id\n      WHERE s.user_id=? AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())\n      ORDER BY s.ends_at DESC\n      LIMIT 1\n    ");
    $st->execute([$user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row : null;
  } catch (Throwable $e) {
    return null;
  }
}

/* ---------- SIMPLE CACHE (APCu / filesystem fallback) ---------- */
/**
 * Small cache helper to reduce DB load on hot paths (stream start).
 * Uses APCu when available; otherwise falls back to a tiny filesystem cache in sys_get_temp_dir().
 */
function iptv_cache_get(string $key) {
  // APCu first
  if (function_exists('apcu_fetch')) {
    $ok = false;
    $val = apcu_fetch($key, $ok);
    if ($ok) return $val;
  }

  $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'iptv_cache';
  $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
  if (!is_file($file)) return null;
  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') return null;
  $data = json_decode($raw, true);
  if (!is_array($data)) return null;
  $exp = (int)($data['exp'] ?? 0);
  if ($exp > 0 && $exp < time()) {
    @unlink($file);
    return null;
  }
  if (!array_key_exists('val', $data)) return null;
  $ser = base64_decode((string)$data['val'], true);
  if ($ser === false) return null;
  $val = @unserialize($ser, ['allowed_classes' => false]);
  return $val;
}

function iptv_cache_set(string $key, $value, int $ttl): void {
  if ($ttl <= 0) return;
  // APCu
  if (function_exists('apcu_store')) {
    @apcu_store($key, $value, $ttl);
    return;
  }

  $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'iptv_cache';
  if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
  }
  if (!is_dir($dir) || !is_writable($dir)) return;
  $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
  $payload = [
    'exp' => time() + $ttl,
    'val' => base64_encode(serialize($value)),
  ];
  @file_put_contents($file, json_encode($payload), LOCK_EX);
}

/**
 * Cached active subscription lookup. TTL should be small (30-120s).
 */
function iptv_cached_active_subscription(PDO $pdo, int $user_id, int $ttl = 60): ?array {
  $ttl = (int)$ttl;
  if ($ttl <= 0) return iptv_active_subscription($pdo, $user_id);
  $key = 'iptv_active_sub_' . $user_id;
  $cached = iptv_cache_get($key);
  if ($cached !== null) {
    // cached can be array or false sentinel
    return ($cached === false) ? null : $cached;
  }
  $sub = iptv_active_subscription($pdo, $user_id);
  // Use false sentinel for 'no sub' so we still cache misses.
  iptv_cache_set($key, $sub ?? false, $ttl);
  return $sub;
}


/**
 * Expire any subscriptions that have passed their end date.
 *
 * Why this exists:
 * - Access control already checks ends_at > NOW() (so playback blocks correctly),
 *   but admin screens and reports often display the stored status field.
 * - This keeps subscriptions.status in sync with reality.
 *
 * If $user_id is provided, only that user's subscriptions are updated.
 * Returns number of rows updated.
 */
function iptv_expire_due_subscriptions(PDO $pdo, int $user_id = 0): int {
  try {
    if ($user_id > 0) {
      $st = $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE user_id=? AND status='active' AND ends_at IS NOT NULL AND ends_at<=NOW()");
      $st->execute([$user_id]);
      return (int)$st->rowCount();
    }
    $st = $pdo->prepare("UPDATE subscriptions SET status='expired' WHERE status='active' AND ends_at IS NOT NULL AND ends_at<=NOW()");
    $st->execute();
    return (int)$st->rowCount();
  } catch (Throwable $e) {
    return 0;
  }
}

/**
 * Return true if a user currently has an active subscription.
 */
function iptv_user_has_active_subscription(PDO $pdo, int $user_id): bool {
  return iptv_active_subscription($pdo, $user_id) !== null;
}

/**
 * Cancel any currently-active subscriptions for a user.
 * This is used to enforce "one active subscription at a time".
 */
function iptv_cancel_other_active_subscriptions(PDO $pdo, int $user_id, int $keep_sub_id = 0): void {
  try {
    if ($keep_sub_id > 0) {
      $pdo->prepare("UPDATE subscriptions SET status='cancelled' WHERE user_id=? AND id<>? AND status='active' AND (ends_at IS NULL OR ends_at>NOW())")
          ->execute([$user_id, $keep_sub_id]);
    } else {
      $pdo->prepare("UPDATE subscriptions SET status='cancelled' WHERE user_id=? AND status='active' AND (ends_at IS NULL OR ends_at>NOW())")
          ->execute([$user_id]);
    }
  } catch (Throwable $e) {
    // ignore
  }
}

/**
 * Inline SVG icons (avoid emoji baseline/OS font weirdness).
 * Usage: echo gc_svg_icon('home', 'extra-class');
 */
function gc_svg_icon(string $name, string $class = '', array $attrs = []): string {
  static $icons = null;
  if ($icons === null) {
    $icons = [
      'home' => '<path d="M3 11l9-8 9 8v10a2 2 0 0 1-2 2h-4v-6H9v6H5a2 2 0 0 1-2-2z"/>',
      'tv' => '<rect x="3" y="7" width="18" height="12" rx="2"/><path d="M7 7l5-4 5 4"/><path d="M8 21h8"/>',
      'movies' => '<path d="M3 7h18l-2-4H5L3 7z"/><rect x="3" y="7" width="18" height="14" rx="2"/><path d="M7 7l3 4"/><path d="M12 7l3 4"/><path d="M17 7l3 4"/>',
      'series' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 9h10"/><path d="M7 13h10"/><path d="M7 17h6"/>',
      'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
      'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
      'support' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="M4.93 4.93l4.24 4.24"/><path d="M14.83 14.83l4.24 4.24"/><path d="M14.83 9.17l4.24-4.24"/><path d="M4.93 19.07l4.24-4.24"/>',
      'star' => '<path d="M12 2l3.09 6.26L22 9.24l-5 4.87L18.18 22 12 18.56 5.82 22 7 14.11 2 9.24l6.91-.98z"/>',
      'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
      'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-1.41 3.41h-.2a1.65 1.65 0 0 0-1.54 1.1l-.02.08a2 2 0 0 1-3.77 0l-.02-.08a1.65 1.65 0 0 0-1.54-1.1H10a1.65 1.65 0 0 0-1.82.33l-.06.06A2 2 0 0 1 4.7 19.4l.06-.06A1.65 1.65 0 0 0 5.1 17.5l-.01-.08a1.65 1.65 0 0 0-1.1-1.54l-.08-.02a2 2 0 0 1 0-3.77l.08-.02a1.65 1.65 0 0 0 1.1-1.54l.01-.08A1.65 1.65 0 0 0 4.76 8.1l-.06-.06A2 2 0 0 1 8.1 4.7l.06.06A1.65 1.65 0 0 0 10 5.1l.08-.01a1.65 1.65 0 0 0 1.54-1.1l.02-.08a2 2 0 0 1 3.77 0l.02.08a1.65 1.65 0 0 0 1.54 1.1l.08.01a1.65 1.65 0 0 0 1.82-.33l.06-.06A2 2 0 0 1 19.4 8.1l-.06.06A1.65 1.65 0 0 0 18.9 10l.01.08a1.65 1.65 0 0 0 1.1 1.54l.08.02a2 2 0 0 1 0 3.77l-.08.02a1.65 1.65 0 0 0-1.1 1.54z"/>',
      'power' => '<path d="M12 2v10"/><path d="M5.5 5.5a9 9 0 1 0 13 0"/>',
      'x' => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
      'alert' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
      'check' => '<path d="M20 6L9 17l-5-5"/>',
      'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
      'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
      'chevron-up' => '<path d="M18 15l-6-6-6 6"/>',
      'chevron-left' => '<path d="M15 18l-6-6 6-6"/>',
      'chevron-right' => '<path d="M9 18l6-6-6-6"/>',
      'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h9a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
      'receipt' => '<path d="M6 2h12v20l-2-1-2 1-2-1-2 1-2-1-2 1z"/><path d="M8 6h8"/><path d="M8 10h8"/><path d="M8 14h6"/>',
      'bolt' => '<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>',
      'repeat' => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
      'wrench' => '<path d="M14.7 6.3a4 4 0 0 0-5.6 5.6l-6.6 6.6a2 2 0 0 0 2.8 2.8l6.6-6.6a4 4 0 0 0 5.6-5.6l-2.1 2.1-3-3z"/>',
      'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
      'grip' => '<path d="M10 8h.01"/><path d="M14 8h.01"/><path d="M10 12h.01"/><path d="M14 12h.01"/><path d="M10 16h.01"/><path d="M14 16h.01"/>',
    ];
  }

  if (!isset($icons[$name])) return '';

  $cls = trim('gc-ico ' . $class);
  $extra = '';
  foreach ($attrs as $k => $v) {
    $k = preg_replace('/[^a-zA-Z0-9_:-]/', '', (string)$k);
    $extra .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
  }

  return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' . $extra . '>' . $icons[$name] . '</svg>';
}

