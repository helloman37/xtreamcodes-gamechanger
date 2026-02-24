<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$ctx = _gc_mobile_require_admin($pdo);

_gc_rate_limit('mobile_rotate', 10, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$token_id = (int)$ctx['token_id'];
$admin_id = (int)$ctx['admin_id'];

$new = bin2hex(random_bytes(32));
$hash = hash('sha256', $new);
$ttl_days = 30;

$cols = _gc_table_cols($pdo, 'mobile_tokens');

$set = [
  "token_hash=?",
  "created_at=NOW()",
  "expires_at=(NOW() + INTERVAL ? DAY)"
];
$params = [$hash, $ttl_days];

if (isset($cols['revoked_at'])) {
  $set[] = "revoked_at=NULL";
}

$params[] = $token_id;
$params[] = $admin_id;

$pdo->prepare("
  UPDATE mobile_tokens
  SET " . implode(", ", $set) . "
  WHERE id=? AND admin_id=?
")->execute($params);

// best-effort audit (function may live in helpers)
if (function_exists('_gc_mobile_audit')) {
  _gc_mobile_audit($pdo, $admin_id, $token_id, 'token_rotate', []);
}

_gc_json(['ok'=>true,'token'=>$new,'token_id'=>$token_id,'expires_in_days'=>$ttl_days]);
