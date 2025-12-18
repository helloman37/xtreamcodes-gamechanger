<?php
// portal/_init.php
// Subscriber portal bootstrap (reuses storefront customer session).

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../plugins_core.php';
require_once __DIR__ . '/../api_common.php';

session_start();

// Storefront login session is the gate for portal access.
if (empty($_SESSION['store_user'])) {
  header('Location: /login.php');
  exit;
}

$pdo = db();

// Plugin bridge (for portal UX)
$GC_LEGALVOD_CFG = null;
try {
  if (function_exists('gc_plugins_db_init')) {
    gc_plugins_db_init($pdo);
    $enabled = false;
    foreach (gc_plugins_enabled($pdo) as $prow) {
      if (($prow['id'] ?? '') === 'legalvod') { $enabled = true; break; }
    }
    if ($enabled) {
      $base = (string)gc_plugin_settings_get($pdo, 'legalvod', 'base_url', '');
      $movie_tpl = (string)gc_plugin_settings_get($pdo, 'legalvod', 'movie_template', '/movie/{id}/');
      $tv_tpl = (string)gc_plugin_settings_get($pdo, 'legalvod', 'tv_template', '/tv/{id}/{season}/{episode}/');
      $GC_LEGALVOD_CFG = [
        'enabled' => true,
        'base_url' => $base,
        'movie_template' => $movie_tpl,
        'tv_template' => $tv_tpl,
      ];
    }
  }
} catch (Throwable $t) {
  $GC_LEGALVOD_CFG = null;
}


// Normalize store_user session (some pages store just id).
$userId = is_array($_SESSION['store_user']) ? (int)($_SESSION['store_user']['id'] ?? 0) : (int)$_SESSION['store_user'];
if ($userId < 1) {
  header('Location: /logout.php');
  exit;
}

$st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user || ($user['status'] ?? 'active') !== 'active') {
  header('Location: /logout.php');
  exit;
}

// Must have an active subscription to use the portal.
$subSql = "SELECT s.*, p.name AS plan_name, p.max_streams, p.max_devices
FROM subscriptions s
JOIN plans p ON p.id=s.plan_id
WHERE s.user_id=? AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())
ORDER BY s.ends_at DESC LIMIT 1";
$subSt = $pdo->prepare($subSql);
$subSt->execute([$userId]);
$sub = $subSt->fetch(PDO::FETCH_ASSOC);

$config = require __DIR__ . '/../config.php';
$token_ttl = (int)($config['token_ttl'] ?? 3600);

// Convenience flags
$allowAdult = !empty($user['allow_adult']);

// Package restrictions (empty => no restriction)
$pkg_ids = user_package_ids($pdo, $userId);
ensure_categories($pdo);

function portal_make_play_url(string $username, int $id, string $type='live', string $ext='m3u8'): array {
  $config = require __DIR__ . '/../config.php';
  $ttl = (int)($config['token_ttl'] ?? 3600);
  $exp = time() + max(60, $ttl);
  $token = make_token($username, $id, $exp, $type);

  $type = strtolower($type);
  $prefix = ($type === 'movie') ? '/movie' : (($type === 'episode') ? '/series' : '/live');
  $ext = preg_replace('~[^a-z0-9]~i', '', (string)$ext);
  if ($ext === '') $ext = 'm3u8';
  $url = $prefix . '/' . rawurlencode($username) . '/' . rawurlencode($token) . '/' . $id . '.' . $ext . '?exp=' . $exp;
  return [$url, $exp, $token];
}

function portal_tmdb_key(PDO $pdo, array $user): string {
  // Fallback order: user.tmdb_api_key -> system_settings -> config.local.php/config.php
  $k = trim((string)($user['tmdb_api_key'] ?? ''));
  if ($k !== '') return $k;
  $k = trim((string)system_setting_get($pdo, 'tmdb_api_key', ''));
  if ($k !== '') return $k;
  $cfg = require __DIR__ . '/../config.php';
  $k = trim((string)($cfg['tmdb_api_key'] ?? ''));
  return $k;
}
