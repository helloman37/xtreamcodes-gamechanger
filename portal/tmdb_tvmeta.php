<?php
// portal/tmdb_tvmeta.php
// Returns season list for a TMDB TV show (used for LegalVOD season/episode picker).

require_once __DIR__ . '/tmdb_common.php';

header('Content-Type: application/json; charset=utf-8');

$tvId = (int)($_GET['id'] ?? 0);
if ($tvId < 1) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_id']);
  exit;
}

$res = portal_tmdb_api('/tv/' . $tvId, [], 86400);
if (empty($res['ok'])) {
  http_response_code(502);
  echo json_encode(['ok'=>false,'error'=>($res['error'] ?? 'tmdb_error')]);
  exit;
}

$data = $res['data'] ?? [];
$seasons = [];
foreach (($data['seasons'] ?? []) as $s) {
  $sn = (int)($s['season_number'] ?? -1);
  if ($sn < 0) continue;
  $seasons[] = [
    'season_number' => $sn,
    'name' => (string)($s['name'] ?? ''),
    'episode_count' => (int)($s['episode_count'] ?? 0),
    'air_date' => (string)($s['air_date'] ?? ''),
  ];
}

echo json_encode([
  'ok' => true,
  'id' => $tvId,
  'name' => (string)($data['name'] ?? ''),
  'number_of_seasons' => (int)($data['number_of_seasons'] ?? 0),
  'seasons' => $seasons,
  'cached' => !empty($res['cached']),
]);
