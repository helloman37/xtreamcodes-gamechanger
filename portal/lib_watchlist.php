<?php
// portal/lib_watchlist.php
// Storage backends:
//   1) DB table watchlist_items (preferred)
//   2) JSON files (shared-host safe fallback)
//   3) Session (last resort)

function wl_norm_kind(string $kind): string {
  $kind = strtolower(trim($kind));
  // Normalize common aliases
  if ($kind === 'tv') $kind = 'tmdb_tv';
  if ($kind === 'movie_tmdb') $kind = 'tmdb_movie';
  if ($kind === 'tv_tmdb') $kind = 'tmdb_tv';
  return $kind;
}

function wl_db_schema(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS watchlist_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      kind VARCHAR(32) NOT NULL,
      item_id VARCHAR(64) NOT NULL,
      title VARCHAR(255) NOT NULL DEFAULT '',
      poster VARCHAR(512) NOT NULL DEFAULT '',
      url VARCHAR(512) NOT NULL DEFAULT '',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_kind_item (user_id, kind, item_id),
      KEY idx_user_created (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
}

// Backwards-compatible entrypoint used by older code.
// IMPORTANT: must NOT throw on hosts that disallow CREATE TABLE.
function wl_db_init(PDO $pdo): bool {
  return wl_db_ready($pdo);
}

function wl_db_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) return (bool)$ready;

  // 1) If table exists, this succeeds.
  try {
    $pdo->query("SELECT 1 FROM watchlist_items LIMIT 1");
    $ready = true;
    return true;
  } catch (Throwable $t) {
    // continue
  }

  // 2) Try to create it (may fail on shared hosts with limited DB perms).
  try {
    wl_db_schema($pdo);
    $ready = true;
    return true;
  } catch (Throwable $t) {
    $ready = false;
    return false;
  }
}

function wl_storage_dir(): ?string {
  static $dir = '__unset__';
  if ($dir !== '__unset__') return $dir ?: null;

  $candidates = [];
  $appCache = __DIR__ . '/../../cache';
  $candidates[] = $appCache;
  $tmp = @sys_get_temp_dir();
  if (is_string($tmp) && $tmp !== '') $candidates[] = rtrim($tmp, '/\\') . '/gc_watchlist';

  foreach ($candidates as $base) {
    $base = rtrim((string)$base, '/\\');
    if ($base === '') continue;
    $d = $base . '/watchlist';
    if (!is_dir($d)) {
      @mkdir($d, 0755, true);
    }
    if (!is_dir($d)) continue;
    // Writable test
    $rand = '';
    try { $rand = bin2hex(random_bytes(4)); } catch (Throwable $t) { $rand = uniqid('', true); }
    $test = $d . '/.wl_test_' . preg_replace('~[^a-z0-9\._-]~i', '', $rand);
    $ok = @file_put_contents($test, '1', LOCK_EX);
    if ($ok !== false) {
      @unlink($test);
      $dir = $d;
      return $d;
    }
  }

  $dir = '';
  return null;
}

function wl_backend(PDO $pdo): string {
  static $backend = null;
  if ($backend !== null) return $backend;

  if ($pdo instanceof PDO && wl_db_ready($pdo)) {
    $backend = 'db';
    return $backend;
  }

  if (wl_storage_dir()) {
    $backend = 'file';
    return $backend;
  }

  $backend = 'session';
  return $backend;
}

function wl_file_path(int $userId): ?string {
  $dir = wl_storage_dir();
  if (!$dir) return null;
  return rtrim($dir, '/\\') . '/u' . (int)$userId . '.json';
}

function wl_file_read_map(int $userId): array {
  $path = wl_file_path($userId);
  if (!$path || !is_file($path)) return [];
  $raw = @file_get_contents($path);
  if (!is_string($raw) || trim($raw) === '') return [];
  $j = json_decode($raw, true);
  $map = is_array($j) ? ($j['items'] ?? null) : null;
  return is_array($map) ? $map : [];
}

function wl_file_write_map(int $userId, array $map): void {
  $path = wl_file_path($userId);
  if (!$path) throw new RuntimeException('watchlist_storage_unwritable');
  $payload = json_encode(['items' => $map], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($payload === false) $payload = '{"items":{}}';
  $ok = @file_put_contents($path, $payload, LOCK_EX);
  if ($ok === false) throw new RuntimeException('watchlist_storage_unwritable');
}

function wl_session_key(int $userId): string {
  return 'wl_items_u' . (int)$userId;
}

function wl_session_read_map(int $userId): array {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
  }
  $k = wl_session_key($userId);
  $map = $_SESSION[$k] ?? [];
  return is_array($map) ? $map : [];
}

function wl_session_write_map(int $userId, array $map): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
  }
  $_SESSION[wl_session_key($userId)] = $map;
}

// ----------------------------
// Public API (DB/File/Session)
// ----------------------------

function wl_exists(PDO $pdo, int $userId, string $kind, string $itemId): bool {
  $kind = wl_norm_kind($kind);
  $itemId = (string)$itemId;

  $b = wl_backend($pdo);
  if ($b === 'db') {
    try {
      $st = $pdo->prepare("SELECT 1 FROM watchlist_items WHERE user_id=? AND kind=? AND item_id=? LIMIT 1");
      $st->execute([(int)$userId, $kind, $itemId]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $t) {
      // fallthrough
    }
  }

  $key = $kind . ':' . $itemId;
  if ($b === 'file') {
    $map = wl_file_read_map($userId);
    return isset($map[$key]);
  }

  $map = wl_session_read_map($userId);
  return isset($map[$key]);
}

function wl_add(PDO $pdo, int $userId, string $kind, string $itemId, string $title='', string $poster='', string $url=''): void {
  $kind = wl_norm_kind($kind);
  $itemId = (string)$itemId;
  $title = (string)$title;
  $poster = (string)$poster;
  $url = (string)$url;

  $b = wl_backend($pdo);
  if ($b === 'db') {
    try {
      $st = $pdo->prepare("INSERT IGNORE INTO watchlist_items (user_id, kind, item_id, title, poster, url) VALUES (?,?,?,?,?,?)");
      $st->execute([(int)$userId, $kind, $itemId, $title, $poster, $url]);
      return;
    } catch (Throwable $t) {
      // fallthrough
    }
  }

  $key = $kind . ':' . $itemId;
  $item = [
    'kind' => $kind,
    'item_id' => $itemId,
    'title' => $title,
    'poster' => $poster,
    'url' => $url,
    'created_at' => date('Y-m-d H:i:s'),
  ];

  if ($b === 'file') {
    $map = wl_file_read_map($userId);
    if (!isset($map[$key])) {
      $map[$key] = $item;
      wl_file_write_map($userId, $map);
    }
    return;
  }

  $map = wl_session_read_map($userId);
  if (!isset($map[$key])) {
    $map[$key] = $item;
    wl_session_write_map($userId, $map);
  }
}

function wl_remove(PDO $pdo, int $userId, string $kind, string $itemId): void {
  $kind = wl_norm_kind($kind);
  $itemId = (string)$itemId;

  $b = wl_backend($pdo);
  if ($b === 'db') {
    try {
      $st = $pdo->prepare("DELETE FROM watchlist_items WHERE user_id=? AND kind=? AND item_id=?");
      $st->execute([(int)$userId, $kind, $itemId]);
      return;
    } catch (Throwable $t) {
      // fallthrough
    }
  }

  $key = $kind . ':' . $itemId;
  if ($b === 'file') {
    $map = wl_file_read_map($userId);
    if (isset($map[$key])) {
      unset($map[$key]);
      wl_file_write_map($userId, $map);
    }
    return;
  }

  $map = wl_session_read_map($userId);
  if (isset($map[$key])) {
    unset($map[$key]);
    wl_session_write_map($userId, $map);
  }
}

function wl_toggle(PDO $pdo, int $userId, string $kind, string $itemId, string $title='', string $poster='', string $url=''): bool {
  if (wl_exists($pdo, $userId, $kind, $itemId)) {
    wl_remove($pdo, $userId, $kind, $itemId);
    return false;
  }
  wl_add($pdo, $userId, $kind, $itemId, $title, $poster, $url);
  return true;
}

function wl_list(PDO $pdo, int $userId): array {
  $b = wl_backend($pdo);
  if ($b === 'db') {
    try {
      $st = $pdo->prepare("SELECT kind,item_id,title,poster,url,created_at FROM watchlist_items WHERE user_id=? ORDER BY created_at DESC");
      $st->execute([(int)$userId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $t) {
      // fallthrough
    }
  }

  try {
    $map = ($b === 'file') ? wl_file_read_map($userId) : wl_session_read_map($userId);
    $items = array_values($map ?: []);
    usort($items, function($a, $b){
      $ta = (string)($a['created_at'] ?? '');
      $tb = (string)($b['created_at'] ?? '');
      return strcmp($tb, $ta);
    });
    return $items;
  } catch (Throwable $t) {
    return [];
  }
}

// Admin helpers
function wl_admin_wipe_all(PDO $pdo): array {
  $b = wl_backend($pdo);
  if ($b === 'db') {
    $pdo->exec("TRUNCATE TABLE watchlist_items");
    return ['backend' => 'db', 'wiped' => true];
  }
  if ($b === 'file') {
    $dir = wl_storage_dir();
    if ($dir && is_dir($dir)) {
      foreach (glob(rtrim($dir, '/\\') . '/u*.json') ?: [] as $f) {
        @unlink($f);
      }
    }
    return ['backend' => 'file', 'wiped' => true];
  }
  // session wipe isn't global; return best-effort
  return ['backend' => 'session', 'wiped' => false];
}
