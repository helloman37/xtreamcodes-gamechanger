<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

require_admin();

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

$error = '';
$sources_raw = '';

// ------------------------------------------------------------
//  SINGLE FILE: LOGO FETCHER + M3U LOGO AUTO-MAPPER (PATCHED)
//  PHP 8+
// ------------------------------------------------------------

function normalize($name) {
    $name = strtolower($name);
    $name = preg_replace('/\.(png|jpg|jpeg|svg|webp)$/', '', $name);
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
    $name = preg_replace('/\b(hd|fhd|sd|uhd|4k|us|uk|ca|au)\b/', '', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

function curlGet($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => "Mozilla/5.0",
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    return $out;
}

function fetchLogosFromSource($url) {
    $logos = [];

    // ------------------------------------------------------------
    // 1. Detect GitHub folder URL and convert to API
    // ------------------------------------------------------------
    if (preg_match('#https://github\.com/([^/]+)/([^/]+)/tree/([^/]+)/(.+)#', $url, $m)) {

        $api = "https://api.github.com/repos/{$m[1]}/{$m[2]}/contents/{$m[4]}";

        $raw = curlGet($api);
        $json = json_decode($raw, true);

        // SAFETY: ensure valid JSON array
        if (!is_array($json)) {
            return $logos;
        }

        foreach ($json as $file) {
            if (!is_array($file)) continue;
            if (($file['type'] ?? '') !== 'file') continue;

            $norm = normalize($file['name']);
            if ($norm !== "") {
                $logos[$norm] = $file['download_url'];
            }
        }

        return $logos;
    }

    // ------------------------------------------------------------
    // 2. Treat as raw folder / directory listing
    // ------------------------------------------------------------
    $html = @curlGet($url);

    if ($html !== false && strlen($html) > 0) {
        preg_match_all('/href="([^"]+\.(png|jpg|jpeg|svg|webp))"/i', $html, $matches);

        foreach ($matches[1] as $file) {
            $filename = basename($file);
            $norm = normalize($filename);
            if ($norm !== "") {
                $logos[$norm] = rtrim($url, '/') . '/' . $filename;
            }
        }
    }

    return $logos;
}

function matchLogo($channelName, $logos) {
    $norm = normalize($channelName);

    // Exact match
    if (isset($logos[$norm])) {
        return $logos[$norm];
    }

    // Fuzzy match
    $best = null;
    $bestScore = 0;

    foreach ($logos as $logoName => $url) {
        similar_text($norm, $logoName, $percent);
        if ($percent > $bestScore) {
            $bestScore = $percent;
            $best = $url;
        }
    }

    return $bestScore >= 70 ? $best : null;
}

function rewriteM3U($m3u, $logos) {
    $out = "";
    $lines = explode("\n", $m3u);

    foreach ($lines as $line) {

        if (str_starts_with(trim($line), "#EXTINF")) {

            // Extract channel name
            if (preg_match('/,(.*)$/', $line, $m)) {
                $channelName = trim($m[1]);
                $logo = matchLogo($channelName, $logos);

                if ($logo) {
                    // Remove existing tvg-logo
                    $line = preg_replace('/tvg-logo="[^"]*"/', '', $line);

                    // Insert new tvg-logo
                    $line = preg_replace(
                        '/#EXTINF:-1\s*/',
                        '#EXTINF:-1 tvg-logo="' . $logo . '" ',
                        $line
                    );
                }
            }
        }

        $out .= $line . "\n";
    }

    return $out;
}

// ------------------------------------------------------------
//  FORM HANDLER
// ------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_validate();

    $sources_raw = (string)($_POST['sources'] ?? '');
    $sources = preg_split('/\r\n|\r|\n/', trim($sources_raw));
    if (!is_array($sources)) $sources = [];

    $allLogos = [];
    foreach ($sources as $src) {
        $src = trim((string)$src);
        if ($src !== '') {
            $logos = fetchLogosFromSource($src);
            if (!empty($logos)) {
                $allLogos = array_merge($allLogos, $logos);
            }
        }
    }

    $tmp = $_FILES['m3u']['tmp_name'] ?? '';
    if (!$tmp || !is_file($tmp)) {
        $error = 'No M3U file uploaded.';
    } else {
        $m3u = file_get_contents($tmp);
        if ($m3u === false || $m3u === '') {
            $error = 'Failed to read uploaded M3U.';
        } else {
            $new = rewriteM3U($m3u, $allLogos);
            header('Content-Type: audio/x-mpegurl');
            header('Content-Disposition: attachment; filename="rewritten.m3u"');
            header('Content-Length: ' . strlen($new));
            echo $new;
            exit;
        }
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
  <title>M3U Logo Mapper</title>
  <link rel="stylesheet" href="panel.css">
  <style>
    .hint{font-size:12px;color:var(--muted);margin-top:6px}
  </style>
</head>
<body>
<?= $topbar ?>

  <div class="card">
    <div class="card-title">Content • M3U Logo Mapper</div>
    <h2 style="margin:0 0 10px;">M3U Logo Mapper</h2>

    <?php if ($error): ?>
      <div class="notice notice-warn" style="margin-bottom:12px;">
        <div class="notice-icon"><?= gc_svg_icon('alert') ?></div>
        <div class="notice-body"><div class="notice-title">Error</div><div class="notice-text"><?= e($error) ?></div></div>
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_input() ?>

      <label for="sources">Logo Source URLs (one per line)</label>
      <textarea id="sources" name="sources" rows="6" placeholder="https://github.com/user/repo/tree/main/logos"><?= e($sources_raw) ?></textarea>
      <div class="hint">Paste one or more logo folders. GitHub <code>.../tree/branch/path</code> is supported.</div>

      <div style="height:10px"></div>

      <label for="m3u">Upload M3U Playlist</label>
      <input id="m3u" type="file" name="m3u" accept=".m3u,.txt">

      <div style="height:12px"></div>

      <button type="submit">Process &amp; Download New M3U</button>
    </form>
  </div>

</div><!-- .container from topbar -->
</main></div><!-- .app from topbar -->

</body>
</html>