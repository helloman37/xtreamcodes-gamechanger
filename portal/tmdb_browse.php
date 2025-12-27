<?php
require_once __DIR__ . '/tmdb_common.php';

header('Content-Type: application/json; charset=utf-8');

$type = strtolower(trim((string)($_GET['type'] ?? 'movie')));
$mode = strtolower(trim((string)($_GET['mode'] ?? 'trending')));
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
if ($page > 500) $page = 500;

$path = '/trending/movie/week';
$force = 'movie';
if ($type === 'tv' || $type === 'series') { $path = '/trending/tv/week'; $force = 'tv'; }

if ($mode === 'popular') {
  $path = ($force === 'tv') ? '/tv/popular' : '/movie/popular';
}
if ($mode === 'top') {
  $path = ($force === 'tv') ? '/tv/top_rated' : '/movie/top_rated';
}

$res = portal_tmdb_api($path, [
  'page' => $page,
], 900);

if (!$res['ok']) {
  echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'tmdb_error', 'status' => $res['status'] ?? 0]);
  exit;
}

$data = $res['data'] ?? [];
$items = portal_tmdb_map_items((array)($data['results'] ?? []), $force);

echo json_encode([
  'ok' => true,
  'items' => $items,
  'page' => (int)($data['page'] ?? $page),
  'total_pages' => (int)($data['total_pages'] ?? 1),
]);
