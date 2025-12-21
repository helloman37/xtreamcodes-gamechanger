<?php
// admin/epg_sync.php
// Upload two XMLTV files and synchronize channel IDs by matching channel display names.
// - Copies <channel id> values from the reference XMLTV into the target XMLTV and rewrites programme channel attributes.

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

require_admin();

@set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '600');

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

function sync_norm(string $s): string {
  $s = trim($s);
  $s = str_replace(["\r\n", "\r"], "\n", $s);
  // collapse whitespace
  $out = '';
  $space = false;
  $len = strlen($s);
  for ($i = 0; $i < $len; $i++) {
    $c = $s[$i];
    $isSpace = ($c === ' ' || $c === "\t" || $c === "\n");
    if ($isSpace) {
      if (!$space) { $out .= ' '; $space = true; }
      continue;
    }
    $space = false;
    $out .= $c;
  }
  return strtolower(trim($out));
}

function sync_read_upload(string $field): array {
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return [null, 'Missing upload'];
  $f = $_FILES[$field];
  $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err !== UPLOAD_ERR_OK) return [null, 'Upload failed (error ' . $err . ')'];
  $tmp = (string)($f['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) return [null, 'Upload temp file missing'];
  $name = (string)($f['name'] ?? 'file');
  return [['tmp' => $tmp, 'name' => $name], null];
}

function sync_is_gz_name(string $name): bool {
  $n = strtolower($name);
  return (substr($n, -3) === '.gz');
}

function sync_gunzip_to(string $src, string $dest): bool {
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

function sync_xml_path(array $upload): array {
  // Return [path, cleanupPaths[]] where cleanupPaths are temp files to delete.
  $tmp = $upload['tmp'];
  $name = $upload['name'];
  $cleanup = [];

  if (sync_is_gz_name($name)) {
    $out = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xtream_sync_' . bin2hex(random_bytes(8)) . '.xml';
    if (!sync_gunzip_to($tmp, $out)) return [null, $cleanup, 'Failed to decompress .gz'];
    $cleanup[] = $out;
    return [$out, $cleanup, null];
  }
  return [$tmp, $cleanup, null];
}

function xmltv_build_ref_map(string $xmlPath): array {
  $xr = new XMLReader();
  if (!$xr->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Failed to open reference XMLTV.');
  }

  $map = []; // norm(display-name) => id
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
      if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'channel' && $xr->depth === $depth) {
        break;
      }
    }
    if ($name !== '') {
      $k = sync_norm($name);
      if ($k !== '' && !isset($map[$k])) $map[$k] = $id;
    }
  }
  $xr->close();
  return $map;
}

function xmltv_build_target_id_map(string $xmlPath, array $refMap): array {
  // oldId => newId
  $xr = new XMLReader();
  if (!$xr->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Failed to open target XMLTV.');
  }

  $idMap = [];
  while ($xr->read()) {
    if ($xr->nodeType !== XMLReader::ELEMENT) continue;
    if ($xr->name !== 'channel') continue;

    $oldId = (string)($xr->getAttribute('id') ?? '');
    if ($oldId === '') { $xr->next(); continue; }

    $depth = $xr->depth;
    $name = '';
    while ($xr->read()) {
      if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'display-name') {
        $name = trim((string)$xr->readString());
      }
      if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'channel' && $xr->depth === $depth) {
        break;
      }
    }

    if ($name !== '') {
      $k = sync_norm($name);
      if ($k !== '' && isset($refMap[$k])) {
        $idMap[$oldId] = $refMap[$k];
      }
    }
  }
  $xr->close();
  return $idMap;
}

function sync_clean_output_buffers(): void {
  while (ob_get_level() > 0) {
    @ob_end_clean();
  }
}

function xmltv_write_synced(string $xmlPath, array $refMap, array $idMap, bool $overwriteDisplayName): string {
  // Write output to temp file and return the path.
  $outPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xtream_sync_out_' . bin2hex(random_bytes(8)) . '.xml';

  $xr = new XMLReader();
  if (!$xr->open($xmlPath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Failed to open target XMLTV for writing.');
  }

  // Find <tv> root and capture attributes.
  $tvAttrs = [];
  while ($xr->read()) {
    if ($xr->nodeType === XMLReader::ELEMENT && $xr->name === 'tv') {
      if ($xr->moveToFirstAttribute()) {
        do { $tvAttrs[$xr->name] = $xr->value; } while ($xr->moveToNextAttribute());
        $xr->moveToElement();
      }
      break;
    }
  }

  $xw = new XMLWriter();
  if (!$xw->openURI($outPath)) {
    $xr->close();
    throw new RuntimeException('Failed to create output file.');
  }

  $xw->startDocument('1.0', 'UTF-8');
  $xw->startElement('tv');
  foreach ($tvAttrs as $k => $v) {
    if ($k !== '') $xw->writeAttribute($k, (string)$v);
  }

  // Continue reading after <tv> and stream elements.
  while ($xr->read()) {
    if ($xr->nodeType === XMLReader::END_ELEMENT && $xr->name === 'tv') {
      break;
    }
    if ($xr->nodeType !== XMLReader::ELEMENT) continue;

    if ($xr->name === 'channel' || $xr->name === 'programme') {
      // readOuterXML() advances the reader past this element automatically.
      $outer = $xr->readOuterXML();

      $dom = new DOMDocument('1.0', 'UTF-8');
      $dom->preserveWhiteSpace = true;
      $dom->formatOutput = false;
      @$dom->loadXML($outer, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
      $node = $dom->documentElement;

      if ($node instanceof DOMElement) {
        if ($node->tagName === 'channel') {
          $oldId = $node->getAttribute('id');
          $newId = '';
          if ($oldId !== '' && isset($idMap[$oldId])) {
            $newId = $idMap[$oldId];
          } else {
            $dn = '';
            $list = $node->getElementsByTagName('display-name');
            if ($list->length > 0) $dn = trim((string)$list->item(0)->textContent);
            if ($dn !== '') {
              $k = sync_norm($dn);
              if ($k !== '' && isset($refMap[$k])) $newId = $refMap[$k];
            }
          }
          if ($newId !== '') $node->setAttribute('id', $newId);

          if ($overwriteDisplayName) {
            $list = $node->getElementsByTagName('display-name');
            if ($list->length > 1) {
              for ($i = $list->length - 1; $i >= 1; $i--) {
                $rm = $list->item($i);
                if ($rm && $rm->parentNode) $rm->parentNode->removeChild($rm);
              }
            }
          }

          $xw->writeRaw($dom->saveXML($node));
        } else {
          // programme
          $ch = $node->getAttribute('channel');
          if ($ch !== '') {
            $new = '';
            if (isset($idMap[$ch])) {
              $new = $idMap[$ch];
            } else {
              $k = sync_norm($ch);
              if ($k !== '' && isset($refMap[$k])) $new = $refMap[$k];
            }
            if ($new !== '') $node->setAttribute('channel', $new);
          }
          $xw->writeRaw($dom->saveXML($node));
        }
      }
      continue;
    }

    // Any other element: copy as-is (advances reader).
    $xw->writeRaw($xr->readOuterXML());
  }

  $xw->endElement();
  $xw->endDocument();
  $xr->close();

  return $outPath;
}

// ------------------- POST: run synchronizer -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  [$u1, $e1] = sync_read_upload('file1');
  [$u2, $e2] = sync_read_upload('file2');
  if ($e1 || $e2) {
    flash_set($e1 ?: $e2, 'error');
    header('Location: epg_sync.php');
    exit;
  }

  // XMLTV only (.xml or .gz/.xml.gz)
  $n1 = strtolower((string)($u1['name'] ?? ''));
  $n2 = strtolower((string)($u2['name'] ?? ''));
  $ok1 = (substr($n1, -4) === '.xml') || (substr($n1, -3) === '.gz');
  $ok2 = (substr($n2, -4) === '.xml') || (substr($n2, -3) === '.gz');
  if (!$ok1 || !$ok2) {
    flash_set('XMLTV only: upload .xml or .xml.gz files.', 'error');
    header('Location: epg_sync.php');
    exit;
  }

  $overwriteName = isset($_POST['overwrite_names']) && (string)$_POST['overwrite_names'] === '1';

  try {
    $cleanup = [];
    [$refPath, $c1, $err] = sync_xml_path($u1);
    if ($err) throw new RuntimeException($err);
    $cleanup = array_merge($cleanup, $c1);

    [$tarPath, $c2, $err2] = sync_xml_path($u2);
    if ($err2) throw new RuntimeException($err2);
    $cleanup = array_merge($cleanup, $c2);

    $refMap = xmltv_build_ref_map($refPath);
    $idMap  = xmltv_build_target_id_map($tarPath, $refMap);
    $out    = xmltv_write_synced($tarPath, $refMap, $idMap, $overwriteName);

    sync_clean_output_buffers();
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="synced_' . basename($u2['name']) . '"');
    readfile($out);

    @unlink($out);
    foreach ($cleanup as $pp) @unlink($pp);
    exit;
  } catch (Throwable $e) {
    flash_set($e->getMessage(), 'error');
    header('Location: epg_sync.php');
    exit;
  }
}
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>EPG XML Synchronizer</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>EPG XML Synchronizer</h2>
  <p class="muted">Upload a <b>reference XMLTV</b> and a <b>target XMLTV</b>. This tool aligns channel IDs by matching channel <b>display-name</b>. Download will be the modified target.</p>
  <?php flash_show(); ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_input() ?>

    <div class="form-row">
      <div>
        <label style="display:block">Options</label>
        <label class="muted" style="font-weight:700; display:flex; gap:8px; align-items:center">
          <input type="checkbox" name="overwrite_names" value="1">
          Keep only one &lt;display-name&gt; per channel (cleanup)
        </label>
      </div>
    </div>

    <div class="form-row" style="margin-top:10px">
      <div>
        <label>Reference XMLTV (.xml or .xml.gz)</label>
        <input type="file" name="file1" accept=".xml,.gz" required>
      </div>
      <div>
        <label>Target XMLTV (will be modified)</label>
        <input type="file" name="file2" accept=".xml,.gz" required>
      </div>
    </div>

    <div class="row" style="margin-top:12px">
      <button type="submit">Align IDs &amp; Download</button>
    </div>
  </form>
</div>

<div class="card" style="margin-top:14px">
  <div class="card-title">How it matches</div>
  <div class="muted">
    <b>EPG:</b> matches by the first channel &lt;display-name&gt;, then rewrites channel ids and programme channel attributes.
  </div>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
