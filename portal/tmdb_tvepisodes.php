<?php
// portal/tmdb_tvepisodes.php
// Returns episode list for a TMDB TV show season (used for LegalVOD season/episode picker).

require_once __DIR__ . '/tmdb_common.php';

header('Content-Type: application/json; charset=utf-8');

$tvId = (int)($_GET['id'] ?? 0);
$season = (int)($_GET['season'] ?? 0);

if ($tvId < 1 || $season < 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_params']);
  exit;
}

$res = portal_tmdb_api('/tv/' . $tvId . '/season/' . $season, [], 86400);
if (empty($res['ok'])) {
  http_response_code(502);
  echo json_encode(['ok'=>false,'error'=>($res['error'] ?? 'tmdb_error')]);
  exit;
}

$data = $res['data'] ?? [];
$episodes = [];
foreach (($data['episodes'] ?? []) as $e) {
  $en = (int)($e['episode_number'] ?? 0);
  if ($en < 1) continue;
  $episodes[] = [
    'episode_number' => $en,
    'name' => (string)($e['name'] ?? ''),
    'air_date' => (string)($e['air_date'] ?? ''),
  ];
}

echo json_encode([
  'ok' => true,
  'id' => $tvId,
  'season' => $season,
  'episodes' => $episodes,
  'cached' => !empty($res['cached']),
]);
