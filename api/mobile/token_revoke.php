<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$ctx = _gc_mobile_require_admin($pdo);

_gc_rate_limit('mobile_revoke', 60, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$token_id = (int)($_POST['token_id'] ?? 0);
if ($token_id <= 0) _gc_json(['ok'=>false,'error'=>'bad_token_id'], 400);

$admin_id = (int)$ctx['admin_id'];

$pdo->prepare("UPDATE mobile_tokens SET revoked_at=NOW() WHERE id=? AND admin_id=?")->execute([$token_id, $admin_id]);

_gc_mobile_audit($pdo, $admin_id, (int)$ctx['id'], 'token_revoke', ['token_id'=>$token_id]);

_gc_json(['ok'=>true,'token_id'=>$token_id]);
