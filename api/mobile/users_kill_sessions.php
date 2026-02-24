<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$uid = (int)($_POST['user_id'] ?? 0);
if ($uid <= 0) _gc_json(['ok'=>false,'error'=>'bad_user_id'], 400);

try {
  $pdo->prepare("UPDATE stream_sessions SET killed_at=NOW() WHERE user_id=? AND (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')")->execute([$uid]);
} catch (Throwable $e) {
  $pdo->prepare("DELETE FROM stream_sessions WHERE user_id=?")->execute([$uid]);
}

_gc_json(['ok'=>true,'user_id'=>$uid]);
