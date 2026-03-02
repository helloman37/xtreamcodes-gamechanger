<?php
// portal/watchlist_api.php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/lib_watchlist.php';

header('Content-Type: application/json; charset=utf-8');

try {
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (function_exists('db')) { $pdo = db(); }
  }
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('db_missing');
  }

  if (!isset($userId) || (int)$userId < 1) {
    if (isset($user) && is_array($user) && isset($user['id'])) { $userId = (int)$user['id']; }
    elseif (isset($_SESSION['store_user'])) {
      $userId = is_array($_SESSION['store_user']) ? (int)($_SESSION['store_user']['id'] ?? 0) : (int)$_SESSION['store_user'];
    }
  }
  if (!isset($userId) || (int)$userId < 1) {
    throw new RuntimeException('auth_missing');
  }

  wl_db_init($pdo);
  $backend = function_exists('wl_backend') ? wl_backend($pdo) : 'db';

  $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
  $data = [];
  if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) $data = $json;
    else $data = $_POST;
  } else {
    $data = $_GET;
  }

  $action = strtolower(trim((string)($data['action'] ?? 'toggle')));
  if ($action === 'ping') {
    echo json_encode(['ok'=>true,'pong'=>true]);
    exit;
  }

  if ($action === 'list') {
    $items = wl_list($pdo, (int)$userId);
    $set = [];
    foreach ($items as $it) {
      $k = (string)($it['kind'] ?? '') . ':' . (string)($it['item_id'] ?? '');
      $set[$k] = true;
    }
    echo json_encode(['ok'=>true,'backend'=>$backend,'items'=>$items,'set'=>$set]);
    exit;
  }

  $kind = (string)($data['kind'] ?? '');
  $itemId = (string)($data['id'] ?? $data['item_id'] ?? '');
  $title = (string)($data['title'] ?? '');
  $poster = (string)($data['poster'] ?? '');
  $url = (string)($data['url'] ?? '');

  if ($kind === '' || $itemId === '') {
    throw new RuntimeException('missing_kind_or_id');
  }

  if ($action === 'add') {
    wl_add($pdo, (int)$userId, $kind, $itemId, $title, $poster, $url);
    echo json_encode(['ok'=>true,'backend'=>$backend,'in_watchlist'=>true]);
    exit;
  }

  if ($action === 'remove') {
    wl_remove($pdo, (int)$userId, $kind, $itemId);
    echo json_encode(['ok'=>true,'backend'=>$backend,'in_watchlist'=>false]);
    exit;
  }

  $in = wl_toggle($pdo, (int)$userId, $kind, $itemId, $title, $poster, $url);
  echo json_encode(['ok'=>true,'backend'=>$backend,'in_watchlist'=>$in]);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => 'watchlist_api_error',
    'message' => $e->getMessage(),
  ]);
}
