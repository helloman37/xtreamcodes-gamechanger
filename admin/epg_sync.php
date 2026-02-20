<?php
// admin/epg_sync.php
// Upload two M3U files and sync tvg-id + tvg-name from SOURCE to DEST by matching channel display names.

declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

require_admin();

@set_time_limit(0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '600');

$topbar = @file_get_contents(__DIR__ . '/topbar.html') ?: '';
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

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

function looks_like_m3u(string $path): bool {
  $fh = @fopen($path, 'rb');
  if (!$fh) return false;
  $head = (string)@fread($fh, 65536);
  @fclose($fh);

  $h = strtolower($head);
  // allow either marker
  if (strpos($h, '#extm3u') !== false) return true;
  if (strpos($h, '#extinf') !== false) return true;

  return false;
}

function m3u_lower(string $s): string {
  return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function m3u_strip_region_tags(string $s): string {
  $whitelist = '(?:us|usa|u\.s\.a|u\.s|united\s+states|uk|u\.k|united\s+kingdom|ca|canada|au|australia)';
  $s = preg_replace('~^\s*\(?\s*' . $whitelist . '\s*\)?\s*(?:\||:|-|–|—|>|»)+\s*~iu', '', $s) ?? $s;
  $s = preg_replace('~\s*(?:\||:|-|–|—|>|«)+\s*\(?\s*' . $whitelist . '\s*\)?\s*$~iu', '', $s) ?? $s;
  return $s;
}

function m3u_norm_title(string $s): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $s = m3u_lower($s);

  $s = m3u_strip_region_tags($s);

  $s = preg_replace('~\b(uhd|fhd|hd|sd|4k|2160p|1080p|720p)\b~u', ' ', $s) ?? $s;
  $s = preg_replace('~\b(east|west|eastern|pacific|central|mountain|atlantic|est|pst|cst|mst|et|pt|ct|mt)\b~u', ' ', $s) ?? $s;

  $s = str_replace('&', ' and ', $s);
  $s = preg_replace('~[\(\[\{].*?[\)\]\}]~u', ' ', $s) ?? $s;
  $s = preg_replace('~[^a-z0-9]+~u', ' ', $s) ?? $s;
  $s = preg_replace('~\b(us|usa)\b~u', ' ', $s) ?? $s;
  $s = preg_replace('~\s+~u', ' ', $s) ?? $s;

  return trim($s);
}

function m3u_get_title(string $extinfLine): string {
  $pos = strpos($extinfLine, ',');
  if ($pos === false) return '';
  return trim(substr($extinfLine, $pos + 1));
}

function m3u_get_attr(string $extinfLine, string $key): string {
  if (preg_match('~\b' . preg_quote($key, '~') . '="([^"]*)"~u', $extinfLine, $m)) {
    return $m[1];
  }
  return '';
}

function m3u_set_attr(string $extinfLine, string $key, string $value): string {
  $value = str_replace('"', '', $value);
  $quoted = $key . '="' . $value . '"';

  if (preg_match('~\b' . preg_quote($key, '~') . '="[^"]*"~u', $extinfLine)) {
    return preg_replace('~\b' . preg_quote($key, '~') . '="[^"]*"~u', $quoted, $extinfLine, 1) ?? $extinfLine;
  }

  if ($key === 'tvg-name' && preg_match('~\btvg-id="[^"]*"~u', $extinfLine, $m, PREG_OFFSET_CAPTURE)) {
    $posEnd = $m[0][1] + strlen($m[0][0]);
    return substr($extinfLine, 0, $posEnd) . ' ' . $quoted . substr($extinfLine, $posEnd);
  }

  if (preg_match('~^#EXTINF:-1\b~u', $extinfLine, $m, PREG_OFFSET_CAPTURE)) {
    $posEnd = strlen($m[0][0]);
    return substr($extinfLine, 0, $posEnd) . ' ' . $quoted . substr($extinfLine, $posEnd);
  }

  return $extinfLine . ' ' . $quoted;
}

function build_source_index(string $srcPath): array {
  $index = [];

  $fh = @fopen($srcPath, 'rb');
  if (!$fh) throw new RuntimeException('Failed to open source M3U.');

  while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if (strncmp($line, '#EXTINF', 7) !== 0) continue;

    $title   = m3u_get_title($line);
    $tvgId   = m3u_get_attr($line, 'tvg-id');
    $tvgName = m3u_get_attr($line, 'tvg-name');

    $bestName = $tvgName !== '' ? $tvgName : $title;

    $keys = [];
    if ($title !== '')   $keys[] = m3u_norm_title($title);
    if ($tvgName !== '') $keys[] = m3u_norm_title($tvgName);

    foreach ($keys as $k) {
      if ($k === '') continue;
      if (!isset($index[$k])) {
        $index[$k] = ['tvg-id' => $tvgId, 'tvg-name' => $bestName];
      }
    }
  }

  fclose($fh);
  return $index;
}

function sync_clean_output_buffers(): void {
  while (ob_get_level() > 0) { @ob_end_clean(); }
}

// ------------------- POST: run synchronizer -------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  [$u1, $e1] = sync_read_upload('source');
  [$u2, $e2] = sync_read_upload('dest');

  if ($e1 || $e2) {
    flash_set($e1 ?: $e2, 'error');
    header('Location: epg_sync.php');
    exit;
  }

  // CONTENT-BASED validation (no extension bullshit)
  if (!looks_like_m3u($u1['tmp'])) {
    flash_set('Source does not look like an M3U (missing #EXTM3U/#EXTINF).', 'error');
    header('Location: epg_sync.php');
    exit;
  }
  if (!looks_like_m3u($u2['tmp'])) {
    flash_set('Destination does not look like an M3U (missing #EXTM3U/#EXTINF).', 'error');
    header('Location: epg_sync.php');
    exit;
  }

  try {
    $srcIndex = build_source_index($u1['tmp']);

    $outPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'epg_sync_' . bin2hex(random_bytes(8)) . '.m3u';
    $in  = @fopen($u2['tmp'], 'rb');
    $out = @fopen($outPath, 'wb');
    if (!$in || !$out) throw new RuntimeException('Failed to open target/output stream.');

    $matched = 0;
    $total = 0;

    while (($line = fgets($in)) !== false) {
      $raw = rtrim($line, "\r\n");

      if (strncmp($raw, '#EXTINF', 7) === 0) {
        $total++;
        $title = m3u_get_title($raw);
        $key = m3u_norm_title($title);

        if ($key !== '' && isset($srcIndex[$key])) {
          $matched++;
          $raw = m3u_set_attr($raw, 'tvg-id', $srcIndex[$key]['tvg-id']);
          $raw = m3u_set_attr($raw, 'tvg-name', $srcIndex[$key]['tvg-name']);
        }
      }

      fwrite($out, $raw . "\n");
    }

    fclose($in);
    fclose($out);

    $downloadName = 'synced_' . preg_replace('~[^a-zA-Z0-9._-]+~', '_', (string)$u2['name']);
    if ($downloadName === 'synced_') $downloadName = 'synced_playlist.m3u';

    sync_clean_output_buffers();
    header('Content-Type: application/x-mpegURL');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('X-Matched: ' . (string)$matched);
    header('X-Total: ' . (string)$total);

    readfile($outPath);
    @unlink($outPath);
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
  <meta charset="utf-8">
  <title>EPG Sync</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>EPG Sync (tvg-id + tvg-name)</h2>
  <p class="muted">
    Upload a <b>source</b> M3U (good tvg-id/tvg-name) and a <b>destination</b> M3U.
    It matches by channel <b>display name</b> (after the comma) and copies <b>tvg-id + tvg-name</b> into the destination.
  </p>
  <?php flash_show(); ?>

  <form method="post" enctype="multipart/form-data">
    <?= csrf_input() ?>

    <div class="form-row">
      <div>
        <label>Source M3U</label>
        <input type="file" name="source" required>
      </div>
      <div>
        <label>Destination M3U (will be modified)</label>
        <input type="file" name="dest" required>
      </div>
    </div>

    <div class="row" style="margin-top:12px">
      <button type="submit">Sync &amp; Download</button>
    </div>
  </form>
</div>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
