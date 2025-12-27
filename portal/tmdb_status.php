<?php
require_once __DIR__ . '/tmdb_common.php';

header('Content-Type: application/json; charset=utf-8');

$cfg = portal_tmdb_cfg($pdo, $user);
$k = (string)($cfg['key'] ?? '');
$mask = $k ? (str_repeat('*', max(0, strlen($k)-4)) . substr($k, -4)) : '';

$res = portal_tmdb_api('/configuration', [], 3600);
if (!$res['ok']) {
  echo json_encode(['ok' => false, 'key' => $mask, 'error' => $res['error'] ?? 'tmdb_error', 'status' => $res['status'] ?? 0]);
  exit;
}

echo json_encode(['ok' => true, 'key' => $mask]);
