<?php
// scripts/stream_probe.php
// Usage: php scripts/stream_probe.php --limit=200
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

$pdo = db();

$args = [];
foreach ($argv as $a) {
  if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
}
$limit = (int)($args['limit'] ?? 200);
if ($limit < 1) $limit = 200;

$st = $pdo->prepare("SELECT id, stream_url, sources_json FROM channels ORDER BY IFNULL(last_checked_at,'1970-01-01') ASC LIMIT $limit");
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare("UPDATE channels SET last_checked_at=NOW(), last_status_code=?, works=? WHERE id=?");
$hup = $pdo->prepare("
  INSERT INTO stream_health (channel_id,last_ok,last_fail,fail_count,last_http,last_error)
  VALUES (?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE
    last_ok=VALUES(last_ok),
    last_fail=VALUES(last_fail),
    fail_count=fail_count + VALUES(fail_count),
    last_http=VALUES(last_http),
    last_error=VALUES(last_error)
");

foreach ($rows as $r) {
  $id = (int)$r['id'];
  $sources = [];
  if (!empty($r['sources_json'])) {
    $j = json_decode($r['sources_json'], true);
    if (is_array($j)) $sources = $j;
  }
  if (!$sources) {
    $raw = (string)$r['stream_url'];
    if (strpos($raw, '||') !== false) $sources = array_values(array_filter(array_map('trim', explode('||', $raw))));
    else $sources = [$raw];
  }

  $best = null;
  $bestCode = 0;
  $ok = 0;
  $err = '';

  foreach ($sources as $src) {
    if (!$src) continue;
    $res = check_stream_url($src, 8);
    $bestCode = (int)($res['code'] ?? 0);
    $ok = !empty($res['ok']) ? 1 : 0;
    $err = (string)($res['error'] ?? '');
    if ($ok) { $best = $src; break; }
  }

  $upd->execute([$bestCode ?: null, $ok, $id]);

  if ($ok) {
    $hup->execute([$id, date('Y-m-d H:i:s'), null, 0, $bestCode ?: null, null]);
  } else {
    $hup->execute([$id, null, date('Y-m-d H:i:s'), 1, $bestCode ?: null, $err ? mb_substr($err,0,255) : null]);
  }

  echo "Channel $id => ".($ok ? "OK ($bestCode)" : "FAIL ($bestCode)")."\n";
}
