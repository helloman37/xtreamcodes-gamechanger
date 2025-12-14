<?php
// admin/epg_extract.php
// Upload an XMLTV file, auto-detect "locations" (regions) based on channel id/display-name,
// then extract a filtered epg.xml containing only selected regions (channels + programmes).

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

@set_time_limit(0);

$topbar = file_get_contents(__DIR__ . '/topbar.html');

function xtream_epg_tmp_prefix(): string { return 'xtream_epg_extract_'; }

function xtream_epg_cleanup_tmp(): void {
  $dir = sys_get_temp_dir();
  $prefix = xtream_epg_tmp_prefix();
  foreach (glob($dir . DIRECTORY_SEPARATOR . $prefix . '*') as $p) {
    // 12 hours
    if (is_file($p) && @filemtime($p) !== false && filemtime($p) < (time() - 12*3600)) {
      @unlink($p);
    }
  }
}

function xtream_parse_rules(string $text): array {
  $rules = [];
  $lines = preg_split('/\r\n|\r|\n/', $text);
  foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || substr($line, 0, 1) === '#') continue;
    $parts = explode('=', $line, 2);
    if (count($parts) !== 2) continue;
    $region = trim($parts[0]);
    $keys = array_filter(array_map('trim', explode(',', $parts[1])), fn($v) => $v !== '');
    if ($region !== '' && $keys) $rules[] = ['region' => $region, 'keys' => $keys];
  }
  return $rules;
}

function xtream_detect_region(string $id, string $name, array $rules): string {
  $hay = strtolower($id . ' ' . $name);
  foreach ($rules as $r) {
    foreach ($r['keys'] as $k) {
      $k = strtolower((string)$k);
      if ($k !== '' && strpos($hay, $k) !== false) return (string)$r['region'];
    }
  }
  return 'Other';
}

function xtream_is_gzip_file(string $path): bool {
  $fh = @fopen($path, 'rb');
  if (!$fh) return false;
  $b = fread($fh, 2);
  fclose($fh);
  return $b !== false && strlen($b) === 2 && ord($b[0]) === 0x1f && ord($b[1]) === 0x8b;
}

function xtream_gunzip_to(string $src, string $dest): bool {
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

function xtream_download_to(string $url, string $dest, int $timeout = 90): bool {
  if (!preg_match('#^https?://#i', $url)) return false;
  $tmp = $dest . '.part';
  @unlink($tmp);
  $fh = @fopen($tmp, 'wb');
  if (!$fh) return false;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_FILE => $fh,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => min(20, $timeout),
    CURLOPT_USERAGENT => 'IPTV-EPG-Extract/1.0',
    CURLOPT_ENCODING => ''
  ]);
  $ok = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  @fclose($fh);

  if (!$ok || $code < 200 || $code >= 400) {
    @unlink($tmp);
    return false;
  }
  @rename($tmp, $dest);
  return is_file($dest) && filesize($dest) > 0;
}

function xtream_scan_channels(string $xmlPath, array $rules): array {
  $xr = new XMLReader();
  if (!$xr->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Failed to open XMLTV file.');
  }

  $regions = [];     // region => [ids...]
  $channels = [];    // id => name
  $examples = [];    // region => [name...]
  $counts = [];      // region => int

  while ($xr->read()) {
    if ($xr->nodeType !== XMLReader::ELEMENT) continue;
    if ($xr->name !== 'channel') continue;

    $id = (string)($xr->getAttribute('id') ?? '');
    if ($id === '') { $xr->next(); continue; }

    $depth = $xr->depth;
    $name = '';

    // Read within <channel> for first <display-name>
    while ($xr->read()) {
      if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'display-name') {
        $name = trim((string)$xr->readString());
        // consume until end of display-name handled by reader
      }
      if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'channel' && $xr->depth === $depth) {
        break;
      }
    }

    $region = xtream_detect_region($id, $name, $rules);
    $regions[$region] = $regions[$region] ?? [];
    $regions[$region][] = $id;
    $channels[$id] = $name;

    $counts[$region] = ($counts[$region] ?? 0) + 1;
    if (!isset($examples[$region])) $examples[$region] = [];
    if ($name !== '' && count($examples[$region]) < 5) $examples[$region][] = $name;
  }

  $xr->close();

  ksort($counts);
  return [
    'counts' => $counts,
    'regions' => $regions,
    'channels' => $channels,
    'examples' => $examples,
  ];
}

/**
 * Ensure nothing else leaks into downloads.
 * Some hosting / includes may start output buffering; clear it before streaming files.
 */
function xtream_clean_output_buffers(): void {
  while (ob_get_level() > 0) {
    @ob_end_clean();
  }
}

function xtream_extract_xmltv(string $xmlPath, array $selectedIds, string $downloadName = 'epg_filtered.xml'): void {
  $selected = array_fill_keys($selectedIds, true);

  // Build the filtered XML to a temp file first (prevents any HTML/layout output corrupting XML).
  $outPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . xtream_epg_tmp_prefix() . 'out_' . bin2hex(random_bytes(8)) . '.xml';

  $xr = new XMLReader();
  if (!$xr->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    http_response_code(500);
    echo "Failed to open XMLTV.";
    exit;
  }

  $xw = new XMLWriter();
  if (!$xw->openURI($outPath)) {
    $xr->close();
    http_response_code(500);
    echo "Failed to create output XML.";
    exit;
  }

  $xw->startDocument('1.0', 'UTF-8');

  // Find <tv> root and copy attributes (if present)
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

  if (!$foundRoot) {
    // Not a standard XMLTV root; still produce a valid container
    $xw->startElement('tv');
  }

  // Stream through nodes, write only selected channel + programme.
  // IMPORTANT: do not call XMLReader::next() inside a `while ($xr->read())` loop.
  // `next()` positions the reader on the next sibling element; the next loop iteration
  // would call `read()` again and skip the sibling's start element.
  // We control advancement manually to keep output complete.
  $ok = $xr->read(); // move from <tv> into its children
  while ($ok) {
    if ($xr->nodeType !== XMLReader::ELEMENT) {
      $ok = $xr->read();
      continue;
    }

    if ($xr->name === 'channel') {
      $id = (string)($xr->getAttribute('id') ?? '');
      if (isset($selected[$id])) {
        // Avoid DOMNode->ownerDocument being null on some hosting/libxml builds.
        // Expand into a fresh DOMDocument and serialize from that.
        $dom = new DOMDocument('1.0', 'UTF-8');
        $node = $xr->expand($dom);
        if ($node instanceof DOMNode) {
          $xml = $dom->saveXML($node);
          if ($xml !== false && $xml !== '') $xw->writeRaw($xml);
        }
      }
      // Skip the entire <channel> subtree and move to the next sibling element.
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
      // Skip the entire <programme> subtree and move to the next sibling element.
      $ok = $xr->next();
      continue;
    }

    // Any other element under <tv> (rare) — just advance.
    $ok = $xr->read();
  }

  $xw->endElement(); // tv
  $xw->endDocument();
  $xw->flush();
  $xr->close();

  // Now stream the *file* to the browser cleanly.
  xtream_clean_output_buffers();
  header('Content-Type: application/xml; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $downloadName . '"');
  header('X-Content-Type-Options: nosniff');
  if (is_file($outPath)) {
    header('Content-Length: ' . filesize($outPath));
    readfile($outPath);
  }
  @unlink($outPath);
  exit;
}

xtream_epg_cleanup_tmp();

$default_rules = <<<TXT
# Format: REGION=keyword1,keyword2,keyword3
USA=usa,.us,united states,us|
Canada=canada,.ca
UK=uk,.uk,united kingdom
Australia=australia,.au
Asia=asia,asia|,hk,sg,ph,my,th,jp,kr
India=india,.in
Europe=europe,eu
Latin=latin,latam
Africa=africa
Middle East=middle east,mena,uae,saudi,qatar
TXT;

$step = $_POST['step'] ?? '';
$token = $_POST['token'] ?? '';
$meta = null;
$error = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    if ($step === 'upload') {
      $fromUrl = trim((string)($_POST['xmltv_url'] ?? ''));
      $hasUpload = (!empty($_FILES['xmltv']['tmp_name']) && is_uploaded_file($_FILES['xmltv']['tmp_name']));
      if (!$hasUpload && $fromUrl === '') {
        throw new RuntimeException('Provide either an uploaded XMLTV file or an XMLTV URL.');
      }

      $rulesText = (string)($_POST['rules'] ?? $default_rules);
      $rules = xtream_parse_rules($rulesText);

      if (!$rules) throw new RuntimeException('Please provide at least one REGION=keywords rule.');

      $token = bin2hex(random_bytes(16));
      $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . xtream_epg_tmp_prefix() . $token;

      $tmpSrc = $prefix . '.upload';
      $tmpXml = $prefix . '.xml';
      $tmpMeta = $prefix . '.json';

      if ($hasUpload) {
        if (!move_uploaded_file($_FILES['xmltv']['tmp_name'], $tmpSrc)) {
          throw new RuntimeException('Failed to move uploaded file.');
        }
      } else {
        if (!xtream_download_to($fromUrl, $tmpSrc, 120)) {
          throw new RuntimeException('Failed to download XMLTV from URL.');
        }
      }

      $xmlPath = $tmpSrc;
      $gunzipped = false;
      if (xtream_is_gzip_file($tmpSrc)) {
        if (!xtream_gunzip_to($tmpSrc, $tmpXml)) {
          @unlink($tmpSrc);
          throw new RuntimeException('XMLTV file looks gzipped but could not be decompressed.');
        }
        $xmlPath = $tmpXml;
        $gunzipped = true;
      } else {
        // ensure it has .xml for tools that rely on extension
        @rename($tmpSrc, $tmpXml);
        $xmlPath = $tmpXml;
      }

      $scan = xtream_scan_channels($xmlPath, $rules);

      $meta = [
        'token' => $token,
        'xml_path' => $xmlPath,
        'xmltv_url' => ($hasUpload ? null : $fromUrl),
        'rules' => $rulesText,
        'counts' => $scan['counts'],
        'regions' => $scan['regions'],
        'examples' => $scan['examples'],
        'created_at' => time()
      ];

      file_put_contents($tmpMeta, json_encode($meta, JSON_UNESCAPED_UNICODE));

    } elseif ($step === 'extract') {
      $token = preg_replace('/[^a-f0-9]/', '', (string)$token);
      if (strlen($token) !== 32) throw new RuntimeException('Invalid token.');

      $prefix = sys_get_temp_dir() . DIRECTORY_SEPARATOR . xtream_epg_tmp_prefix() . $token;
      $tmpXml = $prefix . '.xml';
      $tmpMeta = $prefix . '.json';

      if (!is_file($tmpXml) || !is_file($tmpMeta)) throw new RuntimeException('Session expired. Please upload again.');

      $meta = json_decode((string)file_get_contents($tmpMeta), true);
      if (!is_array($meta)) throw new RuntimeException('Invalid session meta.');

      $selectedRegions = (array)($_POST['regions'] ?? []);
      if (!$selectedRegions) throw new RuntimeException('Please select at least one region.');

      $regionsMap = $meta['regions'] ?? [];
      $selectedIds = [];
      foreach ($selectedRegions as $r) {
        $r = (string)$r;
        if (!isset($regionsMap[$r]) || !is_array($regionsMap[$r])) continue;
        foreach ($regionsMap[$r] as $id) $selectedIds[] = (string)$id;
      }
      $selectedIds = array_values(array_unique($selectedIds));
      if (!$selectedIds) throw new RuntimeException('No channels matched your selection.');

      // Clean filename
      $dl = 'epg_filtered_' . date('Ymd_His') . '.xml';

      // Extract and stream to browser
      // Cleanup afterwards (best-effort; may not run if client aborts)
      register_shutdown_function(function() use ($tmpXml, $tmpMeta) {
        @unlink($tmpXml);
        @unlink($tmpMeta);
      });

      xtream_extract_xmltv($tmpXml, $selectedIds, $dl);
    }
  }
} catch (Throwable $t) {
  $error = $t->getMessage();
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>EPG Extract / Filter</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    .grid2{display:grid; grid-template-columns: 1.2fr .8fr; gap:14px;}
    @media (max-width: 980px){ .grid2{grid-template-columns:1fr;} }
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
    .small{font-size:12px; opacity:.85;}
    .region-list{display:grid; grid-template-columns:1fr; gap:8px;}
    .region-item{display:flex; gap:10px; align-items:flex-start; padding:10px; border:1px solid var(--line); border-radius:12px; background:rgba(255,255,255,.04);}
    .pill{display:inline-flex; align-items:center; gap:8px; padding:4px 10px; border-radius:999px; background:rgba(79,109,245,.18); border:1px solid rgba(79,109,245,.28); font-weight:800;}
  </style>
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>EPG Extract / Filter</h2>
  <p class="muted">Upload an <strong>XMLTV</strong> file and extract a smaller <code>epg.xml</code> by “location” (region) based on channel <em>id</em> / <em>display-name</em> keyword rules.</p>

  <?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
  <?php endif; ?>

  <?php if (!$meta): ?>
    <div class="grid2">
      <div>
        <h3>1) XMLTV input</h3>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_input() ?>
          <input type="hidden" name="step" value="upload">
          <div class="row">
            <label class="muted">XMLTV URL (optional)</label><br>
            <input type="text" name="xmltv_url" placeholder="https://example.com/epg.xml.gz" value="<?= e($_POST['xmltv_url'] ?? '') ?>">
          </div>

          <div class="row" style="margin-top:10px;">
            <label class="muted">Or upload XMLTV file (.xml or .gz)</label><br>
            <input type="file" name="xmltv" accept=".xml,.gz,application/gzip,application/xml,text/xml">
          </div>

          <div class="row" style="margin-top:10px;">
            <label class="muted">Region rules</label>
            <div class="small muted">Each line: <span class="mono">REGION=keyword1,keyword2</span> (case-insensitive substring match). Use whatever your provider puts in ids/names (ex: <span class="mono">.us</span>, <span class="mono">USA</span>, <span class="mono">Asia|</span>).</div>
            <textarea class="mono" name="rules" rows="10" style="width:100%; margin-top:6px;"><?= e($_POST['rules'] ?? $default_rules) ?></textarea>
          </div>

          <button type="submit" class="btn" style="margin-top:10px;">Scan &amp; Show Regions</button>
        </form>
      </div>

      <div>
        <h3>Notes</h3>
        <div class="box">
          <div class="small muted">
            XMLTV doesn’t have a universal “country/region” field, so this tool groups channels using keyword rules against:
            <ul>
              <li><span class="mono">&lt;channel id="..."&gt;</span></li>
              <li><span class="mono">&lt;display-name&gt;...&lt;/display-name&gt;</span></li>
            </ul>
            If your provider uses different tags/formatting, just tweak the keywords and scan again.
          </div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <h3>2) Select regions to extract</h3>

    <div class="row" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <span class="pill">Found <?= (int)array_sum($meta['counts'] ?? []) ?> channels</span>
      <span class="small muted">Token: <span class="mono"><?= e((string)$meta['token']) ?></span></span>
    </div>

    <form method="post" style="margin-top:10px;">
      <?= csrf_input() ?>
      <input type="hidden" name="step" value="extract">
      <input type="hidden" name="token" value="<?= e((string)$meta['token']) ?>">

      <div class="region-list">
        <?php
          $counts = $meta['counts'] ?? [];
          $examples = $meta['examples'] ?? [];
          foreach ($counts as $region => $cnt):
            $region = (string)$region;
            $ex = $examples[$region] ?? [];
        ?>
          <label class="region-item">
            <input type="checkbox" name="regions[]" value="<?= e($region) ?>" checked>
            <div>
              <div><strong><?= e($region) ?></strong> <span class="muted small">(<?= (int)$cnt ?> channels)</span></div>
              <?php if ($ex): ?>
                <div class="small muted">Examples: <?= e(implode(' · ', array_slice($ex, 0, 5))) ?></div>
              <?php endif; ?>
            </div>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="row" style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
        <button type="submit" class="btn">Extract &amp; Download epg.xml</button>
        <a class="btn" href="epg_extract.php" style="background:#2b3545;">Start Over</a>
      </div>
      <div class="small muted" style="margin-top:8px;">
        Output contains only the selected regions’ <span class="mono">&lt;channel&gt;</span> and matching <span class="mono">&lt;programme&gt;</span> entries.
      </div>
    </form>
  <?php endif; ?>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
