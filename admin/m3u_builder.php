<?php
// M3U Builder (auto-detect playlist type/output + expiry embed)
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

set_time_limit(60);

function gc_ad_fetch_head(string $url, int $timeout = 10): array
{
  // returns [http_code, content_type, body_prefix]
  $http = 0;
  $ctype = '';
  $body = null;

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_USERAGENT => 'GameChanger-AutoDetect/1.1',
      CURLOPT_HTTPHEADER => ['Range: bytes=0-4096'],
      CURLOPT_HEADER => false,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
  } else {
    $ctx = stream_context_create([
      'http' => [
        'method' => 'GET',
        'timeout' => $timeout,
        'header' => "User-Agent: GameChanger-AutoDetect/1.1\r\nRange: bytes=0-4096\r\n",
      ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $http = $body === false ? 0 : 200;
    $ctype = '';
  }

  if (!is_string($body)) $body = '';
  return [$http, $ctype, $body];
}

function gc_ad_candidates(string $prefix, string $username, string $password): array
{
  $base = rtrim($prefix, '/');
  $get = $base . '/get.php?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);

  // Xtream Codes "type" is usually m3u_plus or m3u. Some providers accept other values; harmless to try.
  $types = ['m3u_plus', 'm3u', 'playlist', 'm3u8'];

  // "output" controls the stream format. Providers vary; try the common ones + some legacy.
  $outputs = ['ts', 'mpegts', 'm3u8', 'hls', 'rtmp'];

  $urls = [];

  // Most common first (fastest success path)
  foreach (['m3u_plus', 'm3u'] as $t) {
    foreach (['ts', 'mpegts', 'm3u8'] as $o) {
      $urls[] = $get . '&type=' . $t . '&output=' . $o;
    }
    $urls[] = $get . '&type=' . $t; // no output (server default)
  }

  // Extra fallbacks
  foreach ($types as $t) {
    foreach ($outputs as $o) {
      $urls[] = $get . '&type=' . $t . '&output=' . $o;
    }
    $urls[] = $get . '&type=' . $t;
  }

  // Some panels accept get.php with no type/output at all
  $urls[] = $get;

  // Unique while preserving order
  $seen = [];
  $out = [];
  foreach ($urls as $u) {
    if (!isset($seen[$u])) {
      $seen[$u] = true;
      $out[] = $u;
    }
  }
  return $out;
}

function gc_ad_detect(string $prefix, string $username, string $password): ?array
{
  foreach (gc_ad_candidates($prefix, $username, $password) as $url) {
    [$http, $ctype, $head] = gc_ad_fetch_head($url, 10);
    if ($http !== 200 && $http !== 206) continue;

    $trim = ltrim($head);
    if (strncmp($trim, '#EXTM3U', 7) === 0) {
      // parse params from url for display
      $q = [];
      parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
      return [
        'url' => $url,
        'type' => (string)($q['type'] ?? ''),
        'output' => (string)($q['output'] ?? ''),
      ];
    }
  }
  return null;
}

function gc_ad_get_expiry(string $prefix, string $username, string $password): ?string
{
  $base = rtrim($prefix, '/');
  $api = $base . '/player_api.php?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);
  $json = null;

  if (function_exists('curl_init')) {
    $ch = curl_init($api);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_USERAGENT => 'GameChanger-AutoDetect/1.1',
    ]);
    $json = curl_exec($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: GameChanger-AutoDetect/1.1\r\n"]]);
    $json = @file_get_contents($api, false, $ctx);
  }

  if (!is_string($json) || $json === '') return null;

  $data = json_decode($json, true);
  if (!is_array($data)) return null;

  $ui = $data['user_info'] ?? null;
  if (!is_array($ui)) return null;

  $keys = ['exp_date', 'expire_date', 'active_until', 'expires', 'expiration'];
  foreach ($keys as $k) {
    if (isset($ui[$k]) && (is_string($ui[$k]) || is_int($ui[$k]))) {
      $v = trim((string)$ui[$k]);
      if ($v === '' || $v === '0') continue;

      // If it's a unix timestamp, convert to ISO-8601 for readability
      if (ctype_digit($v)) {
        $ts = (int)$v;
        if ($ts > 1000000000) {
          return gmdate('c', $ts);
        }
      }
      return $v;
    }
  }
  return null;
}

function gc_ad_stream_m3u_with_expiry(string $url, ?string $expiryIso): void
{
  // No extra output before headers
  if (ob_get_level()) {
    while (ob_get_level()) {
      @ob_end_clean();
    }
  }

  header('Content-Type: audio/x-mpegurl; charset=utf-8');
  header('Content-Disposition: attachment; filename="playlist.m3u"');
  header('X-Content-Type-Options: nosniff');

  $expiryLine = $expiryIso ? "#EXTGC-EXPIRES: {$expiryIso}\n" : null;

  if (!function_exists('curl_init')) {
    // Fallback (less memory-safe, but works)
    $content = @file_get_contents($url);
    if (!is_string($content) || $content === '') {
      http_response_code(502);
      echo 'Failed to download playlist.';
      return;
    }
    if (strncmp(ltrim($content), '#EXTM3U', 7) !== 0) {
      http_response_code(502);
      echo 'Remote did not return an M3U playlist.';
      return;
    }

    // Insert expiry after first line
    $pos = strpos($content, "\n");
    if ($pos === false) {
      echo $content;
      if ($expiryLine) echo $expiryLine;
      return;
    }

    echo substr($content, 0, $pos + 1);
    if ($expiryLine) echo $expiryLine;
    echo substr($content, $pos + 1);
    return;
  }

  $sent = false;
  $buf = '';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 0, // allow large lists
    CURLOPT_USERAGENT => 'GameChanger-AutoDetect/1.1',
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$sent, &$buf, $expiryLine) {
      if ($sent) {
        echo $data;
        return strlen($data);
      }

      $buf .= $data;

      // wait until we have at least the first line
      $nl = strpos($buf, "\n");
      if ($nl === false) {
        // keep buffering
        return strlen($data);
      }

      $firstLine = substr($buf, 0, $nl + 1);
      if (strncmp(ltrim($firstLine), '#EXTM3U', 7) !== 0) {
        // Not an M3U — stop
        return 0;
      }

      echo $firstLine;
      if ($expiryLine) echo $expiryLine;

      $rest = substr($buf, $nl + 1);
      if ($rest !== '') echo $rest;

      $buf = '';
      $sent = true;
      return strlen($data);
    },
  ]);

  $ok = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($ok === false || ($code !== 200 && $code !== 206)) {
    if (!headers_sent()) http_response_code(502);
    echo "\n#ERROR: Download failed (HTTP {$code})\n";
  }
}

// Prefill fields
$prefix_ui = trim((string)($_POST['prefix'] ?? ''));
$user_ui = trim((string)($_POST['user'] ?? ''));
$pass_ui = trim((string)($_POST['password'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $prefix = $prefix_ui;
  $userIn = $user_ui;
  $passIn = $pass_ui;

  if ($prefix === '' || $userIn === '') {
    flash_set('Missing URL prefix or username.', 'error');
  } else {
    // allow user:pass in username field
    $username = $userIn;
    $password = $passIn;
    if (strpos($userIn, ':') !== false && $passIn === '') {
      [$u, $p] = array_pad(explode(':', $userIn, 2), 2, '');
      $username = trim($u);
      $password = trim($p);
    }

    if ($username === '' || $password === '') {
      flash_set('Missing username or password.', 'error');
    } else {
      $det = gc_ad_detect($prefix, $username, $password);
      if (!$det) {
        flash_set('Could not detect a valid M3U endpoint. Check URL/credentials.', 'error');
      } else {
        $expiry = gc_ad_get_expiry($prefix, $username, $password);
        gc_ad_stream_m3u_with_expiry($det['url'], $expiry);
        exit;
      }
    }
  }
}

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>M3U Builder</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>M3U Builder</h2>
  <?php flash_show(); ?>

  <form method="post" autocomplete="off">
    <label>URL prefix</label>
    <input name="prefix" value="<?= e($prefix_ui) ?>" placeholder="http://example.com:8080" required>

    <div class="row" style="margin-top:10px;">
      <div style="flex:1; min-width:220px;">
        <label>Username (or username:password)</label>
        <input name="user" value="<?= e($user_ui) ?>" placeholder="demo or demo:1234" required>
      </div>
      <div style="flex:1; min-width:220px;">
        <label>Password (leave empty if using username:password)</label>
        <input name="password" value="<?= e($pass_ui) ?>" placeholder="">
      </div>
    </div>

    <button class="btn" type="submit" style="margin-top:12px;">Build &amp; Download</button>

    <p class="muted" style="margin-top:10px;">
      Auto-detects the best get.php playlist type/output and downloads <span class="code">playlist.m3u</span>. If available, it embeds expiry as <span class="code">#EXTGC-EXPIRES:</span>.
    </p>
  </form>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
