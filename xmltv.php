<?php
require_once __DIR__ . '/api_common.php';

header("Content-Type: application/xml; charset=utf-8");

// Request telemetry (admin -> Telemetry)
telemetry_init('xmltv', '');

$pdo = db();
ensure_categories($pdo);

// Ensure epg_channels exists (XMLTV <channel> metadata from last import).
$pdo->exec("CREATE TABLE IF NOT EXISTS epg_channels (
  xmltv_id VARCHAR(255) NOT NULL PRIMARY KEY,
  display_name VARCHAR(255) NULL,
  icon_src TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$username = trim($_GET['username'] ?? '');
$password = (string)($_GET['password'] ?? '');

if ($username === '' || $password === '') {
  telemetry_reason('missing_credentials');
  http_response_code(401);
  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><tv></tv>";
  exit;
}

/* user */
$st = $pdo->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
$st->execute([$username]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
  telemetry_reason('auth_fail', ['username'=>$username]);
  http_response_code(401);
  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><tv></tv>";
  exit;
}

telemetry_set_user((int)$user['id'], (string)$user['username']);

// policy: IP allow/deny
$ip = get_client_ip();
$ban = abuse_ban_lookup($pdo, $ip, (int)$user['id']);
if ($ban) {
  audit_log('ban_block', (int)$user['id'], ['ban_type'=>$ban['ban_type'] ?? 'user','ip'=>$ip]);
  telemetry_reason('banned');
  http_response_code(403);
  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><tv></tv>";
  exit;
}

if (!ip_allowed($ip, $user['ip_allowlist'] ?? null, $user['ip_denylist'] ?? null)) {
  telemetry_reason('ip_not_allowed');
  http_response_code(403);
  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><tv></tv>";
  exit;
}

/* sub */
$st = $pdo->prepare("
  SELECT s.*
  FROM subscriptions s
  WHERE s.user_id=? AND s.status='active' AND (s.ends_at IS NULL OR s.ends_at>NOW())
  ORDER BY s.ends_at DESC LIMIT 1
");
$st->execute([(int)$user['id']]);
$sub = $st->fetch(PDO::FETCH_ASSOC);
if (!$sub) {
  telemetry_reason('no_subscription');
  http_response_code(403);
  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><tv></tv>";
  exit;
}

$adult_ok = !empty($user['allow_adult']);

/* If DB has no epg_programs, proxy upstream XMLTV if configured (URL or local file). */
$epg_count = (int)($pdo->query("SELECT COUNT(*) c FROM epg_programs")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
$src = $pdo->query("SELECT * FROM epg_sources WHERE enabled=1 ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($epg_count === 0 && $src && !empty($src['xmltv_url'])) {
  $u = trim((string)$src['xmltv_url']);
  if (preg_match('#^https?://#i', $u)) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_USERAGENT => 'IPTV-XMLTV-Proxy/1.2'
    ]);
    $xml = curl_exec($ch);
    curl_close($ch);
    if ($xml) { echo $xml; exit; }
  } else {
    $path = $u;
    if ($path !== '' && $path[0] !== '/' && !preg_match('#^[A-Za-z]:\\\\#', $path)) {
      $path = __DIR__ . '/' . $path;
    }
    $real = realpath($path);
    if ($real && is_file($real)) {
      $xml = @file_get_contents($real);
      if ($xml !== false) {
        if (strncmp($xml, "\x1f\x8b", 2) === 0) {
          $decoded = @gzdecode($xml);
          if ($decoded !== false) $xml = $decoded;
        }
        echo $xml;
        exit;
      }
    }
  }
}

$pkg_ids  = user_package_ids($pdo, (int)$user['id']);
[$pkg_sql, $pkg_params] = package_filter_sql($pkg_ids, 'c');

function fetch_allowed_channels(PDO $pdo, bool $adult_ok, string $pkg_sql, array $pkg_params): array {
  $sql = "
    SELECT c.id,c.name,c.tvg_id,c.tvg_name,c.tvg_logo,
           IFNULL(cat.sort_order, 999999) AS cat_sort,
           IFNULL(c.sort_order, c.id) AS ch_sort
    FROM channels c
    LEFT JOIN categories cat ON cat.id=c.category_id
    WHERE 1=1
      ".($adult_ok ? "" : " AND IFNULL(c.is_adult,0)=0 ")."
      $pkg_sql
    ORDER BY cat_sort, ch_sort, c.id
  ";
  $st = $pdo->prepare($sql);
  $st->execute($pkg_params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* channels allowed */
$channels = fetch_allowed_channels($pdo, $adult_ok, $pkg_sql, $pkg_params);

/* If package filtering yields nothing (common misconfig), fall back to "no restriction" for XMLTV only. */
if (!$channels && $pkg_ids) {
  telemetry_reason('epg_pkg_empty_fallback', ['username'=>$username]);
  $channels = fetch_allowed_channels($pdo, $adult_ok, '', []);
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<tv generator-info-name=\"IPTV Panel\">\n";

$ids = [];
if ($channels) {
  foreach($channels as $c){
    $id = $c['tvg_id'] ?: $c['name'];
    $ids[] = $id;
    echo "  <channel id=\"" . htmlspecialchars($id, ENT_QUOTES) . "\">\n";
    echo "    <display-name>".htmlspecialchars($c['tvg_name'] ?: $c['name'])."</display-name>\n";
    if (!empty($c['tvg_logo'])) {
      echo "    <icon src=\"" . htmlspecialchars($c['tvg_logo'], ENT_QUOTES) . "\" />\n";
    }
    echo "  </channel>\n";
  }
} else {
  // Fallback: if channels table is empty, emit channel nodes from imported XMLTV metadata.
  $epg_meta_count = (int)($pdo->query("SELECT COUNT(*) c FROM epg_channels")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
  if ($epg_meta_count > 0) {
    $st = $pdo->query("SELECT xmltv_id, display_name, icon_src FROM epg_channels ORDER BY xmltv_id");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $id = (string)$r['xmltv_id'];
      $ids[] = $id;
      echo "  <channel id=\"" . htmlspecialchars($id, ENT_QUOTES) . "\">\n";
      echo "    <display-name>".htmlspecialchars($r['display_name'] ?: $id)."</display-name>\n";
      if (!empty($r['icon_src'])) {
        echo "    <icon src=\"" . htmlspecialchars($r['icon_src'], ENT_QUOTES) . "\" />\n";
      }
      echo "  </channel>\n";
    }
  } else {
    // Last resort: derive distinct channel ids from programmes window.
    $st = $pdo->query("
      SELECT DISTINCT channel_xmltv_id
      FROM epg_programs
      WHERE stop_utc > (UTC_TIMESTAMP() - INTERVAL 6 HOUR)
        AND start_utc < (UTC_TIMESTAMP() + INTERVAL 2 DAY)
      ORDER BY channel_xmltv_id
      LIMIT 5000
    ");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $id = (string)$r['channel_xmltv_id'];
      $ids[] = $id;
      echo "  <channel id=\"" . htmlspecialchars($id, ENT_QUOTES) . "\">\n";
      echo "    <display-name>".htmlspecialchars($id)."</display-name>\n";
      echo "  </channel>\n";
    }
  }
}

// programmes window (chunked IN() to avoid placeholder limits)
if ($ids) {
  $chunkSize = 800;
  for ($off=0; $off < count($ids); $off += $chunkSize) {
    $chunk = array_slice($ids, $off, $chunkSize);
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $st = $pdo->prepare("
      SELECT channel_xmltv_id, start_utc, stop_utc, title, descr
      FROM epg_programs
      WHERE channel_xmltv_id IN ($in)
        AND stop_utc > (UTC_TIMESTAMP() - INTERVAL 6 HOUR)
        AND start_utc < (UTC_TIMESTAMP() + INTERVAL 2 DAY)
      ORDER BY channel_xmltv_id, start_utc
    ");
    $st->execute($chunk);
    while ($p = $st->fetch(PDO::FETCH_ASSOC)) {
      $start = gmdate('YmdHis +0000', strtotime($p['start_utc'] . ' UTC'));
      $stop  = gmdate('YmdHis +0000', strtotime($p['stop_utc'] . ' UTC'));
      echo "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"" . htmlspecialchars($p['channel_xmltv_id'], ENT_QUOTES) . "\">\n";
      echo "    <title>" . htmlspecialchars($p['title']) . "</title>\n";
      if (!empty($p['descr'])) echo "    <desc>" . htmlspecialchars($p['descr']) . "</desc>\n";
      echo "  </programme>\n";
    }
  }
}

echo "</tv>\n";
