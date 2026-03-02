<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

try {
  $pdo = db();

  $counts = [
    'channels' => (int)$pdo->query("SELECT COUNT(*) c FROM channels")->fetch()['c'],
    'users'    => (int)$pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'],
  ];

  $online = [
    'streams' => (int)$pdo->query("SELECT COUNT(*) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
    'users' => (int)$pdo->query("SELECT COUNT(DISTINCT user_id) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
    'connections' => (int)$pdo->query("SELECT COUNT(DISTINCT ip) c FROM stream_sessions WHERE last_seen >= (NOW() - INTERVAL 5 MINUTE)")->fetch()['c'],
    'servers' => 1,
  ];

  $access_logs = $pdo->query("
    SELECT ss.last_seen, ss.ip, u.username AS user_name, c.name AS channel_name
    FROM stream_sessions ss
    LEFT JOIN users u ON u.id = ss.user_id
    LEFT JOIN channels c ON c.id = ss.channel_id
    ORDER BY ss.last_seen DESC
    LIMIT 10
  ")->fetchAll();

  $rows = '';
  foreach ($access_logs as $log) {
    $rows .= '<tr>';
    $rows .= '<td>' . e($log['last_seen']) . '</td>';
    $rows .= '<td>' . e($log['user_name'] ?? '-') . '</td>';
    $rows .= '<td>' . e($log['channel_name'] ?? '-') . '</td>';
    $rows .= '<td>' . e($log['ip']) . '</td>';
    $rows .= '</tr>';
  }

  if (empty($access_logs)) {
    $rows = '<tr><td colspan="4" style="text-align:center; opacity:.7;">No recent sessions</td></tr>';
  }

  echo json_encode([
    'ok' => true,
    'ts' => gmdate('c'),
    'online' => $online,
    'counts' => $counts,
    'logs_html' => $rows,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server_error']);
}
