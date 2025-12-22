<?php
// db.php
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/migration.php';

function db(): PDO {
  static $pdo = null;
  global $config;

  if ($pdo) return $pdo;

  $db = $config['db'] ?? [];
  $host = $db['host'] ?? 'localhost';
  $name = $db['name'] ?? '';
  $user = $db['user'] ?? '';
  $pass = $db['pass'] ?? '';
  $charset = $db['charset'] ?? 'utf8mb4';

  if (!$name || !$user) {
    // Friendly installer redirect if not configured yet.
    if (PHP_SAPI !== 'cli') {
      $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
      if (!str_starts_with($path, '/install')) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $dest   = ($base ? $base : '') . '/install/';
        if (!headers_sent()) {
          header('Location: ' . $dest);
          exit;
        }
        // Headers already sent (output started earlier). Fall back to a JS/meta redirect.
        echo '<script>location.href=' . json_encode($dest) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($dest, ENT_QUOTES) . '"></noscript>';
        exit;
      }
    }
    throw new RuntimeException("DB config missing name/user. Run /install or set config.local.php.");
  }

  $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
  ]);

  // Best-effort runtime migrations (safe to call multiple times).
  try {
    if (function_exists('db_migrate')) db_migrate($pdo);
  } catch (Throwable $e) {
    // Don't break the app if the hosting DB user can't ALTER.
  }

  // Best-effort expiry sweep:
  // Keep subscriptions.status synced with ends_at so Admin screens show the correct state.
  // This runs at most about once per minute per PHP worker.
  try {
    $now = time();
    $should_run = false;
    $key = 'iptv:expire_sweep_ts';

    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
      $ok = false;
      $last = (int)apcu_fetch($key, $ok);
      if (!$ok || $last < ($now - 60)) {
        $should_run = true;
        @apcu_store($key, $now, 120);
      }
    } else {
      $tick = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'iptv_expire_sweep.ts';
      $last = is_file($tick) ? (int)@file_get_contents($tick) : 0;
      if ($last < ($now - 60)) {
        $should_run = true;
        @file_put_contents($tick, (string)$now, LOCK_EX);
      }
    }

    if ($should_run) {
      // Only expire dated subs. Unlimited subs use a far-future ends_at.
      $pdo->exec("UPDATE subscriptions SET status='expired' WHERE status='active' AND ends_at IS NOT NULL AND ends_at<=NOW()");
    }
  } catch (Throwable $e) {
    // ignore
  }

  return $pdo;
}
