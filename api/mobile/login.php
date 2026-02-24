<?php
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  _gc_json(['ok'=>false,'error'=>'method_not_allowed'], 405);
}

$u = trim($_POST['username'] ?? '');
$p = (string)($_POST['password'] ?? '');
$device_name = trim($_POST['device'] ?? '');
$device_id = trim($_POST['device_id'] ?? '');

if ($u === '' || $p === '') _gc_json(['ok'=>false,'error'=>'missing_credentials'], 400);

try {
  $pdo = db();
  _gc_mobile_tokens_ensure($pdo);

  // Use SELECT * so we don't hard-require specific columns (compat with older XUI schemas)
  $st = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
  $st->execute([$u]);
  $admin = $st->fetch(PDO::FETCH_ASSOC);
  if (!$admin) _gc_json(['ok'=>false,'error'=>'invalid_login'], 401);

  $ok = false;

  // Preferred: bcrypt/argon hash stored in password_hash
  if (isset($admin['password_hash']) && $admin['password_hash']) {
    $ok = password_verify($p, $admin['password_hash']);
  } else {
    // Fallbacks for legacy schemas:
    //  - 'password' column may store md5/sha1/plain
    $stored = (string)($admin['password'] ?? '');
    if ($stored !== '') {
      if (hash_equals($stored, $p)) $ok = true;                      // plain
      else if (hash_equals($stored, md5($p))) $ok = true;            // md5
      else if (hash_equals($stored, sha1($p))) $ok = true;           // sha1
      else if (preg_match('/^\$2y\$|^\$2a\$|^\$argon2/', $stored)) {
        $ok = password_verify($p, $stored);                           // hash stored in password
      }
    }
  }

  if (!$ok) _gc_json(['ok'=>false,'error'=>'invalid_login'], 401);

  // Issue token
  $token = bin2hex(random_bytes(32));
  $hash  = hash('sha256', $token);
  $expires_days = 30;
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;

  $cols = _gc_table_cols($pdo, 'mobile_tokens');

  $fields = ['admin_id', 'token_hash', 'expires_at'];
  $values = [(int)$admin['id'], $hash];
  $placeholders = ['?', '?', '(NOW() + INTERVAL ' . (int)$expires_days . ' DAY)'];

  if (isset($cols['device_id'])) {
    $fields[] = 'device_id';
    $values[] = ($device_id !== '' ? $device_id : null);
    $placeholders[] = '?';
  }
  if (isset($cols['device_name'])) {
    $fields[] = 'device_name';
    $values[] = ($device_name !== '' ? $device_name : null);
    $placeholders[] = '?';
  }
  if (isset($cols['last_used_at'])) {
    $fields[] = 'last_used_at';
    $placeholders[] = 'NOW()';
  } elseif (isset($cols['last_seen'])) {
    $fields[] = 'last_seen';
    $placeholders[] = 'NOW()';
  }
  if (isset($cols['last_ip'])) {
    $fields[] = 'last_ip';
    $values[] = $ip;
    $placeholders[] = '?';
  }
  if (isset($cols['revoked_at'])) {
    $fields[] = 'revoked_at';
    $placeholders[] = 'NULL';
  }

  $sql = "INSERT INTO mobile_tokens (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
  $pdo->prepare($sql)->execute($values);

  _gc_json([
    'ok' => true,
    'token' => $token,
    'expires_days' => $expires_days,
    'admin' => ['id'=>(int)$admin['id'], 'username'=>(string)$admin['username']]
  ]);

} catch (Throwable $e) {
  // Optional debug via ?debug=1
  if (!empty($_GET['debug'])) {
    _gc_json(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()], 500);
  }
  _gc_json(['ok'=>false,'error'=>'server_error'], 500);
}
