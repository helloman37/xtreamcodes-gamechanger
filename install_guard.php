<?php
declare(strict_types=1);

/**
 * Simple install guard.
 * Include early in your bootstrap (helpers.php / init.php / index.php entrypoints).
 *
 * If not installed, redirects to /install.
 */
function iptv_is_installed(string $root): bool {
  if (is_file($root . '/install/installed.lock')) return true;
  // fallback: config.php exists with DB name + user
  $cfg = $root . '/config.php';
  if (!is_file($cfg)) return false;
  $arr = @require $cfg;
  if (!is_array($arr)) return false;
  $db = $arr['db'] ?? null;
  if (!is_array($db)) return false;
  return !empty($db['name']) && !empty($db['user']);
}

function iptv_install_redirect(string $root): void {
  $uri = $_SERVER['REQUEST_URI'] ?? '/';
  if (strpos($uri, '/install') === 0) return;
  header('Location: /install/');
  exit;
}
