<?php
declare(strict_types=1);

require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

require_admin();

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

const IPTV_M3U_MATCH_MAX_BYTES = 52428800; // 50MB per upload
const IPTV_M3U_MATCH_TOKEN_TTL = 7200; // 2 hours

function m3u_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function m3u_lower(string $s): string { return function_exists('mb_strtolower') ? (string)mb_strtolower($s, 'UTF-8') : strtolower($s); }

function m3u_normalize_name(string $s): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $s = m3u_lower($s);
  $s = str_replace(['&'], [' and '], $s);
  $s = preg_replace('~\b(uhd|fhd|hd|sd|4k|1080p|720p|2160p)\b~u', ' ', $s) ?? $s;
  $s = preg_replace('~[\(\[\{].*?[\)\]\}]~u', ' ', $s) ?? $s;
  $s = preg_replace('~[^a-z0-9]+~u', ' ', $s) ?? $s;
  $s = preg_replace('~\s+~u', ' ', $s) ?? $s;
  return trim($s);
}

function m3u_split_extinf(string $line): array {
  $inQuote = null;
  $len = strlen($line);
  for ($i = 0; $i < $len; $i++) {
    $ch = $line[$i];
    if ($ch === '"' || $ch === "'") {
      if ($inQuote === null) $inQuote = $ch;
      else if ($inQuote === $ch) $inQuote = null;
    } else if ($ch === ',' && $inQuote === null) {
      return [substr($line, 0, $i), substr($line, $i + 1)];
    }
  }
  return [$line, ''];
}

function m3u_parse_extinf_line(string $line): array {
  $line = rtrim($line, "\r\n");
  if (!str_starts_with($line, '#EXTINF:')) {
    return ['duration' => '-1', 'attrs' => [], 'title' => '', 'raw' => $line];
  }

  [$beforeComma, $title] = m3u_split_extinf($line);
  $title = trim($title);

  $payload = substr($beforeComma, strlen('#EXTINF:'));
  $payload = ltrim($payload);

  $duration = '-1';
  $rest = $payload;

  if (preg_match('~^([\-0-9]+)\s*(.*)$~', $payload, $m)) {
    $duration = $m[1];
    $rest = $m[2] ?? '';
  }

  $attrs = [];
  if (preg_match_all('~([A-Za-z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))~', $rest, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $hit) {
      $k = strtolower($hit[1]);
      $v = $hit[2] !== '' ? $hit[2] : ($hit[3] !== '' ? $hit[3] : ($hit[4] ?? ''));
      $attrs[$k] = $v;
    }
  }

  return ['duration' => $duration, 'attrs' => $attrs, 'title' => $title, 'raw' => $line];
}

function m3u_build_extinf_line(string $duration, array $attrs, string $title): string {
  $ordered = [];
  foreach (['tvg-id', 'tvg-name'] as $k) {
    $lk = strtolower($k);
    if (isset($attrs[$lk]) && $attrs[$lk] !== '') {
      $ordered[$lk] = $attrs[$lk];
    }
  }
  foreach ($attrs as $k => $v) {
    if ($v === '' || $v === null) continue;
    if (!isset($ordered[$k])) $ordered[$k] = $v;
  }

  $parts = [];
  foreach ($ordered as $k => $v) {
    $v = str_replace('"', '\"', (string)$v);
    $parts[] = $k . '="' . $v . '"';
  }

  $attrStr = $parts ? ' ' . implode(' ', $parts) : '';
  return '#EXTINF:' . $duration . $attrStr . ',' . $title;
}

function m3u_to_lines(string $text): array {
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  return explode("\n", $text);
}

function m3u_read_upload(string $field): string {
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
    throw new RuntimeException("Missing upload: {$field}");
  }
  $f = $_FILES[$field];

  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    throw new RuntimeException("Upload error for {$field} (code " . (int)$f['error'] . ")");
  }
  if (!is_uploaded_file($f['tmp_name'] ?? '')) {
    throw new RuntimeException("Upload not valid for {$field}");
  }
  if (($f['size'] ?? 0) > IPTV_M3U_MATCH_MAX_BYTES) {
    throw new RuntimeException("File too large for {$field} (max 50MB).");
  }
  $txt = file_get_contents($f['tmp_name']);
  if ($txt === false) throw new RuntimeException("Failed reading {$field}");
  return $txt;
}

function m3u_parse_reference_entries(string $text): array {
  $lines = m3u_to_lines($text);
  $n = count($lines);
  $entries = [];

  for ($i = 0; $i < $n; ) {
    $line = rtrim($lines[$i], "\r\n");
    if ($line === '') { $i++; continue; }

    if (str_starts_with($line, '#EXTINF:')) {
      $info = m3u_parse_extinf_line($line);

      $extras = [];
      $url = '';
      $tail = [];
      $j = $i + 1;

      while ($j < $n && !str_starts_with(rtrim($lines[$j], "\r\n"), '#EXTINF:')) {
        $l = rtrim($lines[$j], "\r\n");
        $t = trim($l);
        $j++;

        if ($t === '') continue;

        if ($url === '' && !str_starts_with($t, '#')) { $url = $t; continue; }
        if ($url === '' && str_starts_with($t, '#')) $extras[] = $l;
        else $tail[] = $l;
      }

      $entries[] = [
        'duration' => $info['duration'],
        'attrs' => $info['attrs'],
        'title' => $info['title'],
        'extras' => $extras,
        'url' => $url,
        'tail' => $tail,
      ];

      $i = $j;
      continue;
    }

    $i++;
  }

  return $entries;
}

function m3u_parse_target_parts(string $text): array {
  $lines = m3u_to_lines($text);
  $n = count($lines);
  $parts = [];

  for ($i = 0; $i < $n; ) {
    $line = rtrim($lines[$i], "\r\n");
    $i++;

    if ($line === '') continue;

    if (str_starts_with($line, '#EXTINF:')) {
      $info = m3u_parse_extinf_line($line);

      $extras = [];
      $url = '';
      $tail = [];

      while ($i < $n && !str_starts_with(rtrim($lines[$i], "\r\n"), '#EXTINF:')) {
        $l = rtrim($lines[$i], "\r\n");
        $t = trim($l);
        $i++;

        if ($t === '') continue;

        if ($url === '' && !str_starts_with($t, '#')) { $url = $t; continue; }
        if ($url === '' && str_starts_with($t, '#')) $extras[] = $l;
        else $tail[] = $l;
      }

      $parts[] = [
        'type' => 'entry',
        'duration' => $info['duration'],
        'attrs' => $info['attrs'],
        'title' => $info['title'],
        'extras' => $extras,
        'url' => $url,
        'tail' => $tail,
      ];
    } else {
      $parts[] = ['type' => 'line', 'line' => $line];
    }
  }

  return $parts;
}

function m3u_build_lookup(array $refEntries): array {
  $byId = [];
  $byNorm = [];

  foreach ($refEntries as $e) {
    $attrs = $e['attrs'];
    $id = trim((string)($attrs['tvg-id'] ?? ''));
    $name = trim((string)($attrs['tvg-name'] ?? ''));
    $title = trim((string)($e['title'] ?? ''));

    $bestName = $name !== '' ? $name : $title;

    if ($id !== '') {
      $k = m3u_lower($id);
      if (!isset($byId[$k])) $byId[$k] = ['tvg-id' => $id, 'tvg-name' => $bestName];
    }

    $norm = m3u_normalize_name($bestName);
    if ($norm !== '' && !isset($byNorm[$norm])) $byNorm[$norm] = ['tvg-id' => $id, 'tvg-name' => $bestName];
  }

  return [$byId, $byNorm];
}

function m3u_find_meta(array $entry, array $byId, array $byNorm): ?array {
  $attrs = $entry['attrs'] ?? [];
  $id = trim((string)($attrs['tvg-id'] ?? ''));
  if ($id !== '') {
    $k = m3u_lower($id);
    if (isset($byId[$k])) return $byId[$k];
  }

  $name = trim((string)($attrs['tvg-name'] ?? ''));
  $title = trim((string)($entry['title'] ?? ''));
  $candidate = $name !== '' ? $name : $title;
  $norm = m3u_normalize_name($candidate);
  if ($norm !== '' && isset($byNorm[$norm])) return $byNorm[$norm];

  if ($name !== '' && $title !== '') {
    $norm2 = m3u_normalize_name($title);
    if ($norm2 !== '' && isset($byNorm[$norm2])) return $byNorm[$norm2];
  }

  return null;
}

function m3u_token_path(string $token): string {
  $safe = preg_replace('~[^a-zA-Z0-9_-]~', '', $token);
  return rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'iptv_m3u_match_' . $safe . '.m3u';
}

function m3u_expire_old_tokens(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['m3u_match_tokens']) || !is_array($_SESSION['m3u_match_tokens'])) return;

  foreach ($_SESSION['m3u_match_tokens'] as $tk => $ts) {
    if (!is_int($ts) || (time() - $ts) > IPTV_M3U_MATCH_TOKEN_TTL) {
      unset($_SESSION['m3u_match_tokens'][$tk]);
      $p = m3u_token_path((string)$tk);
      if (is_file($p)) @unlink($p);
    }
  }
}

/* ---------------- Download handler ---------------- */
if (isset($_GET['dl']) && is_string($_GET['dl'])) {
  $token = $_GET['dl'];
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  m3u_expire_old_tokens();

  if (empty($_SESSION['m3u_match_tokens'][$token])) {
    http_response_code(404);
    echo "Invalid token.";
    exit;
  }

  $path = m3u_token_path($token);
  if (!is_file($path)) {
    http_response_code(404);
    echo "File expired.";
    exit;
  }

  header('Content-Type: application/x-mpegURL; charset=utf-8');
  header('Content-Disposition: attachment; filename="matched.m3u"');
  header('X-Content-Type-Options: nosniff');
  readfile($path);
  exit;
}

/* ---------------- Processing ---------------- */
$result = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_validate();

  try {
    $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';
    $fillName  = isset($_POST['fillName']) && $_POST['fillName'] === '1';

    $refText = m3u_read_upload('ref_m3u');
    $targetText = m3u_read_upload('target_m3u');

    $refEntries = m3u_parse_reference_entries($refText);
    [$byId, $byNorm] = m3u_build_lookup($refEntries);

    $parts = m3u_parse_target_parts($targetText);

    $matched = 0;
    $unmatched = 0;
    $unmatchedTitles = [];

    foreach ($parts as &$p) {
      if (($p['type'] ?? '') !== 'entry') continue;

      $meta = m3u_find_meta($p, $byId, $byNorm);
      if ($meta === null) {
        $unmatched++;
        $t = trim((string)($p['title'] ?? ''));
        if ($t !== '') $unmatchedTitles[] = $t;
        continue;
      }

      $attrs = $p['attrs'] ?? [];

      $metaId = (string)($meta['tvg-id'] ?? '');
      $metaName = (string)($meta['tvg-name'] ?? '');

      $curId = trim((string)($attrs['tvg-id'] ?? ''));
      $curName = trim((string)($attrs['tvg-name'] ?? ''));

      if ($overwrite || $curId === '') {
        if ($metaId !== '') $attrs['tvg-id'] = $metaId;
      }
      if ($overwrite || $curName === '') {
        if ($metaName !== '') $attrs['tvg-name'] = $metaName;
      }

      if ($fillName && trim((string)($attrs['tvg-name'] ?? '')) === '') {
        $title = trim((string)($p['title'] ?? ''));
        if ($title !== '') $attrs['tvg-name'] = $title;
      }

      $p['attrs'] = $attrs;
      $matched++;
    }
    unset($p);

    $outLines = [];
    foreach ($parts as $p) {
      if (($p['type'] ?? '') === 'line') {
        $outLines[] = $p['line'];
        continue;
      }

      $outLines[] = m3u_build_extinf_line((string)$p['duration'], (array)$p['attrs'], (string)$p['title']);
      foreach ((array)$p['extras'] as $x) $outLines[] = $x;
      if (!empty($p['url'])) $outLines[] = (string)$p['url'];
      foreach ((array)$p['tail'] as $t) $outLines[] = $t;
    }
    $out = implode("\n", $outLines) . "\n";

    // store on disk + token in session
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['m3u_match_tokens'] ??= [];

    $token = bin2hex(random_bytes(16));
    $_SESSION['m3u_match_tokens'][$token] = time();

    m3u_expire_old_tokens();

    $path = m3u_token_path($token);
    file_put_contents($path, $out);

    $result = [
      'token' => $token,
      'refCount' => count($refEntries),
      'matched' => $matched,
      'unmatched' => $unmatched,
      'unmatchedTitles' => $unmatchedTitles,
    ];
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

?><!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>M3U TVG Matcher</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    .badge{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-weight:900;border:1px solid var(--line);background:#fff;}
    .badge.ok{border-color:rgba(34,197,94,.25);background:rgba(34,197,94,.10);}
    .badge.bad{border-color:rgba(239,68,68,.25);background:rgba(239,68,68,.10);}
    .mono{font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;}
    .small{font-size:12px;color:var(--muted);}
    .helpbox{background:var(--bg-soft);border:1px dashed rgba(148,163,184,.55);padding:12px;border-radius:14px;margin-top:10px;}
    .two{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    @media (max-width:900px){.two{grid-template-columns:1fr;}}
  </style>
</head>
<body>
<?= $topbar ?>

<!-- container is opened by topbar.html -->
  <div class="card">
    <h2>M3U TVG Matcher</h2>
    <p class="muted" style="margin-top:-6px; margin-bottom:12px;">
      Upload two playlists. This will take <span class="mono">tvg-id</span> + <span class="mono">tvg-name</span> from the first M3U and inject them into the second.
    </p>

    <?php flash_show(); ?>

    <?php if ($error): ?>
      <div class="callout callout-bad" style="margin-top:12px;">
        <b>ERROR:</b> <?= m3u_h($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($result): ?>
      <div class="callout callout-ok" style="margin-top:12px;">
        <div class="row" style="justify-content:space-between;">
          <div>
            <span class="badge ok">Reference entries: <?= (int)$result['refCount'] ?></span>
            <span class="badge ok">Matched: <?= (int)$result['matched'] ?></span>
            <span class="badge <?= ((int)$result['unmatched'] > 0 ? 'bad' : 'ok') ?>">Unmatched: <?= (int)$result['unmatched'] ?></span>
          </div>
          <div>
            <a class="btn" href="m3u_tvg_matcher.php?dl=<?= m3u_h((string)$result['token']) ?>">Download matched.m3u</a>
          </div>
        </div>

        <?php if (!empty($result['unmatchedTitles'])): ?>
          <details style="margin-top:12px;">
            <summary><b>Show some unmatched channel titles</b> <span class="small">(first 60)</span></summary>
            <ul>
              <?php
                $max = 60;
                $shown = 0;
                foreach ($result['unmatchedTitles'] as $t) {
                  $shown++;
                  if ($shown > $max) break;
                  echo '<li>' . m3u_h((string)$t) . '</li>';
                }
                if (count($result['unmatchedTitles']) > $max) {
                  echo '<li class="small">…and ' . (count($result['unmatchedTitles']) - $max) . ' more</li>';
                }
              ?>
            </ul>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <h3 style="margin:16px 0 6px;">Upload + Match</h3>

    <div class="helpbox">
      <div><b>Matching order:</b> target <span class="mono">tvg-id</span> → normalized <span class="mono">tvg-name</span> → normalized display title.</div>
      <div class="small" style="margin-top:6px;">
        Pro tip: if your target list uses different naming, run your M3U Editor to standardize names first, then match.
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" style="margin-top:12px;">
      <?= csrf_input() ?>

      <div class="two">
        <div>
          <label>Reference M3U (has tvg-id / tvg-name)</label>
          <input type="file" name="ref_m3u" accept=".m3u,.m3u8,text/plain" required>
          <div class="small" style="margin-top:6px;">Max 50MB.</div>
        </div>
        <div>
          <label>Target M3U (streams, missing metadata)</label>
          <input type="file" name="target_m3u" accept=".m3u,.m3u8,text/plain" required>
          <div class="small" style="margin-top:6px;">Max 50MB.</div>
        </div>
      </div>

      <div class="row" style="margin-top:14px; gap:14px;">
        <label class="badge" style="cursor:pointer;">
          <input type="checkbox" name="overwrite" value="1" checked style="transform: translateY(1px);">
          Overwrite existing tvg-id / tvg-name in target
        </label>

        <label class="badge" style="cursor:pointer;">
          <input type="checkbox" name="fillName" value="1" checked style="transform: translateY(1px);">
          If tvg-name still empty, fill from target title
        </label>
      </div>

      <div style="margin-top:14px;">
        <button class="btn" type="submit">Match + Build Output</button>
      </div>
    </form>
  </div>
</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>