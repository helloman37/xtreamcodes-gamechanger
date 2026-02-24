<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$ctx = _gc_mobile_require_admin($pdo);

$admin_id = (int)$ctx['admin_id'];

$st = $pdo->prepare("
  SELECT id, created_at, expires_at, last_used_at, revoked_at, device_id, device_name, last_ip
  FROM mobile_tokens
  WHERE admin_id=?
  ORDER BY id DESC
  LIMIT 50
");
$st->execute([$admin_id]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

_gc_json(['ok'=>true,'items'=>$rows,'count'=>count($rows)]);
