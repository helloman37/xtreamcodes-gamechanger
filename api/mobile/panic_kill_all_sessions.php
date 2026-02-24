<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$mode = trim((string)($_POST['mode'] ?? 'mark')); // mark|delete
$killed = 0;

try {
  if ($mode === 'delete') {
    $st = $pdo->query("SELECT COUNT(*) AS c FROM stream_sessions");
    $killed = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $pdo->exec("DELETE FROM stream_sessions");
  } else {
    $st = $pdo->query("SELECT COUNT(*) AS c FROM stream_sessions WHERE (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')");
    $killed = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    $pdo->exec("UPDATE stream_sessions SET killed_at=NOW() WHERE (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')");
  }
} catch (Throwable $e) {
  _gc_json(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}

_gc_json(['ok'=>true,'killed'=>$killed,'mode'=>$mode,'ts'=>date('c')]);
