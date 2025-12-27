<?php
require_once __DIR__ . '/api_common.php';

// CORS for browser-based webplayers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

header("Content-Type: application/xml; charset=utf-8");

// Keep XML output clean even if PHP emits warnings/notices.
if (PHP_SAPI !== 'cli') {
  @ini_set('display_errors', '0');
  @ini_set('log_errors', '1');
  @ini_set('default_charset', 'UTF-8');
  if (function_exists('mb_internal_encoding')) { @mb_internal_encoding('UTF-8'); }
  if (ob_get_level() === 0) { @ob_start(); }
}

// XML-safe escaping (prevents malformed UTF-8 + control chars from breaking XML)
function _xmltv_strip_invalid_xml(string $s): string {
  // Remove disallowed XML 1.0 control chars (keep TAB, LF, CR).
  return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
}
function _xmltv_x(string $s): string {
  $s = _xmltv_strip_invalid_xml($s);
  return htmlspecialchars($s, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}
function _xmltv_attr(string $s): string {
  $s = _xmltv_strip_invalid_xml($s);
  return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Request telemetry (admin -> Telemetry)
telemetry_init('xmltv', '');

// ----- XMLTV region filtering (proxy mode) -----
function _xmltv_default_rules(): string {
  return "# Format: REGION=keyword1,keyword2\n"
    . "USA=usa,.us,united states,us|\n"
    . "Canada=canada,.ca\n"
    . "UK=uk,.uk,united kingdom\n"
    . "Australia=australia,.au\n"
    . "Asia=asia,asia|,hk,sg,ph,my,th,jp,kr\n"
    . "India=india,.in\n"
    . "Europe=europe,eu\n"
    . "Latin=latin,latam\n"
    . "Africa=africa\n"
    . "Middle East=middle east,mena,uae,saudi,qatar\n";
}

function _xmltv_parse_rules(string $text): array {
  $rules = [];
  $lines = preg_split('/\r\n|\r|\n/', $text);
  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $parts = explode('=', $line, 2);
    if (count($parts) !== 2) continue;
    $region = trim($parts[0]);
    $keys = array_filter(array_map('trim', explode(',', $parts[1])), fn($v) => $v !== '');
    if ($region !== '' && $keys) $rules[] = ['region' => $region, 'keys' => $keys];
  }
  return $rules;
}

function _xmltv_detect_region(string $id, string $name, array $rules): string {
  $hay = strtolower($id . ' ' . $name);
  foreach ($rules as $r) {
    foreach ($r['keys'] as $k) {
      $k = strtolower((string)$k);
      if ($k !== '' && strpos($hay, $k) !== false) return (string)$r['region'];
    }
  }
  return 'Other';
}

function _xmltv_is_gzip_file(string $path): bool {
  $fh = @fopen($path, 'rb');
  if (!$fh) return false;
  $b = fread($fh, 2);
  fclose($fh);
  return $b !== false && strlen($b) === 2 && ord($b[0]) === 0x1f && ord($b[1]) === 0x8b;
}

function _xmltv_gunzip_to(string $src, string $dest): bool {
  $in = @gzopen($src, 'rb');
  if (!$in) return false;
  $out = @fopen($dest, 'wb');
  if (!$out) { @gzclose($in); return false; }
  while (!gzeof($in)) {
    $buf = gzread($in, 1024 * 1024);
    if ($buf === false) break;
    fwrite($out, $buf);
  }
  @gzclose($in);
  @fclose($out);
  return is_file($dest) && filesize($dest) > 0;
}

function _xmltv_download_to_impl(string $url, string $dest, int $timeout, ?string &$err, bool $insecure): bool {
  if (!preg_match('#^https?://#i', $url)) { $err = 'invalid_url'; return false; }

  $tmp = $dest . '.part';
  @unlink($tmp);
  $fh = @fopen($tmp, 'wb');
  if (!$fh) { $err = 'tmp_open_failed'; return false; }

  $ch = curl_init($url);
  $opts = [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_FILE => $fh,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => min(20, $timeout),
    CURLOPT_USERAGENT => 'IPTV-XMLTV-Proxy/1.4',
    CURLOPT_ENCODING => '', // accept gzip/deflate transparently
    CURLOPT_FAILONERROR => false,
  ];
  if (stripos($url, 'https://') === 0) {
    $opts[CURLOPT_SSL_VERIFYPEER] = $insecure ? false : true;
    $opts[CURLOPT_SSL_VERIFYHOST] = $insecure ? 0 : 2;
  }
  curl_setopt_array($ch, $opts);

  $ok = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $cerr = curl_error($ch);
  $cno  = curl_errno($ch);
  curl_close($ch);
  @fclose($fh);

  if (!$ok || $code < 200 || $code >= 400) {
    @unlink($tmp);
    $err = trim(($cno ? ("curl_errno={$cno}") : '') . ($cerr ? (" {$cerr}") : '') . ($code ? (" http={$code}") : ''));
    if ($err === '') $err = 'download_failed';
    return false;
  }

  @rename($tmp, $dest);
  if (!is_file($dest) || filesize($dest) <= 0) {
    $err = 'empty_download';
    return false;
  }
  $err = null;
  return true;
}

function _xmltv_download_to(string $url, string $dest, int $timeout = 60, ?string &$err = null): bool {
  $err1 = null;
  if (_xmltv_download_to_impl($url, $dest, $timeout, $err1, false)) {
    $err = null;
    return true;
  }

  // Common shared-hosting issue: missing CA bundle => SSL verify failure.
  if (stripos($url, 'https://') === 0 && $err1) {
    $sslish = (stripos($err1, 'ssl') !== false) || (stripos($err1, 'certificate') !== false) || (stripos($err1, 'cainfo') !== false) || (stripos($err1, 'issuer') !== false);
    if ($sslish) {
      $err2 = null;
      if (_xmltv_download_to_impl($url, $dest, $timeout, $err2, true)) {
        $err = 'ssl_verify_failed_retry_insecure_ok';
        return true;
      }
      $err = trim($err1 . ' | ' . ($err2 ?? ''));
      return false;
    }
  }

  $err = $err1;
  return false;
}

function _xmltv_scan_selected_ids(string $xmlPath, array $rules, array $wantRegions): array {
  $want = array_fill_keys(array_map('strtolower', $wantRegions), true);
  $selected = [];

  $xr = new XMLReader();
  if (!$xr->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Failed to open XMLTV.');
  }

  while ($xr->read()) {
    if ($xr->nodeType !== XMLReader::ELEMENT) continue;
    if ($xr->name !== 'channel') continue;

    $id = (string)($xr->getAttribute('id') ?? '');
    if ($id === '') { $xr->next(); continue; }

    $depth = $xr->depth;
    $name = '';
    while ($xr->read()) {
      if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'display-name') {
        $name = trim((string)$xr->readString());
      }
      if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'channel' && $xr->depth === $depth) break;
    }

    $region = _xmltv_detect_region($id, $name, $rules);
    if (isset($want[strtolower($region)])) {
      $selected[] = $id;
    }
  }

  $xr->close();
  return array_values(array_unique($selected));
}

function _xmltv_extract_to_file(string $xmlPath, array $selectedIds, string $outPath): void {
  $selected = array_fill_keys($selectedIds, true);

  $xr = new XMLReader();
  if (!$xr->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Failed to open XMLTV.');
  }

  $xw = new XMLWriter();
  if (!$xw->openURI($outPath)) {
    $xr->close();
    throw new RuntimeException('Failed to create output XML.');
  }

  $xw->startDocument('1.0', 'UTF-8');

  // Copy <tv> root + attributes.
  $foundRoot = false;
  while ($xr->read()) {
    if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'tv') {
      $foundRoot = true;
      $xw->startElement('tv');
      if ($xr->hasAttributes) {
        while ($xr->moveToNextAttribute()) {
          $xw->writeAttribute($xr->name, (string)$xr->value);
        }
        $xr->moveToElement();
      }
      break;
    }
  }
  if (!$foundRoot) $xw->startElement('tv');

  $ok = $xr->read();
  while ($ok) {
    if ($xr->nodeType !== XMLReader::ELEMENT) { $ok = $xr->read(); continue; }

    if ($xr->name === 'channel') {
      $id = (string)($xr->getAttribute('id') ?? '');
      if (isset($selected[$id])) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $node = $xr->expand($dom);
        if ($node instanceof DOMNode) {
          $xml = $dom->saveXML($node);
          if ($xml !== false && $xml !== '') $xw->writeRaw($xml);
        }
      }
      $ok = $xr->next();
      continue;
    }

    if ($xr->name === 'programme') {
      $ch = (string)($xr->getAttribute('channel') ?? '');
      if (isset($selected[$ch])) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $node = $xr->expand($dom);
        if ($node instanceof DOMNode) {
          $xml = $dom->saveXML($node);
          if ($xml !== false && $xml !== '') $xw->writeRaw($xml);
        }
      }
      $ok = $xr->next();
      continue;
    }

    $ok = $xr->read();
  }

  $xw->endElement();
  $xw->endDocument();
  $xw->flush();
  $xr->close();
}

function _xmltv_clean_output_buffers(): void {
  while (ob_get_level() > 0) { @ob_end_clean(); }
}

function _xmltv_stream_file(string $path): void {
  _xmltv_clean_output_buffers();
  @set_time_limit(0);
  header('Content-Type: application/xml; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store');
  if (is_file($path)) {
    // Avoid Content-Length here (server/output compression can cause truncation issues).
    readfile($path);
  } else {
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><tv></tv>";
  }
  exit;
}

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
// Hard bans (IP)
$ban = abuse_ip_ban_lookup($pdo, $ip);
if ($ban) {
  audit_log('ban_block', (int)$user['id'], ['ban_type'=>'ip','ip'=>$ip]);
  telemetry_reason('banned_ip');
  http_response_code(403);
  echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><tv></tv>";
  exit;
}

// Hard bans (user)
$ban = abuse_user_ban_lookup($pdo, (int)$user['id']);
if ($ban) {
  audit_log('ban_block_user', (int)$user['id'], ['ban_type'=>'user','ip'=>$ip]);
  telemetry_reason('banned_user');
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

/* If DB has no epg_programs, proxy upstream XMLTV if configured.
   Also supports region filtering: xmltv.php?region=USA (comma-separated). */
$regionRaw = trim((string)($_GET['region'] ?? ($_GET['regions'] ?? ($_GET['profile'] ?? ''))));
$wantRegions = [];
if ($regionRaw !== '') {
  $wantRegions = array_values(array_filter(array_map('trim', preg_split('/[,+]/', $regionRaw) ?: []), fn($v) => $v !== ''));
}

$epg_count = (int)($pdo->query("SELECT COUNT(*) c FROM epg_programs")->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
$src = $pdo->query("SELECT * FROM epg_sources WHERE enabled=1 ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$debug = (string)($_GET['debug'] ?? '') === '1';
if ($debug) {
  $willProxy = ($src && !empty($src['xmltv_url']) && ($epg_count === 0 || $wantRegions)) ? true : false;
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => true,
    'php' => PHP_VERSION,
    'ext' => [
      'curl' => function_exists('curl_init'),
      'xmlreader' => class_exists('XMLReader'),
      'zlib' => function_exists('gzopen')
    ],
    'user' => ['id' => (int)$user['id'], 'username' => (string)$user['username']],
    'ip' => get_client_ip(),
    'regions' => $wantRegions,
    'epg_programs_count' => $epg_count,
    'epg_source_enabled' => $src ? true : false,
    'epg_source_name' => $src['name'] ?? null,
    'epg_source_is_url' => ($src && !empty($src['xmltv_url']) && preg_match('#^https?://#i', (string)$src['xmltv_url'])) ? true : false,
    'will_proxy' => $willProxy
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  exit;
}


if ($src && !empty($src['xmltv_url']) && ($epg_count === 0 || $wantRegions)) {
  @set_time_limit(0);
  $u = trim((string)$src['xmltv_url']);
  $rulesText = trim((string)($src['region_rules'] ?? ''));
  if ($rulesText === '') $rulesText = _xmltv_default_rules();
  $ttl = (int)($src['cache_ttl'] ?? 21600);
  if ($ttl <= 0) $ttl = 21600;

  $cacheDir = __DIR__ . '/storage/epg_cache';
  if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);

  $mode = $wantRegions ? ('regions:' . strtolower(implode(',', $wantRegions))) : 'all';
  $key = hash('sha256', $u . "\n" . $rulesText . "\n" . $mode);
  $cacheFile = $cacheDir . '/xmltv_' . $key . '.xml';

  if (is_file($cacheFile) && @filemtime($cacheFile) !== false && (time() - filemtime($cacheFile)) < $ttl && filesize($cacheFile) > 16) {
    telemetry_reason($wantRegions ? 'proxy_cache_hit_region' : 'proxy_cache_hit', ['mode'=>$mode]);
    _xmltv_stream_file($cacheFile);
  }

  $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xt_xmltv_' . bin2hex(random_bytes(8));
  $tmpDownload = $tmpBase . '.bin';
  $tmpXml = $tmpBase . '.xml';
  $xmlPath = '';

  try {
    if (preg_match('#^https?://#i', $u)) {
        $dlErr = null;
      if (!_xmltv_download_to($u, $tmpDownload, 90, $dlErr)) {
        throw new RuntimeException('Failed to download XMLTV' . ($dlErr ? (': ' . $dlErr) : '.'));
      }
    } else {
      $path = $u;
      if ($path !== '' && $path[0] !== '/' && !preg_match('#^[A-Za-z]:\\\\#', $path)) {
        $path = __DIR__ . '/' . $path;
      }
      $real = realpath($path);
      if (!$real || !is_file($real)) {
        throw new RuntimeException('Local XMLTV path not found.');
      }
      if (!@copy($real, $tmpDownload)) {
        throw new RuntimeException('Failed to read local XMLTV file.');
      }
    }

    if (_xmltv_is_gzip_file($tmpDownload)) {
      if (!_xmltv_gunzip_to($tmpDownload, $tmpXml)) {
        throw new RuntimeException('XMLTV appears gzipped but could not be decompressed.');
      }
      $xmlPath = $tmpXml;
    } else {
      @rename($tmpDownload, $tmpXml);
      $xmlPath = $tmpXml;
    }

    if ($wantRegions) {
      $rules = _xmltv_parse_rules($rulesText);
      if (!$rules) $rules = _xmltv_parse_rules(_xmltv_default_rules());
      $selectedIds = _xmltv_scan_selected_ids($xmlPath, $rules, $wantRegions);
      if (!$selectedIds) {
        throw new RuntimeException('No channels matched requested region(s).');
      }
      _xmltv_extract_to_file($xmlPath, $selectedIds, $cacheFile);
      telemetry_reason('proxy_region_generated', ['mode'=>$mode, 'channels'=>count($selectedIds)]);
      _xmltv_stream_file($cacheFile);
    }

    // Full upstream cache (no DB import present).
    if (!@copy($xmlPath, $cacheFile)) {
      // If copy fails for any reason, just stream the temp file.
      telemetry_reason('proxy_stream_temp', ['mode'=>$mode]);
      _xmltv_stream_file($xmlPath);
    }
    telemetry_reason('proxy_generated', ['mode'=>$mode]);
    _xmltv_stream_file($cacheFile);
  } catch (Throwable $e) {
    telemetry_reason('proxy_fail', ['mode'=>$mode, 'err'=>$e->getMessage()]);
    // Fall through to DB output.
  } finally {
    @unlink($tmpDownload);
    @unlink($tmpXml);
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
      ".($adult_ok ? "" : " AND IFNULL(c.is_adult,0)=0 AND IFNULL(cat.is_adult,0)=0 ")."
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
    echo "  <channel id=\"" . _xmltv_attr($id) . "\">\n";
    echo "    <display-name>"._xmltv_x((string)($c['tvg_name'] ?: $c['name']))."</display-name>\n";
    if (!empty($c['tvg_logo'])) {
      echo "    <icon src=\"" . _xmltv_attr((string)$c['tvg_logo']) . "\" />\n";
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
      echo "  <channel id=\"" . _xmltv_attr($id) . "\">\n";
      echo "    <display-name>"._xmltv_x((string)($r['display_name'] ?: $id))."</display-name>\n";
      if (!empty($r['icon_src'])) {
        echo "    <icon src=\"" . _xmltv_attr((string)$r['icon_src']) . "\" />\n";
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
      echo "  <channel id=\"" . _xmltv_attr($id) . "\">\n";
      echo "    <display-name>"._xmltv_x((string)$id)."</display-name>\n";
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
      echo "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"" . _xmltv_attr((string)$p['channel_xmltv_id']) . "\">\n";
      echo "    <title>" . _xmltv_x((string)$p['title']) . "</title>\n";
      if (!empty($p['descr'])) echo "    <desc>" . _xmltv_x((string)$p['descr']) . "</desc>\n";
      echo "  </programme>\n";
    }
  }
}

echo "</tv>\n";
