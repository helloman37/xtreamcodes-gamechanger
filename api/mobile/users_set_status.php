<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$uid = (int)($_POST['user_id'] ?? 0);
$status = trim((string)($_POST['status'] ?? ''));
if ($uid <= 0) _gc_json(['ok'=>false,'error'=>'bad_user_id'], 400);
if (!in_array($status, ['active','suspended'], true)) _gc_json(['ok'=>false,'error'=>'bad_status'], 400);

$pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $uid]);

_gc_json(['ok'=>true,'user_id'=>$uid,'status'=>$status]);
