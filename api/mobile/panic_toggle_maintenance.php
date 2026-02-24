<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') _gc_json(['ok'=>false,'error'=>'method'], 405);

$enabled = (int)($_POST['enabled'] ?? -1);
if ($enabled !== 0 && $enabled !== 1) {
  // toggle if not provided
  $enabled = (system_setting_get($pdo, 'maintenance_mode', '0') === '1') ? 0 : 1;
}
system_setting_set($pdo, 'maintenance_mode', $enabled ? '1' : '0');

_gc_json(['ok'=>true,'maintenance'=>($enabled===1),'ts'=>date('c')]);
