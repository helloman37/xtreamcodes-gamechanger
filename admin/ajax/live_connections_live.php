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

// Count live sessions seen in the last 60 seconds.
// If the table is missing, return ok=false without fatal.
try {
  $stmt = $pdo->query("SELECT COUNT(*) AS c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 60 SECOND)");
  $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
  $count = $row ? (int)$row['c'] : 0;

  echo json_encode([
    'ok' => true,
    'count' => $count,
    'window_sec' => 60,
    'ts' => gmdate('c')
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'query_failed']);
}
