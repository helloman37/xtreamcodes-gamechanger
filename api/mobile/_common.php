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

/**
 * Lightweight rate limit (shared-host safe).
 * Uses a temp-file counter keyed by (key + ip). If the environment blocks writes, it becomes a no-op.
 */
function _gc_rate_limit(string $key, int $max, int $window_seconds): void {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $id = sha1($key . '|' . $ip);
  $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'gc_rl';
  $file = $dir . DIRECTORY_SEPARATOR . $id . '.json';

  try {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $now = time();
    $data = ['start' => $now, 'count' => 0];

    if (is_file($file)) {
      $raw = @file_get_contents($file);
      if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['start']) && isset($j['count'])) {
          $data['start'] = (int)$j['start'];
          $data['count'] = (int)$j['count'];
        }
      }
    }

    if (($now - $data['start']) >= $window_seconds) {
      $data['start'] = $now;
      $data['count'] = 0;
    }

    $data['count']++;

    @file_put_contents($file, json_encode($data), LOCK_EX);

    if ($data['count'] > $max) {
      _gc_json(['ok'=>false,'error'=>'rate_limited'], 429);
    }
  } catch (Throwable $e) {
    // If rate limiting can't run, don't block the API.
  }
}

function _gc_mobile_tokens_ensure(PDO $pdo): void {
  // create if missing (new schema)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS mobile_tokens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      admin_id INT NOT NULL,
      token_hash CHAR(64) NOT NULL,
      device_id VARCHAR(120) NULL,
      device_name VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_used_at DATETIME NULL,
      last_seen DATETIME NULL,
      last_ip VARCHAR(64) NULL,
      expires_at DATETIME NOT NULL,
      revoked_at DATETIME NULL,
      UNIQUE KEY uq_token_hash (token_hash),
      KEY idx_admin_id (admin_id),
      KEY idx_expires (expires_at),
      KEY idx_revoked (revoked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  // add missing columns if table existed from older versions
  $cols = _gc_table_cols($pdo, 'mobile_tokens');

  $adds = [];
  if (!isset($cols['device_id']))     $adds[] = "ADD COLUMN device_id VARCHAR(120) NULL";
  if (!isset($cols['device_name']))   $adds[] = "ADD COLUMN device_name VARCHAR(120) NULL";
  if (!isset($cols['created_at']))    $adds[] = "ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
  if (!isset($cols['last_used_at']))  $adds[] = "ADD COLUMN last_used_at DATETIME NULL";
  if (!isset($cols['last_seen']))     $adds[] = "ADD COLUMN last_seen DATETIME NULL";
  if (!isset($cols['last_ip']))       $adds[] = "ADD COLUMN last_ip VARCHAR(64) NULL";
  if (!isset($cols['expires_at']))    $adds[] = "ADD COLUMN expires_at DATETIME NOT NULL";
  if (!isset($cols['revoked_at']))    $adds[] = "ADD COLUMN revoked_at DATETIME NULL";

  if (!empty($adds)) {
    try {
      $pdo->exec("ALTER TABLE mobile_tokens " . implode(", ", $adds));
      // refresh cache
      // (next call will rebuild)
    } catch (Throwable $e) {
      // If ALTER TABLE not allowed, endpoints will fallback dynamically where possible.
    }
  }
}

function _gc_mobile_require_admin(PDO $pdo): array {
  _gc_mobile_tokens_ensure($pdo);

  $tok = _gc_bearer_token();
  if ($tok === '') _gc_json(['ok'=>false,'error'=>'unauthorized'], 401);

  $hash = hash('sha256', $tok);

  $cols = _gc_table_cols($pdo, 'mobile_tokens');
  $where = "mt.token_hash = ? AND mt.expires_at > NOW()";
  if (isset($cols['revoked_at'])) $where .= " AND mt.revoked_at IS NULL";

  $st = $pdo->prepare("SELECT mt.id AS token_id, mt.admin_id FROM mobile_tokens mt WHERE $where LIMIT 1");
  $st->execute([$hash]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) _gc_json(['ok'=>false,'error'=>'unauthorized'], 401);

  // touch
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $set = [];
  $params = [];
  if (isset($cols['last_used_at'])) { $set[] = "last_used_at = NOW()"; }
  if (isset($cols['last_seen']))    { $set[] = "last_seen = NOW()"; }
  if (isset($cols['last_ip']))      { $set[] = "last_ip = ?"; $params[] = $ip; }
  $params[] = $hash;

  if (!empty($set)) {
    $pdo->prepare("UPDATE mobile_tokens SET " . implode(", ", $set) . " WHERE token_hash = ?")->execute($params);
  }

  $admin_id = (int)$row['admin_id'];
  $token_id = (int)$row['token_id'];

  $st = $pdo->prepare("SELECT id, username FROM admins WHERE id = ? LIMIT 1");
  $st->execute([$admin_id]);
  $admin = $st->fetch(PDO::FETCH_ASSOC);
  if (!$admin) _gc_json(['ok'=>false,'error'=>'unauthorized'], 401);

  // Keep both keys for compatibility with older endpoint code.
  return [
    'id' => (int)$admin['id'],
    'admin_id' => (int)$admin['id'],
    'username' => (string)$admin['username'],
    'token_id' => $token_id,
  ];
}
