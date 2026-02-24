<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../db.php';

$pdo = db();
$admin = _gc_mobile_require_admin($pdo);

$minutes = (int)($_GET['minutes'] ?? 5);
if ($minutes < 1) $minutes = 5;
if ($minutes > 1440) $minutes = 1440;

$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 10) $limit = 50;
if ($limit > 500) $limit = 500;

$type = trim((string)($_GET['type'] ?? ''));
if ($type !== '' && !in_array($type, ['live','vod','series'], true)) $type = '';

$sql = "
  SELECT ss.id, ss.user_id, u.username, ss.ip, ss.stream_type, ss.channel_id, ss.item_id, ss.last_seen
  FROM stream_sessions ss
  LEFT JOIN users u ON u.id = ss.user_id
  WHERE ss.last_seen >= (NOW() - INTERVAL ? MINUTE)
    AND (ss.killed_at IS NULL OR ss.killed_at='0000-00-00 00:00:00')
";
$params = [$minutes];
if ($type !== '') { $sql .= " AND ss.stream_type=?"; $params[] = $type; }
$sql .= " ORDER BY ss.last_seen DESC LIMIT $limit";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

_gc_json(['ok'=>true,'items'=>$rows,'count'=>count($rows),'window_minutes'=>$minutes]);
