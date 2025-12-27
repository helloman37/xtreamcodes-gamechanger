<?php
// check_m3u.php
declare(strict_types=1);

date_default_timezone_set('America/Chicago');

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildBaseUrl(array $parts): string {
    $scheme = $parts['scheme'] ?? 'http';
    $host   = $parts['host'] ?? '';
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $scheme . '://' . $host . $port;
}

function curlFetch(string $url, array $opts = []): array {
    $ch = curl_init($url);
    $default = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'M3U-Validator/1.0'
    ];

    foreach ($default as $k => $v) {
        curl_setopt($ch, $k, $v);
    }
    foreach ($opts as $k => $v) {
        curl_setopt($ch, $k, $v);
    }

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    return [
        'ok'   => $err === '',
        'err'  => $err,
        'info' => $info,
        'body' => is_string($body) ? $body : ''
    ];
}

function looksLikeM3U(string $body): bool {
    $trim = ltrim($body);
    if ($trim === '') return false;
    // Most playlists start with #EXTM3U
    return stripos($trim, '#EXTM3U') === 0 || stripos($body, '#EXTINF') !== false;
}

function parsePossibleExpiryFromQuery(array $query): ?int {
    $keys = [
        'exp', 'expires', 'expiry', 'expire', 'valid', 'validuntil', 'valid_until', 'end', 'endtime'
    ];

    foreach ($keys as $k) {
        if (!isset($query[$k])) continue;
        $v = $query[$k];

        if (is_array($v)) $v = $v[0] ?? '';
        $v = trim((string)$v);
        if ($v === '') continue;

        // Numeric epoch detection (seconds or milliseconds)
        if (ctype_digit($v)) {
            $num = (int)$v;
            if ($num > 2000000000000) { // likely ms
                $num = (int)floor($num / 1000);
            }
            // sanity range: after 2000-01-01 and before 2100-01-01
            if ($num > 946684800 && $num < 4102444800) {
                return $num;
            }
        }

        // Try parsing date-like strings
        $ts = strtotime($v);
        if ($ts !== false) {
            return $ts;
        }
    }

    return null;
}

function detectXtreamApiUrl(string $m3uUrl, array $parts, array $query): ?string {
    $path = $parts['path'] ?? '';
    $username = $query['username'] ?? null;
    $password = $query['password'] ?? null;

    if (!$username || !$password) return null;

    $username = is_array($username) ? ($username[0] ?? '') : (string)$username;
    $password = is_array($password) ? ($password[0] ?? '') : (string)$password;

    if ($username === '' || $password === '') return null;

    $base = buildBaseUrl($parts);

    // If URL ends in get.php, swap to player_api.php in same directory
    if (preg_match('~get\.php$~i', $path)) {
        $apiPath = preg_replace('~get\.php$~i', 'player_api.php', $path);
    } else {
        // Common fallback location
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $apiPath = ($dir === '' || $dir === '.') ? '/player_api.php' : $dir . '/player_api.php';
    }

    $apiUrl = $base . $apiPath . '?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);

    return $apiUrl;
}

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['m3u_url'] ?? '');

    $result = [
        'input' => $url,
        'is_url' => false,
        'http_code' => null,
        'content_type' => null,
        'm3u_valid' => false,
        'error' => null,
        'expiry_ts' => null,
        'expiry_source' => null,
        'xtream_status' => null,
        'xtream_raw' => null,
    ];

    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        $result['error'] = 'Please enter a valid URL.';
    } else {
        $result['is_url'] = true;

        $parts = parse_url($url) ?: [];
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        // Try Xtream API first if it looks like username/password style
        $apiUrl = detectXtreamApiUrl($url, $parts, $query);
        if ($apiUrl) {
            $apiRes = curlFetch($apiUrl, [
                CURLOPT_HTTPHEADER => ['Accept: application/json']
            ]);

            if ($apiRes['ok'] && ($apiRes['info']['http_code'] ?? 0) === 200) {
                $json = json_decode($apiRes['body'], true);

                if (is_array($json) && isset($json['user_info'])) {
                    $ui = $json['user_info'];
                    $result['xtream_raw'] = $json;

                    $status = $ui['status'] ?? ($ui['auth'] ?? null);
                    if (is_bool($status)) {
                        $status = $status ? 'Active' : 'Invalid';
                    }
                    $result['xtream_status'] = is_string($status) ? $status : null;

                    $exp = $ui['exp_date'] ?? null;
                    if (is_string($exp) && ctype_digit($exp)) {
                        $expInt = (int)$exp;
                        if ($expInt > 0) {
                            $result['expiry_ts'] = $expInt;
                            $result['expiry_source'] = 'Xtream player_api.php';
                        } else {
                            $result['expiry_ts'] = 0; // no expiry / unlimited (commonly)
                            $result['expiry_source'] = 'Xtream player_api.php (no expiry flag)';
                        }
                    } elseif (is_int($exp)) {
                        $result['expiry_ts'] = $exp;
                        $result['expiry_source'] = 'Xtream player_api.php';
                    }
                }
            }
        }

        // Fetch a small chunk of the playlist to validate it
        $m3uRes = curlFetch($url, [
            CURLOPT_RANGE => '0-8191',
            CURLOPT_HTTPHEADER => ['Accept: */*']
        ]);

        if (!$m3uRes['ok']) {
            $result['error'] = 'Network error: ' . $m3uRes['err'];
        } else {
            $info = $m3uRes['info'];
            $result['http_code'] = $info['http_code'] ?? null;
            $result['content_type'] = $info['content_type'] ?? null;

            if (($result['http_code'] ?? 0) >= 200 && ($result['http_code'] ?? 0) < 400) {
                $result['m3u_valid'] = looksLikeM3U($m3uRes['body']);
            }
        }

        // If we didn't get expiry from Xtream API, fall back to query heuristics
        if ($result['expiry_ts'] === null) {
            $qExp = parsePossibleExpiryFromQuery($query);
            if ($qExp !== null) {
                $result['expiry_ts'] = $qExp;
                $result['expiry_source'] = 'URL query parameter heuristic';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

    <meta charset="utf-8">
    <title>M3U Validity & Expiry Checker</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px}
        .wrap{max-width:900px;margin:0 auto}
        .card{background:#111827;border:1px solid #1f2937;border-radius:14px;padding:18px 20px;margin-bottom:18px;box-shadow:0 8px 24px rgba(0,0,0,.25)}
        h1{font-size:22px;margin:0 0 10px}
        label{display:block;margin-bottom:8px;color:#cbd5e1}
        input[type=text]{width:100%;padding:12px 12px;border-radius:10px;border:1px solid #334155;background:#0b1220;color:#e2e8f0}
        button{margin-top:12px;padding:10px 14px;border-radius:10px;border:1px solid #334155;background:#1f2937;color:#e2e8f0;cursor:pointer}
        button:hover{background:#273449}
        .muted{color:#94a3b8}
        .good{color:#86efac}
        .bad{color:#fca5a5}
        .warn{color:#fde047}
        pre{white-space:pre-wrap;background:#0b1220;border:1px solid #273449;border-radius:10px;padding:12px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>M3U Validity & Expiry Checker</h1>
        <div class="muted">
            Paste an M3U link. This checks if the URL responds and if the content looks like a playlist. 
            Expiry is detected via Xtream API when available or by common “expires/exp” parameters in the URL.
        </div>
        <form method="post">
            <label for="m3u_url">M3U URL</label>
            <input id="m3u_url" name="m3u_url" type="text" placeholder="https://example.com/get.php?username=...&password=...&type=m3u_plus&output=ts"
                   value="<?= isset($result['input']) ? h($result['input']) : '' ?>">
            <button type="submit">Check</button>
        </form>
    </div>

    <?php if ($result): ?>
        <div class="card">
            <h1>Result</h1>

            <?php if ($result['error']): ?>
                <div class="bad"><?= h($result['error']) ?></div>
            <?php else: ?>
                <?php
                    $http = (int)($result['http_code'] ?? 0);
                    $validText = $result['m3u_valid'] ? 'Looks like a valid M3U response.' : 'Response does not look like an M3U playlist.';
                    $validClass = $result['m3u_valid'] ? 'good' : 'warn';

                    $expiryTs = $result['expiry_ts'];
                    $expiryMsg = 'Expiry not detected.';
                    $expiryClass = 'muted';

                    if ($expiryTs === 0 && $result['expiry_source']) {
                        $expiryMsg = 'Account appears to have no expiry flag (unlimited) per provider.';
                        $expiryClass = 'good';
                    } elseif (is_int($expiryTs) && $expiryTs > 0) {
                        $dt = date('Y-m-d H:i:s T', $expiryTs);
                        if ($expiryTs < time()) {
                            $expiryMsg = "Expired on $dt.";
                            $expiryClass = 'bad';
                        } else {
                            $expiryMsg = "Expires on $dt.";
                            $expiryClass = 'good';
                        }
                    }

                    $statusMsg = '';
                    if ($result['xtream_status']) {
                        $statusMsg = 'Provider status: ' . $result['xtream_status'] . '.';
                    }
                ?>

                <div class="<?= $http >= 200 && $http < 400 ? 'good' : 'bad' ?>">
                    HTTP response code: <?= h((string)($result['http_code'] ?? 'N/A')) ?>
                </div>
                <div class="muted">
                    Content-Type: <?= h((string)($result['content_type'] ?? 'Unknown')) ?>
                </div>
                <div class="<?= $validClass ?>" style="margin-top:8px;">
                    <?= h($validText) ?>
                </div>

                <?php if ($statusMsg): ?>
                    <div class="muted" style="margin-top:8px;"><?= h($statusMsg) ?></div>
                <?php endif; ?>

                <div class="<?= $expiryClass ?>" style="margin-top:8px;">
                    <?= h($expiryMsg) ?>
                    <?php if ($result['expiry_source']): ?>
                        <span class="muted">Source: <?= h($result['expiry_source']) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($result['xtream_raw']): ?>
                    <div class="muted" style="margin-top:14px;">Xtream API snapshot (for debugging)</div>
                    <pre><?= h(json_encode($result['xtream_raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="muted">
            Note: this tool is meant for playlists you have rights to use. 
            If a provider blocks API access, expiry may not be discoverable even if the playlist still loads.
        </div>
    </div>
</div>
</body>
</html>
