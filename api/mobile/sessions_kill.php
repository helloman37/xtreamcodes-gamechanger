<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$sid = (int)($_POST['session_id'] ?? 0);
if ($sid <= 0) _gc_json(['ok'=>false,'error'=>'bad_session_id'], 400);

try {
  $pdo->prepare("UPDATE stream_sessions SET killed_at=NOW() WHERE id=?")->execute([$sid]);
} catch (Throwable $e) {
  $pdo->prepare("DELETE FROM stream_sessions WHERE id=?")->execute([$sid]);
}

_gc_json(['ok'=>true,'session_id'=>$sid]);
