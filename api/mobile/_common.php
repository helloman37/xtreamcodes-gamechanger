<?php
require_once __DIR__ . '/../../api_common.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function _gc_json($data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data);
  exit;
}

function _gc_bearer_token(): string {
  $h = '';
  if (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) $h = $headers['Authorization'];
    elseif (isset($headers['authorization'])) $h = $headers['authorization'];
  }
  if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
  if ($h === '') return '';
  if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
  return '';
}

function _gc_mobile_tokens_ensure(PDO $pdo): void {
  // shared-hosting safe: create if missing
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS mobile_tokens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      admin_id INT NOT NULL,
      token_hash CHAR(64) NOT NULL,
      device_name VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen DATETIME NULL,
      last_ip VARCHAR(64) NULL,
      expires_at DATETIME NOT NULL,
      UNIQUE KEY uq_token_hash (token_hash),
      KEY idx_admin_id (admin_id),
      KEY idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
}

function _gc_mobile_require_admin(PDO $pdo): array {
  _gc_mobile_tokens_ensure($pdo);

  $tok = _gc_bearer_token();
  if ($tok === '') _gc_json(['ok'=>false,'error'=>'unauthorized'], 401);

  $hash = hash('sha256', $tok);

  $st = $pdo->prepare("SELECT mt.admin_id FROM mobile_tokens mt WHERE mt.token_hash = ? AND mt.expires_at > NOW() LIMIT 1");
  $st->execute([$hash]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) _gc_json(['ok'=>false,'error'=>'unauthorized'], 401);

  // touch
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $pdo->prepare("UPDATE mobile_tokens SET last_seen = NOW(), last_ip = ? WHERE token_hash = ?")->execute([$ip, $hash]);

  $st = $pdo->prepare("SELECT id, username FROM admins WHERE id = ? LIMIT 1");
  $st->execute([(int)$row['admin_id']]);
  $admin = $st->fetch(PDO::FETCH_ASSOC);
  if (!$admin) _gc_json(['ok'=>false,'error'=>'unauthorized'], 401);

  return $admin;
}
function _gc_table_cols(PDO $pdo, string $table): array {
  static $cache = [];
  $k = strtolower($table);
  if (isset($cache[$k])) return $cache[$k];
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `$table`");
    $cols = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $cols[strtolower($r['Field'])] = true;
    }
    $cache[$k] = $cols;
    return $cols;
  } catch (Throwable $e) {
    $cache[$k] = [];
    return [];
  }
}
