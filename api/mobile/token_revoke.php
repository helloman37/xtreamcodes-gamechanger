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

$cols = _gc_table_cols($pdo, 'mobile_tokens');

if (isset($cols['revoked_at'])) {
  $pdo->prepare("UPDATE mobile_tokens SET revoked_at=NOW() WHERE id=? AND admin_id=?")->execute([$token_id, $admin_id]);
} else {
  // schema doesn't support revoke timestamp; just expire it now
  $pdo->prepare("UPDATE mobile_tokens SET expires_at=NOW() WHERE id=? AND admin_id=?")->execute([$token_id, $admin_id]);
}

// best-effort audit (function may live in helpers)
if (function_exists('_gc_mobile_audit')) {
  _gc_mobile_audit($pdo, $admin_id, (int)$ctx['id'], 'token_revoke', ['token_id'=>$token_id]);
}

_gc_json(['ok'=>true,'token_id'=>$token_id]);
