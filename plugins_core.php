<?php
// Lightweight plugin framework for Gamechanger panel.
//
// Conventions:
// - Plugins live in /plugins/<id>/
// - Each plugin must include /plugins/<id>/manifest.json
// - Plugin admin pages are rendered via /admin/plugin.php (wrapper),
//   so plugins can share the panel layout/theme.

function gc_plugins_dir(): string {
  return rtrim(__DIR__, '/\\') . DIRECTORY_SEPARATOR . 'plugins';
}

// PHP 7+ safe helpers (avoid relying on PHP 8 string helpers)
function gc_str_starts_with(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  return strncmp($haystack, $needle, strlen($needle)) === 0;
}
function gc_str_ends_with(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  $len = strlen($needle);
  return $len <= strlen($haystack) && substr($haystack, -$len) === $needle;
}
function gc_str_contains(string $haystack, string $needle): bool {
  if ($needle === '') return true;
  return strpos($haystack, $needle) !== false;
}

function gc_plugins_ensure_dir(): bool {
  $dir = gc_plugins_dir();
  if (!is_dir($dir)) {
    return @mkdir($dir, 0775, true);
  }
  return true;
}

function gc_plugins_db_init(PDO $pdo): void {
  // plugins table
  $pdo->exec("CREATE TABLE IF NOT EXISTS plugins (
    id VARCHAR(64) PRIMARY KEY,
    name VARCHAR(140) NOT NULL,
    version VARCHAR(32) NOT NULL,
    description TEXT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    manifest_json MEDIUMTEXT NULL,
    installed_at DATETIME NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // settings table
  $pdo->exec("CREATE TABLE IF NOT EXISTS plugin_settings (
    plugin_id VARCHAR(64) NOT NULL,
    k VARCHAR(120) NOT NULL,
    v MEDIUMTEXT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (plugin_id, k)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}function gc_plugins_sync_fs(PDO $pdo): void {
  $dir = gc_plugins_dir();
  if (!is_dir($dir)) return;

  // Preserve enabled state from DB
  $existing_enabled = [];
  try {
    $rows = $pdo->query("SELECT id, enabled FROM plugins")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $existing_enabled[(string)$r['id']] = (int)$r['enabled'];
    }
  } catch (Throwable $e) {
    // ignore
  }

  $items = @scandir($dir);
  if (!$items) return;

  $seen = [];
  foreach ($items as $name) {
    if ($name === '.' || $name === '..') continue;
    if ($name !== '' && $name[0] === '.') continue;

    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($path)) continue;

    $manifest_path = $path . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifest_path)) continue;

    $pid = preg_replace('~[^a-zA-Z0-9_-]~', '', (string)$name);
    if ($pid === '') continue;

    $enabled = $existing_enabled[$pid] ?? 0;

    try {
      $manifest = gc_plugin_manifest_load($pid);
      gc_plugin_upsert($pdo, $pid, $manifest, $enabled);
    } catch (Throwable $e) {
      // Keep it visible but mark it broken
      $manifest = [
        'id' => $pid,
        'name' => $pid,
        'version' => '0.0.0',
        'description' => 'manifest.json error: ' . $e->getMessage(),
        'routes' => [],
        'admin' => [],
      ];
      gc_plugin_upsert($pdo, $pid, $manifest, $enabled);
    }

    $seen[$pid] = true;
  }

  // If a plugin folder was deleted, disable it in DB so it won't be dispatched.
  foreach ($existing_enabled as $pid => $en) {
    if (!isset($seen[$pid])) {
      $stmt = $pdo->prepare("UPDATE plugins SET enabled=0 WHERE id=:id");
      $stmt->execute([':id' => $pid]);
    }
  }
}



function gc_plugin_manifest_path(string $plugin_id): string {
  return gc_plugins_dir() . DIRECTORY_SEPARATOR . $plugin_id . DIRECTORY_SEPARATOR . 'manifest.json';
}

function gc_plugin_manifest_load(string $plugin_id): array {
  $path = gc_plugin_manifest_path($plugin_id);
  if (!is_file($path)) {
    throw new RuntimeException('manifest.json missing');
  }
  $raw = file_get_contents($path);
  $m = json_decode($raw, true);
  if (!is_array($m)) {
    throw new RuntimeException('Invalid manifest.json');
  }
  $m['id'] = $m['id'] ?? $plugin_id;
  $m['name'] = $m['name'] ?? $plugin_id;
  $m['version'] = $m['version'] ?? '0.0.0';
  $m['description'] = $m['description'] ?? '';
  $m['routes'] = is_array($m['routes'] ?? null) ? $m['routes'] : [];
  $m['admin'] = is_array($m['admin'] ?? null) ? $m['admin'] : [];
  return $m;
}

function gc_plugin_upsert(PDO $pdo, string $plugin_id, array $manifest, int $enabled = 0): void {
  $now = date('Y-m-d H:i:s');
  $stmt = $pdo->prepare("INSERT INTO plugins (id,name,version,description,enabled,manifest_json,installed_at,updated_at)
    VALUES (:id,:name,:version,:description,:enabled,:manifest,:installed_at,:updated_at)
    ON DUPLICATE KEY UPDATE
      name=VALUES(name),
      version=VALUES(version),
      description=VALUES(description),
      enabled=VALUES(enabled),
      manifest_json=VALUES(manifest_json),
      updated_at=VALUES(updated_at)");
  $stmt->execute([
    ':id' => $plugin_id,
    ':name' => (string)($manifest['name'] ?? $plugin_id),
    ':version' => (string)($manifest['version'] ?? '0.0.0'),
    ':description' => (string)($manifest['description'] ?? ''),
    ':enabled' => (int)$enabled,
    ':manifest' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':installed_at' => $now,
    ':updated_at' => $now,
  ]);
}

function gc_plugin_set_enabled(PDO $pdo, string $plugin_id, int $enabled): void {
  $stmt = $pdo->prepare("UPDATE plugins SET enabled=:en, updated_at=NOW() WHERE id=:id");
  $stmt->execute([':en' => (int)$enabled, ':id' => $plugin_id]);
}

function gc_plugins_list(PDO $pdo): array {
  $rows = $pdo->query("SELECT * FROM plugins ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
  return $rows ?: [];
}

function gc_plugins_enabled(PDO $pdo): array {
  $rows = $pdo->query("SELECT * FROM plugins WHERE enabled=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
  return $rows ?: [];
}

function gc_plugin_settings_get(PDO $pdo, string $plugin_id, string $key, $default = null) {
  $stmt = $pdo->prepare("SELECT v FROM plugin_settings WHERE plugin_id=:pid AND k=:k");
  $stmt->execute([':pid' => $plugin_id, ':k' => $key]);
  $v = $stmt->fetchColumn();
  if ($v === false || $v === null) return $default;
  $decoded = json_decode((string)$v, true);
  return ($decoded === null && trim((string)$v) !== 'null') ? $v : $decoded;
}

function gc_plugin_settings_set(PDO $pdo, string $plugin_id, string $key, $value): void {
  $now = date('Y-m-d H:i:s');
  $v = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $stmt = $pdo->prepare("INSERT INTO plugin_settings (plugin_id,k,v,updated_at)
    VALUES (:pid,:k,:v,:u)
    ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=VALUES(updated_at)");
  $stmt->execute([':pid' => $plugin_id, ':k' => $key, ':v' => $v, ':u' => $now]);
}

function gc_rrmdir(string $dir): void {
  if (!is_dir($dir)) return;
  $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
  $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) {
    if ($file->isDir()) {
      @rmdir($file->getRealPath());
    } else {
      @unlink($file->getRealPath());
    }
  }
  @rmdir($dir);
}

function gc_plugins_dispatch(PDO $pdo, string $path): bool {
  // $path is URL path without leading slash, e.g. "movie/123" or "tv/1/2/3"
  foreach (gc_plugins_enabled($pdo) as $row) {
    $plugin_id = $row['id'];
    $manifest = json_decode($row['manifest_json'] ?? '', true);
    if (!is_array($manifest)) {
      // fall back to filesystem manifest
      try { $manifest = gc_plugin_manifest_load($plugin_id); } catch (Throwable $t) { continue; }
    }
    $routes = $manifest['routes'] ?? [];
    if (!is_array($routes)) $routes = [];
    foreach ($routes as $route) {
      $pattern = $route['pattern'] ?? null;
      $file = $route['file'] ?? null;
      if (!$pattern || !$file) continue;
      if (@preg_match('~' . $pattern . '~', $path, $m)) {
        $plugin_base = gc_plugins_dir() . DIRECTORY_SEPARATOR . $plugin_id;
        $target = realpath($plugin_base . DIRECTORY_SEPARATOR . $file);
        if (!$target || strpos($target, realpath($plugin_base)) !== 0) {
          http_response_code(500);
          echo 'Plugin route file invalid.';
          return true;
        }
        // expose params
        $GC_PLUGIN_ID = $plugin_id;
        $GC_PLUGIN_MANIFEST = $manifest;
        $GC_ROUTE_MATCH = $m;
        require $target;
        return true;
      }
    }
  }
  return false;
}
