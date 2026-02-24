<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

$maintenance = (system_setting_get($pdo, 'maintenance_mode', '0') === '1');

// counts
$active_sessions = 0;
try {
  $st = $pdo->query("SELECT COUNT(*) AS c FROM stream_sessions WHERE (killed_at IS NULL OR killed_at='0000-00-00 00:00:00')");
  $active_sessions = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
} catch (Throwable $e) {
  $active_sessions = 0;
}

_gc_json([
  'ok' => true,
  'maintenance' => $maintenance,
  'active_sessions' => $active_sessions,
  'ts' => date('c')
]);
