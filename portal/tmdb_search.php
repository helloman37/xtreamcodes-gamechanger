<?php
require_once __DIR__ . '/tmdb_common.php';

header('Content-Type: application/json; charset=utf-8');

$type = strtolower(trim((string)($_GET['type'] ?? 'multi')));
$q    = trim((string)($_GET['q'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
if ($page > 500) $page = 500;

if ($q === '') {
  echo json_encode(['ok' => false, 'error' => 'query_required']);
  exit;
}

// TMDB endpoints
$path = '/search/multi';
if ($type === 'movie') $path = '/search/movie';
if ($type === 'tv')    $path = '/search/tv';

$res = portal_tmdb_api($path, [
  'query' => $q,
  'page' => $page,
], 300);

if (!$res['ok']) {
  echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'tmdb_error', 'status' => $res['status'] ?? 0]);
  exit;
}

$data = $res['data'] ?? [];
$items = portal_tmdb_map_items((array)($data['results'] ?? []));

// Hide people from multi search
$items = array_values(array_filter($items, fn($it) => ($it['type'] ?? '') !== 'person'));

echo json_encode([
  'ok' => true,
  'items' => $items,
  'page' => (int)($data['page'] ?? $page),
  'total_pages' => (int)($data['total_pages'] ?? 1),
]);
