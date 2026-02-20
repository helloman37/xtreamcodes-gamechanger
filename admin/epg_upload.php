<?php
// admin/epg_upload.php
// Upload a local XMLTV file (.xml or .gz), store it on disk, and create/update a single epg_sources entry
// so it can be imported via EPG → Import / Run (same flow as URL sources).

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

require_admin();
$pdo = db();
$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

$upload_dir = realpath(__DIR__ . '/../storage/epg_uploads');
if ($upload_dir === false) {
  // Create if missing
  @mkdir(__DIR__ . '/../storage/epg_uploads', 0775, true);
  $upload_dir = realpath(__DIR__ . '/../storage/epg_uploads');
}

function epg_upload_safe_name(string $name): string {
  $name = basename($name);
  $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'epg.xml';
  // avoid empty
  if (trim($name, '._-') === '') $name = 'epg.xml';
  return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  if ($upload_dir === false || !is_dir($upload_dir)) {
    flash_set("Upload folder is not writable: storage/epg_uploads", "error");
    header("Location: epg_upload.php");
    exit;
  }
  if (!is_writable($upload_dir)) {
    flash_set("Upload folder is not writable: storage/epg_uploads", "error");
    header("Location: epg_upload.php");
    exit;
  }

  $name = trim($_POST['name'] ?? '');
  $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;

  /**
   * Normalize $_FILES into a flat list of file objects:
   * [
   *   ['name'=>..., 'tmp_name'=>..., 'error'=>..., 'size'=>..., 'type'=>...],
   *   ...
   * ]
   */
  $filesRaw = null;
  if (isset($_FILES['xmltv_files'])) $filesRaw = $_FILES['xmltv_files'];
  else if (isset($_FILES['xmltv'])) $filesRaw = $_FILES['xmltv'];

  if (!$filesRaw || !is_array($filesRaw)) {
    flash_set("No file uploaded.", "error");
    header("Location: epg_upload.php");
    exit;
  }

  $uploads = [];
  if (is_array($filesRaw['name'] ?? null)) {
    $count = count($filesRaw['name']);
    for ($i = 0; $i < $count; $i++) {
      $uploads[] = [
        'name' => $filesRaw['name'][$i] ?? '',
        'type' => $filesRaw['type'][$i] ?? '',
        'tmp_name' => $filesRaw['tmp_name'][$i] ?? '',
        'error' => $filesRaw['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        'size' => $filesRaw['size'][$i] ?? 0,
      ];
    }
  } else {
    $uploads[] = $filesRaw;
  }

  // Remove empties (in case a browser sends an empty slot)
  $uploads = array_values(array_filter($uploads, function($f) {
    return isset($f['name']) && trim((string)$f['name']) !== '';
  }));

  if (count($uploads) < 1) {
    flash_set("No file uploaded.", "error");
    header("Location: epg_upload.php");
    exit;
  }
  if (count($uploads) > 2) {
    flash_set("Please upload a maximum of 2 XMLTV files at a time.", "error");
    header("Location: epg_upload.php");
    exit;
  }

  $stamp = date('Ymd_His');
  $savedAbs = [];
  $savedNames = [];

  foreach ($uploads as $f) {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      flash_set("Upload failed (error code: " . (int)($f['error'] ?? -1) . ")", "error");
      header("Location: epg_upload.php");
      exit;
    }

    $tmp = $f['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) {
      flash_set("Upload temp file missing.", "error");
      header("Location: epg_upload.php");
      exit;
    }

    $orig = (string)($f['name'] ?? 'epg.xml');
    $safe = epg_upload_safe_name($orig);
    $lower = strtolower($safe);

    if (!preg_match('/\.(xml|gz)$/', $lower)) {
      flash_set("Only .xml or .gz files are allowed.", "error");
      header("Location: epg_upload.php");
      exit;
    }

    $base = preg_replace('/\.(xml|gz)$/', '', $safe) ?: 'epg';
    $ext  = preg_match('/\.gz$/', $lower) ? 'gz' : 'xml';
    $rand = substr(bin2hex(random_bytes(4)), 0, 8);
    $dest_name = epg_upload_safe_name($base . "_{$stamp}_{$rand}." . $ext);

    $dest_abs = $upload_dir . DIRECTORY_SEPARATOR . $dest_name;
    if (!@move_uploaded_file($tmp, $dest_abs)) {
      flash_set("Failed to save uploaded file.", "error");
      header("Location: epg_upload.php");
      exit;
    }

    $savedAbs[] = $dest_abs;
    $savedNames[] = $safe;
  }

  // If two files are uploaded, combine them into a single XMLTV on the server.
  $finalAbs = $savedAbs[0];
  $finalRel = 'storage/epg_uploads/' . basename($finalAbs);

  if (count($savedAbs) === 2) {

    // Decompress gz if needed to temp XML paths for parsing.
    $tmpXmls = [];
    $parsePaths = [];

    foreach ($savedAbs as $abs) {
      if (preg_match('/\.gz$/i', $abs)) {
        $tmpXml = tempnam(sys_get_temp_dir(), 'xmltv_');
        if ($tmpXml === false) {
          flash_set("Failed to create temp file for gz extraction.", "error");
          header("Location: epg_upload.php");
          exit;
        }
        $tmpXml .= '.xml';

        $in = @gzopen($abs, 'rb');
        if (!$in) {
          flash_set("Failed to open uploaded .gz file.", "error");
          header("Location: epg_upload.php");
          exit;
        }
        $out = @fopen($tmpXml, 'wb');
        if (!$out) {
          gzclose($in);
          flash_set("Failed to write temp XML file.", "error");
          header("Location: epg_upload.php");
          exit;
        }
        while (!gzeof($in)) {
          $chunk = gzread($in, 1024 * 1024);
          if ($chunk === false) break;
          fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);

        $tmpXmls[] = $tmpXml;
        $parsePaths[] = $tmpXml;
      } else {
        $parsePaths[] = $abs;
      }
    }

    // Read tv root attributes from the first file (best-effort).
    $tvAttrs = [];
    $xrAttr = new XMLReader();
    if (@$xrAttr->open($parsePaths[0])) {
      while ($xrAttr->read()) {
        if ($xrAttr->nodeType === XMLReader::ELEMENT && $xrAttr->name === 'tv') {
          if ($xrAttr->moveToFirstAttribute()) {
            do {
              $tvAttrs[$xrAttr->name] = $xrAttr->value;
            } while ($xrAttr->moveToNextAttribute());
            $xrAttr->moveToElement();
          }
          break;
        }
      }
      $xrAttr->close();
    }

    $rand = substr(bin2hex(random_bytes(4)), 0, 8);
    $combinedName = epg_upload_safe_name("combined_{$stamp}_{$rand}.xml");
    $combinedAbs = $upload_dir . DIRECTORY_SEPARATOR . $combinedName;

    $xw = new XMLWriter();
    if (!$xw->openURI($combinedAbs)) {
      flash_set("Failed to write combined XMLTV file.", "error");
      header("Location: epg_upload.php");
      exit;
    }
    $xw->startDocument('1.0', 'UTF-8');
    $xw->startElement('tv');

    // Apply copied attributes (if any), then ensure generator-info-name is present.
    foreach ($tvAttrs as $k => $v) {
      // Skip if empty
      if ($k !== '' && $v !== null) $xw->writeAttribute($k, (string)$v);
    }
    if (!isset($tvAttrs['generator-info-name'])) {
      $xw->writeAttribute('generator-info-name', 'IPTV Panel');
    }

    $seenChannels = [];

    foreach ($parsePaths as $p) {
      $xr = new XMLReader();
      if (!@$xr->open($p)) continue;

      while ($xr->read()) {
        if ($xr->nodeType !== XMLReader::ELEMENT) continue;

        if ($xr->name === 'channel') {
          $id = (string)($xr->getAttribute('id') ?? '');
          if ($id !== '' && isset($seenChannels[$id])) { $xr->next(); continue; }

          $dom = new DOMDocument('1.0', 'UTF-8');
          $node = $xr->expand($dom);
          if ($node instanceof DOMNode) {
            $xml = $dom->saveXML($node);
            if ($xml !== false) $xw->writeRaw($xml);
          }
          if ($id !== '') $seenChannels[$id] = true;
          $xr->next();
          continue;
        }

        if ($xr->name === 'programme') {
          $dom = new DOMDocument('1.0', 'UTF-8');
          $node = $xr->expand($dom);
          if ($node instanceof DOMNode) {
            $xml = $dom->saveXML($node);
            if ($xml !== false) $xw->writeRaw($xml);
          }
          $xr->next();
          continue;
        }
      }
      $xr->close();
    }

    $xw->endElement(); // tv
    $xw->endDocument();
    $xw->flush();

    // Clean up temp XMLs created from gz
    foreach ($tmpXmls as $t) { @unlink($t); }

    $finalAbs = $combinedAbs;
    $finalRel = 'storage/epg_uploads/' . $combinedName;

    if ($name === '') {
      $name = 'Upload (Combined): ' . $savedNames[0] . ' + ' . $savedNames[1];
    }
  } else {
    if ($name === '') $name = 'Upload: ' . $savedNames[0];
  }

  // Instead of creating a new epg_sources row every time, we automatically reuse (update)
// the most recent local-upload source (xmltv_url under storage/epg_uploads/). This keeps the Sources list clean.
$existing = $pdo->query("SELECT id, name, xmltv_url FROM epg_sources WHERE xmltv_url LIKE 'storage/epg_uploads/%' ORDER BY id DESC LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);

if (is_array($existing) && isset($existing['id'])) {
  $id = (int)$existing['id'];

  // If admin didn't provide a name, keep the existing name (so it stays stable across uploads).
  if (trim((string)($_POST['name'] ?? '')) === '') {
    $name = (string)($existing['name'] ?? $name);
  }

  $pdo->prepare("UPDATE epg_sources SET name=?, xmltv_url=?, enabled=? WHERE id=?")
      ->execute([$name, $finalRel, $enabled, $id]);

  // Delete the previous uploaded file to avoid clutter (safety: only delete within storage/epg_uploads).
  $oldRel = (string)($existing['xmltv_url'] ?? '');
  if ($oldRel !== '' && $oldRel !== $finalRel && strpos($oldRel, 'storage/epg_uploads/') === 0) {
    $oldAbs = realpath(__DIR__ . '/../' . $oldRel);
    if ($oldAbs !== false) {
      $normOld = str_replace('\\', '/', $oldAbs);
      $normDir = str_replace('\\', '/', (string)$upload_dir);
      if (strpos($normOld, rtrim($normDir, '/') . '/') === 0) {
        @unlink($oldAbs);
      }
    }
  }
} else {
  $pdo->prepare("INSERT INTO epg_sources (name, xmltv_url, enabled) VALUES (?,?,?)")
      ->execute([$name, $finalRel, $enabled]);
  $id = (int)$pdo->lastInsertId();
}

  // Auto-rewrite (flush) on every upload so we don't accumulate duplicate programmes.
  flash_set("EPG uploaded and added as a source. Import will replace the previous EPG automatically.", "success");
  header("Location: epg_import.php?flush=1&source_id=" . urlencode((string)$id));
  exit;
}


?><!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Upload XMLTV</title>
  <link rel="stylesheet" href="assets/adminlte4/css/adminlte.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>
  <!-- container is opened by topbar.html -->
  <div class="card">
    <h2>Upload XMLTV (Local File)</h2>
    <p class="muted" style="margin-top:-6px; margin-bottom:12px;">
      Upload a filtered XMLTV file (<span class="mono">.xml</span> or <span class="mono">.gz</span>). It will be saved under
      <span class="mono">storage/epg_uploads/</span> and added to EPG Sources automatically.
    </p>

    <?php flash_show(); ?>

      <form method="post" enctype="multipart/form-data">
        <?= csrf_input() ?>

        <div class="grid2" style="margin-top:12px;">
          <div>
            <label class="small">Source name (optional)</label>
            <input name="name" placeholder="e.g., My Filtered USA EPG">
          </div>
          <div>
            <label class="small">Enabled</label>
            <select name="enabled">
              <option value="1" selected>Yes</option>
              <option value="0">No</option>
            </select>
          </div>
        </div>

        <div style="margin-top:12px;">
          <label class="small">XMLTV file</label>
          <input type="file" name="xmltv_files[]" multiple accept=".xml,.gz,application/gzip,application/x-gzip,text/xml" required>
        
          <div class="muted" style="margin-top:6px;">You can select <strong>1 or 2</strong> XMLTV files (.xml or .gz). If you select 2, they will be combined into a single XMLTV source on the server.<br><span class="muted">Uploads automatically <strong>overwrite your last local upload source</strong> (so sources don’t pile up).</span></div>
</div>

        <div style="margin-top:14px; display:flex; gap:10px;">
          <button class="btn" type="submit">Upload &amp; Continue to Import</button>
          <a class="btn" href="epg_manager.php" style="background:#2b3545;">Back to Sources</a>
        </div>
      </form>
  </div>

  </div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
