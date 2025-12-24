<?php
declare(strict_types=1);

// MAG/Stalker portal adapter (base: /c/)
// Endpoint: /c/server/load.php?type=...&action=...
// Returns JSON: {"js": ...}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

define('IPTV_NO_INSTALL_GUARD', true);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../helpers.php';

function _js($payload): void {
  echo json_encode(["js" => $payload], JSON_UNESCAPED_SLASHES);
  exit;
}

function _mac_from_request(): string {
  $mac = $_COOKIE['mac'] ?? ($_GET['mac'] ?? '');
  $mac = is_string($mac) ? $mac : '';
  return iptv_normalize_mac($mac);
}

function _bearer_token(): string {
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (is_string($hdr) && stripos($hdr, 'Bearer ') === 0) return trim(substr($hdr, 7));
  $t = $_GET['token'] ?? '';
  return is_string($t) ? trim($t) : '';
}

function _require_device(PDO $pdo, string $mac): array {
  if ($mac === '') _js(["error" => "NO_MAC"]);

  $st = $pdo->prepare("
    SELECT d.*, u.username, u.status AS user_status, u.allow_adult
    FROM stalker_devices d
    JOIN users u ON u.id = d.user_id
    WHERE d.mac = ?
    LIMIT 1
  ");
  $st->execute([$mac]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) _js(["error" => "UNKNOWN_MAC"]);
  if ((int)($row['is_enabled'] ?? 0) !== 1) _js(["error" => "MAC_DISABLED"]);
  if (($row['user_status'] ?? '') !== 'active') _js(["error" => "USER_INACTIVE"]);

  $user_id = (int)($row['user_id'] ?? 0);
  $sub = iptv_active_subscription($pdo, $user_id);
  if (!$sub) _js(["error" => "NO_ACTIVE_SUB"]);

  try { $pdo->prepare("UPDATE stalker_devices SET last_seen=NOW() WHERE id=?")->execute([(int)$row['id']]); } catch (Throwable $e) {}
  $row['_sub'] = $sub;
  return $row;
}

function _require_session(PDO $pdo, string $mac): array {
  $t = _bearer_token();
  if ($t === '') _js(["error" => "NO_TOKEN"]);

  $st = $pdo->prepare("SELECT * FROM stalker_sessions WHERE token=? LIMIT 1");
  $st->execute([$t]);
  $sess = $st->fetch(PDO::FETCH_ASSOC);
  if (!$sess) _js(["error" => "BAD_TOKEN"]);
  if (strtoupper((string)$sess['mac']) !== strtoupper($mac)) _js(["error" => "BAD_TOKEN"]);

  try { $pdo->prepare("UPDATE stalker_sessions SET last_seen=NOW() WHERE id=?")->execute([(int)$sess['id']]); } catch (Throwable $e) {}
  return $sess;
}

function _categories(PDO $pdo, bool $allow_adult): array {
  $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM(group_title),''),'Other') AS grp
          FROM channels
          WHERE (?=1 OR is_adult=0)
          ORDER BY grp";
  $st = $pdo->prepare($sql);
  $st->execute([$allow_adult ? 1 : 0]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $out = [];
  $i = 1;
  foreach ($rows as $r) {
    $out[] = ["id" => (string)$i, "title" => (string)$r['grp']];
    $i++;
  }
  return $out;
}

function _cat_map(array $cats): array {
  $map = [];
  foreach ($cats as $c) {
    $map[(string)$c['title']] = (string)$c['id'];
  }
  return $map;
}

$pdo = db();
$type   = (string)($_GET['type'] ?? '');
$action = (string)($_GET['action'] ?? '');
$mac    = _mac_from_request();

if ($type === 'stb' && $action === 'handshake') {
  $dev = _require_device($pdo, $mac);

  try { $pdo->prepare("DELETE FROM stalker_sessions WHERE mac=? AND last_seen < (NOW() - INTERVAL 2 DAY)")->execute([$mac]); } catch (Throwable $e) {}

  $token = bin2hex(random_bytes(24));
  $ip = get_client_ip();
  $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);

  $pdo->prepare("INSERT INTO stalker_sessions (mac, user_id, token, created_at, last_seen, ip, ua)
                 VALUES (?,?,?,NOW(),NOW(),?,?)")
      ->execute([$mac, (int)$dev['user_id'], $token, $ip, $ua]);

  _js(["token" => $token, "random" => (string)random_int(100000,999999), "mac" => $mac]);
}

if ($type === 'stb' && $action === 'get_profile') {
  _require_device($pdo, $mac);
  _require_session($pdo, $mac);

  _js([
    "mac" => $mac,
    "name" => "Xtream GameChanger",
    "theme" => "default",
    "timezone" => $_COOKIE['timezone'] ?? "UTC",
    "status" => 1
  ]);
}

if ($type === 'itv' && $action === 'get_categories') {
  $dev = _require_device($pdo, $mac);
  _require_session($pdo, $mac);

  $cats = _categories($pdo, ((int)$dev['allow_adult']) === 1);
  _js($cats);
}

if ($type === 'itv' && ($action === 'get_all_channels' || $action === 'get_ordered_list')) {
  $dev = _require_device($pdo, $mac);
  _require_session($pdo, $mac);

  $config = require __DIR__ . '/../../config.php';
  $base_url = rtrim((string)($config['base_url'] ?? ''), '/');
  $allow_adult = ((int)$dev['allow_adult']) === 1;

  $cats = _categories($pdo, $allow_adult);
  $map = _cat_map($cats);

  $sql = "SELECT * FROM channels WHERE (?=1 OR is_adult=0) ORDER BY id";
  $st = $pdo->prepare($sql);
  $st->execute([$allow_adult ? 1 : 0]);

  $exp = time() + 86400 * 30; // 30 days
  $chs = [];
  while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
    $grp = trim((string)($c['group_title'] ?? ''));
    if ($grp === '') $grp = 'Other';
    $gid = (string)($map[$grp] ?? ($map['Other'] ?? '1'));
    $cid = (int)$c['id'];

    $tok = make_token((string)$dev['username'], $cid, $exp, 'live');
    $url = $base_url . "/live/" . rawurlencode((string)$dev['username']) . "/" . rawurlencode($tok) . "/" . $cid . ".m3u8?exp=" . $exp;

    $chs[] = [
      "id" => (string)$cid,
      "number" => (string)$cid,
      "name" => (string)$c['name'],
      "logo" => (string)($c['tvg_logo'] ?? ''),
      "tv_genre_id" => $gid,
      "epg_id" => (string)($c['tvg_id'] ?? ''),
      "cmd" => "ffmpeg " . $url,
      "use_http_tmp_link" => 1
    ];
  }
  _js($chs);
}

if ($type === 'itv' && $action === 'create_link') {
  $dev = _require_device($pdo, $mac);
  _require_session($pdo, $mac);

  $channel_id = (int)($_GET['channel_id'] ?? 0);
  if ($channel_id <= 0) _js(["error" => "BAD_CHANNEL_ID"]);

  $allow_adult = ((int)$dev['allow_adult']) === 1;
  $st = $pdo->prepare("SELECT id, is_adult FROM channels WHERE id=? LIMIT 1");
  $st->execute([$channel_id]);
  $c = $st->fetch(PDO::FETCH_ASSOC);
  if (!$c) _js(["error" => "NO_CHANNEL"]);
  if (!$allow_adult && (int)($c['is_adult'] ?? 0) === 1) _js(["error" => "NOT_ALLOWED"]);

  $config = require __DIR__ . '/../../config.php';
  $base_url = rtrim((string)($config['base_url'] ?? ''), '/');

  $exp = time() + 86400 * 30;
  $tok = make_token((string)$dev['username'], $channel_id, $exp, 'live');
  $url = $base_url . "/live/" . rawurlencode((string)$dev['username']) . "/" . rawurlencode($tok) . "/" . $channel_id . ".m3u8?exp=" . $exp;

  _js(["cmd" => "ffmpeg " . $url, "id" => (string)$channel_id]);
}

_js(["error" => "UNKNOWN_REQUEST", "type" => $type, "action" => $action]);
