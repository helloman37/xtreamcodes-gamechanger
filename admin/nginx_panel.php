<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

set_time_limit(60);

$RAW_INPUT = file_get_contents('php://input') ?: '';

function get_input_value(string $key, string $raw): string
{
    // 1) Normal POST
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        return trim((string)$_POST[$key]);
    }

    // 2) Fallback: GET
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        return trim((string)$_GET[$key]);
    }

    // 3) Raw body: JSON
    $trim = ltrim($raw);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j[$key]) && $j[$key] !== '') {
            return trim((string)$j[$key]);
        }
    }

    // 4) Raw body: form-encoded
    if ($raw !== '') {
        $parsed = [];
        parse_str($raw, $parsed);
        if (isset($parsed[$key]) && $parsed[$key] !== '') {
            return trim((string)$parsed[$key]);
        }
    }

    return '';
}

/**
 * cURL GET helper (with HTTP status)
 * @return array{0:int,1:string}
 */
function http_get(string $url, array $params = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url . "?" . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => "Mozilla/5.0 PHP8-Grabber",
        CURLOPT_HEADER => false,
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL Error: " . $err);
    }

    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, (string)$body];
}

function safe_attr(string $s): string
{
    // M3U attributes are quoted; don't let quotes break the line
    return str_replace('"', "'", $s);
}

// Prefill fields (for UI)
$server_ui = trim((string)($_POST['server'] ?? $_GET['server'] ?? ''));
$username_ui = trim((string)($_POST['username'] ?? $_GET['username'] ?? ''));
$password_ui = trim((string)($_POST['password'] ?? $_GET['password'] ?? ''));

/* ------------------------------------------------------------
   PROCESS FORM
------------------------------------------------------------ */

$server   = get_input_value('server', $RAW_INPUT);
$username = get_input_value('username', $RAW_INPUT);
$password = get_input_value('password', $RAW_INPUT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' || ($server !== '' || $username !== '' || $password !== '')) {

    // Only attempt if any field was provided (avoid running on first load)
    if ($server !== '' || $username !== '' || $password !== '') {

        if ($server === '' || $username === '' || $password === '') {
            flash_set('Missing required fields (server, username, password).', 'error');
        } else {
            $server = rtrim($server, '/');
            $apiUrl = $server . '/player_api.php';

            // 1) Fetch categories so group-title is correct
            $catMap = [];
            try {
                [$codeCats, $catsJson] = http_get($apiUrl, [
                    'username' => $username,
                    'password' => $password,
                    'action'   => 'get_live_categories',
                ]);

                if ($codeCats === 200) {
                    $cats = json_decode($catsJson, true);
                    if (is_array($cats)) {
                        foreach ($cats as $c) {
                            if (isset($c['category_id'], $c['category_name'])) {
                                $catMap[(string)$c['category_id']] = (string)$c['category_name'];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Categories are optional; keep going
            }

            // 2) Fetch streams
            try {
                [$code, $json] = http_get($apiUrl, [
                    'username' => $username,
                    'password' => $password,
                    'action'   => 'get_live_streams',
                ]);

                if ($code !== 200) {
                    flash_set("Remote panel returned HTTP {$code} from player_api.php", 'error');
                } else {
                    $data = json_decode($json, true);
                    if (!is_array($data)) {
                        flash_set('Invalid JSON returned from remote panel.', 'error');
                    } else {
                        // 3) Build M3U
                        $m3u = "#EXTM3U\n";

                        foreach ($data as $ch) {
                            if (!isset($ch['stream_id'])) continue;

                            $id   = (string)$ch['stream_id'];
                            $name = (string)($ch['name'] ?? 'Unknown');
                            $logo = (string)($ch['stream_icon'] ?? '');

                            // Prefer category map; fallback to returned name; else Other
                            $catId = isset($ch['category_id']) ? (string)$ch['category_id'] : '';
                            $group = $catId !== '' && isset($catMap[$catId]) ? $catMap[$catId] : (string)($ch['category_name'] ?? 'Other');

                            // Prefer EPG channel id if the panel provides it, else stream_id
                            $tvgId = (string)($ch['epg_channel_id'] ?? $id);

                            $url = "{$server}/live/{$username}/{$password}/{$id}.m3u8";

                            $m3u .= '#EXTINF:-1 tvg-id="' . safe_attr($tvgId) .
                                '" tvg-name="' . safe_attr($name) .
                                '" tvg-logo="' . safe_attr($logo) .
                                '" group-title="' . safe_attr($group) . '",' . safe_attr($name) . "\n";
                            $m3u .= $url . "\n";
                        }

                        header('Content-Type: application/x-mpegURL; charset=utf-8');
                        header('Content-Disposition: attachment; filename=channels.m3u');
                        echo $m3u;
                        exit;
                    }
                }
            } catch (Throwable $e) {
                flash_set('Failed to fetch channels: ' . $e->getMessage(), 'error');
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
  <title>NGINX Panel</title>
  <link rel="stylesheet" href="panel.css">
</head>
<body>
<?= $topbar ?>

<div class="card">
  <h2>NGINX Panel</h2>
  <?php flash_show(); ?>

  <form method="post" autocomplete="off">
    <div class="row">
      <div>
        <label>Server URL</label>
        <input name="server" value="<?= e($server_ui) ?>" placeholder="http://ip:port" required>
      </div>
      <div>
        <label>Username</label>
        <input name="username" value="<?= e($username_ui) ?>" placeholder="Username" required>
      </div>
    </div>

    <label>Password</label>
    <input type="password" name="password" value="<?= e($password_ui) ?>" placeholder="Password" required>

    <button class="btn" type="submit">Download Playlist</button>

    <p class="muted" style="margin-top:10px;">
      This downloads a playlist file for the provided panel credentials.
    </p>
  </form>
</div>


</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
