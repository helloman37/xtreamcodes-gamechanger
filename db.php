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

  return $pdo;
}
