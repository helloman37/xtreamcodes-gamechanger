<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Admin only (resellers blocked).
if (isset($_SESSION['reseller_id']) && empty($_SESSION['admin_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}
if (empty($_SESSION['admin_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

$pdo = db();

// Match dashboard semantics: last 5 minutes, distinct IPs (real "connections").
try {
  $stmt = $pdo->query("SELECT COUNT(DISTINCT ip) AS c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)");
  $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
  $connections = $row ? (int)$row['c'] : 0;

  echo json_encode([
    'ok' => true,
    // preferred key for the pill:
    'live' => $connections,
    // compat keys (older JS / other places):
    'connections' => $connections,
    'count' => $connections,
    'window' => '5m',
    'ts' => gmdate('c')
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'query_failed']);
}
