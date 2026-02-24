<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$ctx = _gc_mobile_require_admin($pdo);

_gc_rate_limit('mobile_rotate', 10, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$token_id = (int)$ctx['id'];
$admin_id = (int)$ctx['admin_id'];

$new = bin2hex(random_bytes(32));
$hash = hash('sha256', $new);
$ttl_days = 30;

$pdo->prepare("
  UPDATE mobile_tokens
  SET token_hash=?, created_at=NOW(), expires_at=(NOW() + INTERVAL ? DAY), revoked_at=NULL
  WHERE id=? AND admin_id=?
")->execute([$hash, $ttl_days, $token_id, $admin_id]);

_gc_mobile_audit($pdo, $admin_id, $token_id, 'token_rotate', []);

_gc_json(['ok'=>true,'token'=>$new,'token_id'=>$token_id,'expires_in_days'=>$ttl_days]);
