<?php
declare(strict_types=1);

/*
 * NGINX/Xtream-style Panel → JSON Channels → M3U Export
 * Output format: http://server/live/username/password/stream_id.m3u8
 */

set_time_limit(60);

/* ------------------------------------------------------------
   UNIVERSAL INPUT FIX (POST/GET/raw JSON/form-encoded)
------------------------------------------------------------ */

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

/* ------------------------------------------------------------
   cURL GET helper (with HTTP status)
------------------------------------------------------------ */
function http_get(string $url, array $params = []): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url . "?" . http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => false, // keep as you had it
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

/* ------------------------------------------------------------
   PROCESS FORM (allow POST, but also tolerate GET/raw for weird hosts)
------------------------------------------------------------ */

$server   = get_input_value('server', $RAW_INPUT);
$username = get_input_value('username', $RAW_INPUT);
$password = get_input_value('password', $RAW_INPUT);

if ($server !== '' || $username !== '' || $password !== '') {

    if ($server === '' || $username === '' || $password === '') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR: Missing required fields.\n";
        echo "REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN') . "\n";
        echo "Have server?   " . ($server !== '' ? "yes" : "no") . "\n";
        echo "Have username? " . ($username !== '' ? "yes" : "no") . "\n";
        echo "Have password? " . ($password !== '' ? "yes" : "no") . " (masked)\n";
        exit;
    }

    $server = rtrim($server, '/');
    $apiUrl = $server . "/player_api.php";

    // 1) Fetch categories so group-title is correct
    $catMap = [];
    try {
        [$codeCats, $catsJson] = http_get($apiUrl, [
            "username" => $username,
            "password" => $password,
            "action"   => "get_live_categories",
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
    } catch (\Throwable $e) {
        // Categories are optional; keep going even if blocked
    }

    // 2) Fetch streams
    try {
        [$code, $json] = http_get($apiUrl, [
            "username" => $username,
            "password" => $password,
            "action"   => "get_live_streams",
        ]);
    } catch (Exception $e) {
        header('Content-Type: text/plain; charset=utf-8');
        die("Failed to fetch channels: " . $e->getMessage());
    }

    if ($code !== 200) {
        header('Content-Type: text/plain; charset=utf-8');
        die("Panel returned HTTP $code from player_api.php\n");
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        header('Content-Type: text/plain; charset=utf-8');
        die("Invalid JSON returned from panel.\n");
    }

    // 3) Build M3U
    $m3u = "#EXTM3U\n";

    foreach ($data as $ch) {
        if (!isset($ch["stream_id"])) continue;

        $id   = (string)$ch["stream_id"];
        $name = (string)($ch["name"] ?? "Unknown");
        $logo = (string)($ch["stream_icon"] ?? "");

        // Prefer category map; fallback to returned name; else Other
        $catId = isset($ch["category_id"]) ? (string)$ch["category_id"] : '';
        $group = $catId !== '' && isset($catMap[$catId]) ? $catMap[$catId] : (string)($ch["category_name"] ?? "Other");

        // Prefer EPG channel id if the panel provides it, else stream_id
        $tvgId = (string)($ch["epg_channel_id"] ?? $id);

        $url = "{$server}/live/{$username}/{$password}/{$id}.m3u8";

        $m3u .= '#EXTINF:-1 tvg-id="' . safe_attr($tvgId) .
               '" tvg-name="' . safe_attr($name) .
               '" tvg-logo="' . safe_attr($logo) .
               '" group-title="' . safe_attr($group) . '",' . safe_attr($name) . "\n";
        $m3u .= $url . "\n";
    }

    header("Content-Type: application/x-mpegURL; charset=utf-8");
    header("Content-Disposition: attachment; filename=channels.m3u");
    echo $m3u;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>NGINX Panel → M3U Export</title>
<style>
    body { background:#111; color:#eee; font-family:Arial; padding:40px; }
    form { max-width:400px; margin:auto; background:#222; padding:20px; border-radius:8px; }
    input { width:100%; padding:10px; margin:10px 0; border:0; border-radius:5px; }
    button { width:100%; padding:12px; background:#4CAF50; border:0; border-radius:5px; color:#fff; font-size:16px; cursor:pointer; }
    button:hover { background:#45a049; }
</style>
</head>
<body>

<h2>NGINX Panel → M3U Export</h2>

<form method="POST">
    <input type="text" name="server" placeholder="Server URL (http://ip:port)" required>
    <input type="text" name="username" placeholder="Username" required>
    <input type="password" name="password" placeholder="Password" required>
    <button type="submit">Download M3U</button>
</form>

</body>
</html>
