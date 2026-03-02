<?php
// licensebox_client.php
// Minimal LicenseBox External API client used by admin UI (plugins installer).
// Keeps secrets server-side; reads config.local.php (recommended) or env vars.

function lb_cfg(): array {
  global $config;
  $lb = $config['licensebox'] ?? [];
  if (!is_array($lb)) $lb = [];

  $base = $lb['base_url'] ?? getenv('LB_BASE') ?? 'https://license.iptvnetworking.com/';
  $base = rtrim((string)$base, '/') . '/';

  $key = $lb['api_key'] ?? getenv('LB_API_KEY') ?? '';
  $internal_key = $lb['internal_api_key'] ?? getenv('LB_INTERNAL_API_KEY') ?? '';
  // Optional: plain JSON endpoint for store listing (no LicenseBox internal API required)
  $products_json_url = $lb['products_json_url'] ?? getenv('LB_PRODUCTS_JSON_URL') ?? '';
  $lang = $lb['lang'] ?? getenv('LB_LANG') ?? 'english';

  $url_override = $lb['url_override'] ?? getenv('LB_URL_OVERRIDE') ?? '';
  $ip_override  = $lb['ip_override']  ?? getenv('LB_IP_OVERRIDE')  ?? '';

  $curl_insecure = $lb['curl_insecure'] ?? getenv('LB_CURL_INSECURE');
  if (!is_bool($curl_insecure)) {
    $curl_insecure = in_array(strtolower((string)$curl_insecure), ['1','true','yes','on'], true);
  }

  $products = $lb['products'] ?? [];
  if (!is_array($products) || !$products) {
    // Defaults (you can override in config.local.php)
    $products = [
      'supportdesk' => ['name' => 'SUPPORT DESK', 'product_id' => 'B076E357', 'current_version' => '0.0.0', 'download_filename' => 'SupportDesk.zip'],
      'p1' => ['name' => 'Product 6D01EC7E', 'product_id' => '6D01EC7E', 'current_version' => '0.0.0', 'download_filename' => 'Product-6D01EC7E.zip'],
      'p2' => ['name' => 'Product 22993E66', 'product_id' => '22993E66', 'current_version' => '0.0.0', 'download_filename' => 'Product-22993E66.zip'],
    ];
  }

  return [
    'base_url' => $base,
    'api_key' => (string)$key,
    'internal_api_key' => (string)$internal_key,
    'products_json_url' => trim((string)$products_json_url),
    'lang' => (string)$lang,
    'url_override' => (string)$url_override,
    'ip_override' => (string)$ip_override,
    'curl_insecure' => (bool)$curl_insecure,
    'products' => $products,
  ];
}

function lb_current_url(): string {
  $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
            ? 'https://' : 'http://';
  $uri = $_SERVER['REQUEST_URI'] ?? '/';
  return $scheme . $host . $uri;
}

function lb_server_ip(): string {
  // Prefer the actual server address (not client IP).
  $ip = $_SERVER['SERVER_ADDR'] ?? '';
  if ($ip) return $ip;
  $ip = gethostbyname(gethostname());
  return $ip ?: '127.0.0.1';
}

function lb_request(string $endpoint, array $payload, bool $binary=false, string $api_key_override=''): array {
  $cfg = lb_cfg();
  $url = $cfg['base_url'] . ltrim($endpoint, '/');

  $api_key = $api_key_override !== '' ? $api_key_override : (string)($cfg['api_key'] ?? '');
  if ($api_key === '') {
    return ['http' => 0, 'ok' => false, 'error' => 'LicenseBox API key missing. Set licensebox.api_key in config.local.php or env LB_API_KEY.', 'body' => ''];
  }

  $lb_url = $cfg['url_override'] ?: lb_current_url();
  $lb_ip  = $cfg['ip_override']  ?: lb_server_ip();

  $ch = curl_init();
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => [
      'LB-API-KEY: ' . $api_key,
      'LB-URL: ' . $lb_url,
      'LB-IP: ' . $lb_ip,
      'LB-LANG: ' . ($cfg['lang'] ?: 'english'),
      'Content-Type: application/json',
    ],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_RETURNTRANSFER => true,
  ]);

  if ($cfg['curl_insecure']) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  }

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false) $body = '';
  $ok = ($err === '' && $http >= 200 && $http < 300);

  if ($binary) {
    return ['http' => $http, 'ok' => $ok, 'error' => $err, 'body' => $body];
  }

  $data = json_decode((string)$body, true);
  $jsonErr = json_last_error();
  if ($jsonErr !== JSON_ERROR_NONE) {
    $data = null;
    if ($ok && trim((string)$body) !== '') {
      $ok = false;
      if ($err === '') $err = 'Invalid JSON from LicenseBox: ' . json_last_error_msg();
    }
  }
  return ['http' => $http, 'ok' => $ok, 'error' => $err, 'body' => (string)$body, 'json' => $data];
}

function lb_verify_product(string $product_id, string $license, string $email): array {
  $payload = [
    'product_id' => $product_id,
    'license_code' => $license,
    'client_name' => $email,
    'time_based_check' => false,
  ];
  return lb_request('api/verify_license', $payload, false);
}

function lb_check_update(string $product_id, string $current_version, string $license, string $email): array {
  $payload = [
    'product_id' => $product_id,
    'license_code' => $license,
    'client_name' => $email,
    'current_version' => $current_version,
    'time_based_check' => false,
  ];
  return lb_request('api/check_update', $payload, false);
}

function lb_latest_version(string $product_id): array {
  $payload = [ 'product_id' => $product_id ];
  return lb_request('api/latest_version', $payload, false);
}

function lb_download_update_main(string $update_id, string $license, string $email): array {
  $payload = [
    'license_code' => $license,
    'client_name' => $email,
  ];
  return lb_request('api/download_update/main/' . $update_id, $payload, true);
}

// Stream a download_update ZIP directly to a file to avoid holding large binaries in memory.
// Returns: ['http' => int, 'ok' => bool, 'error' => string]
function lb_download_update_to_file(string $update_id, string $license, string $email, string $destFile): array {
  $cfg = lb_cfg();
  $url = $cfg['base_url'] . 'api/download_update/main/' . rawurlencode($update_id);

  $api_key = (string)($cfg['api_key'] ?? '');
  if ($api_key === '') {
    return ['http' => 0, 'ok' => false, 'error' => 'LicenseBox API key missing. Set licensebox.api_key in config.local.php or env LB_API_KEY.'];
  }

  $lb_url = $cfg['url_override'] ?: lb_current_url();
  $lb_ip  = $cfg['ip_override']  ?: lb_server_ip();

  $payload = [
    'license_code' => $license,
    'client_name' => $email,
  ];
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if ($json === false) $json = '{}';

  $fp = @fopen($destFile, 'wb');
  if (!$fp) {
    return ['http' => 0, 'ok' => false, 'error' => 'Could not write to temp file for download.'];
  }

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => [
      'LB-API-KEY: ' . $api_key,
      'LB-URL: ' . $lb_url,
      'LB-IP: ' . $lb_ip,
      'LB-LANG: ' . ($cfg['lang'] ?: 'english'),
      'Content-Type: application/json',
    ],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FILE => $fp,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
  ]);

  if (!empty($cfg['curl_insecure'])) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  }

  $okExec = curl_exec($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  fclose($fp);

  $ok = ($okExec !== false && $err === '' && $http >= 200 && $http < 300);
  if (!$ok) {
    @unlink($destFile);
  }

  return ['http' => $http, 'ok' => $ok, 'error' => (string)$err];
}

/**
 * Stream a LicenseBox download_update ZIP directly to the browser (no temp file).
 * Returns: ['ok'=>bool,'http'=>int,'error'=>string,'content_type'=>string,'bytes'=>int]
 */
function lb_download_update_stream_to_browser(string $update_id, string $license, string $email, string $filename): array {
  $cfg = lb_cfg();
  $url = $cfg['base_url'] . 'api/download_update/main/' . rawurlencode($update_id);

  $api_key = (string)($cfg['api_key'] ?? '');
  if ($api_key === '') {
    return ['ok' => false, 'http' => 0, 'error' => 'LicenseBox API key missing. Set licensebox.api_key in config.local.php or env LB_API_KEY.', 'content_type' => '', 'bytes' => 0];
  }

  $lb_url = $cfg['url_override'] ?: lb_current_url();
  $lb_ip  = $cfg['ip_override']  ?: lb_server_ip();

  $payload = [
    'license_code' => $license,
    'client_name' => $email,
  ];
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if ($json === false) $json = '{}';

  // Ensure clean output for downloads
  if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
  @ini_set('zlib.output_compression', 'Off');
  while (ob_get_level() > 0) { @ob_end_clean(); }
  @session_write_close();

  $http = 0;
  $contentType = '';
  $contentLength = 0;

  $started = false;
  $buffer = '';
  $bytesOut = 0;
  $abortReason = '';

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_HTTPHEADER => [
      'LB-API-KEY: ' . $api_key,
      'LB-URL: ' . $lb_url,
      'LB-IP: ' . $lb_ip,
      'LB-LANG: ' . ($cfg['lang'] ?: 'english'),
      'Content-Type: application/json',
    ],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_HEADER => false,
    CURLOPT_BUFFERSIZE => 262144,
  ]);

  if (!empty($cfg['curl_insecure'])) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  }

  curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $header) use (&$http, &$contentType, &$contentLength) {
    $len = strlen($header);
    if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $m)) {
      $http = (int)$m[1];
    } elseif (stripos($header, 'Content-Type:') === 0) {
      $contentType = trim(substr($header, 13));
    } elseif (stripos($header, 'Content-Length:') === 0) {
      $contentLength = (int)trim(substr($header, 15));
    }
    return $len;
  });

  curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$started, &$buffer, &$bytesOut, &$abortReason, &$http, &$contentType, &$contentLength, $filename) {
    $l = strlen($data);
    if ($l === 0) return 0;

    if ($started) {
      echo $data;
      $bytesOut += $l;
      if (function_exists('flush')) { @flush(); }
      return $l;
    }

    $buffer .= $data;

    // If we don't know status yet, buffer a little.
    if ($http === 0 && strlen($buffer) < 4096) return $l;

    // Non-200 => abort and keep a short preview
    if ($http && $http !== 200) {
      $preview = substr($buffer, 0, 512);
      $preview = preg_replace('/[\x00-\x1F\x7F]/', ' ', $preview);
      $abortReason = 'HTTP ' . $http . ' from LicenseBox. ' . trim($preview);
      return 0;
    }

    // ZIP signature check (PK)
    if (strlen($buffer) >= 2) {
      $sig = substr($buffer, 0, 2);
      if ($sig === 'PK') {
        $safe = str_replace('"', '', (string)$filename);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $safe . '"');
        header('Content-Transfer-Encoding: binary');
        header('X-Content-Type-Options: nosniff');
        header('Expires: 0');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        if ($contentLength > 0) header('Content-Length: ' . $contentLength);

        echo $buffer;
        $bytesOut += strlen($buffer);
        $buffer = '';
        $started = true;

        if (function_exists('flush')) { @flush(); }
        return $l;
      }
    }

    // Not ZIP; if buffer grows, abort with preview
    if (strlen($buffer) >= 8192) {
      $preview = substr($buffer, 0, 512);
      $preview = preg_replace('/\s+/', ' ', trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $preview)));
      $abortReason = 'LicenseBox did not return a ZIP (Content-Type: ' . ($contentType ?: 'unknown') . '). Preview: ' . $preview;
      return 0;
    }

    return $l;
  });

  $okExec = curl_exec($ch);
  $err  = (string)curl_error($ch);
  if ($http === 0) $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($started) {
    return ['ok' => true, 'http' => $http, 'error' => '', 'content_type' => (string)$contentType, 'bytes' => (int)$bytesOut];
  }

  if ($abortReason !== '') {
    return ['ok' => false, 'http' => $http, 'error' => $abortReason, 'content_type' => (string)$contentType, 'bytes' => (int)$bytesOut];
  }

  if ($err !== '') {
    return ['ok' => false, 'http' => $http, 'error' => $err, 'content_type' => (string)$contentType, 'bytes' => (int)$bytesOut];
  }

  if ($okExec === false) {
    return ['ok' => false, 'http' => $http, 'error' => 'Download failed.', 'content_type' => (string)$contentType, 'bytes' => (int)$bytesOut];
  }

  return ['ok' => false, 'http' => $http, 'error' => 'Empty response body from LicenseBox (0 bytes).', 'content_type' => (string)$contentType, 'bytes' => (int)$bytesOut];
}




function lb_get_products_internal(): array {
  $cfg = lb_cfg();
  $key = (string)($cfg['internal_api_key'] ?? '');
  if ($key === '') {
    return ['http' => 0, 'ok' => false, 'error' => 'LicenseBox internal API key not set. Set licensebox.internal_api_key in config.local.php or env LB_INTERNAL_API_KEY.', 'body' => '', 'json' => null];
  }
  return lb_request('api/get_products', [], false, $key);
}

function lb_get_products_json_url(string $url): array {
  $url = trim($url);
  if ($url === '') {
    return ['http' => 0, 'ok' => false, 'error' => 'Products JSON URL is empty.', 'body' => '', 'json' => null];
  }

  $cfg = lb_cfg();
  $ch = curl_init();

  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_HTTPGET => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
      'User-Agent: IPTVNetworking-Panel/1.0',
    ],
  ]);

  if (!empty($cfg['curl_insecure'])) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  }

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($body === false) $body = '';
  $ok = ($err === '' && $http >= 200 && $http < 300);

  $data = json_decode((string)$body, true);
  $jsonErr = json_last_error();
  if ($jsonErr !== JSON_ERROR_NONE) {
    $data = null;
    if ($ok && trim((string)$body) !== '') {
      $ok = false;
      if ($err === '') $err = 'Invalid JSON from products JSON URL: ' . json_last_error_msg();
    }
  }

  return ['http' => $http, 'ok' => $ok, 'error' => $err, 'body' => (string)$body, 'json' => $data];
}

function lb_get_products_cached(int $ttl_seconds = 300): array {
  $cfg = lb_cfg();
  $cacheDir = __DIR__ . '/cache';
  $cacheKey = '';
  if (!empty($cfg['products_json_url'])) {
    $cacheKey = 'json_' . substr(md5((string)$cfg['products_json_url']), 0, 12);
  } else {
    $cacheKey = 'lb_internal';
  }
  $cacheFile = $cacheDir . '/lb_products_' . $cacheKey . '.json';

  if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl_seconds)) {
    $raw = (string)@file_get_contents($cacheFile);
    $j = json_decode($raw, true);
    if (is_array($j)) {
      return ['http' => 200, 'ok' => true, 'error' => '', 'body' => $raw, 'json' => $j, 'cached' => true];
    }
  }

  // Source priority:
  // 1) licensebox.products_json_url (GET JSON)
  // 2) LicenseBox internal API (api/get_products)
  if (!empty($cfg['products_json_url'])) {
    $r = lb_get_products_json_url((string)$cfg['products_json_url']);
  } else {
    $r = lb_get_products_internal();
  }
  if (!empty($r['ok']) && !empty($r['body'])) {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
      @file_put_contents($cacheFile, (string)$r['body']);
    }
  }
  return $r;
}

function lb_products_from_get_products(array $resp): array {
  $j = $resp['json'] ?? null;
  if (!is_array($j)) return [];

  // Optional global payment info at the root of the JSON
  $globalCash = '';
  foreach (['cashapp', 'cashapp_tag', 'cashtag', 'cash_tag', 'cashapp_cashtag', 'cashapp_handle'] as $k) {
    if (isset($j[$k]) && is_string($j[$k])) { $globalCash = trim((string)$j[$k]); break; }
  }
  if ($globalCash !== '' && $globalCash[0] !== '$') $globalCash = '$' . ltrim($globalCash, '@$');

  // Try a few common shapes
  $list = [];
  if (isset($j['products']) && is_array($j['products'])) $list = $j['products'];
  elseif (isset($j['data']) && is_array($j['data'])) $list = $j['data'];
  elseif (isset($j['result']) && is_array($j['result'])) $list = $j['result'];
  elseif (isset($j['items']) && is_array($j['items'])) $list = $j['items'];
  elseif (isset($j['list']) && is_array($j['list'])) $list = $j['list'];
  elseif (isset($j['product']) && is_array($j['product'])) $list = [$j['product']];
  elseif (isset($j['plugin']) && is_array($j['plugin'])) $list = [$j['plugin']];
  elseif (isset($j[0]) && is_array($j[0])) $list = $j;
  // If the root is itself a single product object
  elseif (isset($j['product_id']) || isset($j['id']) || isset($j['pid'])) $list = [$j];
  else {
    // If it's an associative map keyed by product_id, convert to list
    $allArrays = true;
    foreach ($j as $k => $v) { if (!is_array($v)) { $allArrays = false; break; } }
    if ($allArrays) {
      foreach ($j as $k => $v) {
        if (!isset($v['product_id']) && !isset($v['id']) && !isset($v['pid'])) $v['product_id'] = (string)$k;
        $list[] = $v;
      }
    }
  }

  // If $list is an associative map keyed by product_id, convert it to a list
  if (is_array($list) && !empty($list)) {
    $keys = array_keys($list);
    $isSequential = ($keys === range(0, count($list) - 1));
    if (!$isSequential) {
      $allArrays = true;
      foreach ($list as $k => $v) { if (!is_array($v)) { $allArrays = false; break; } }
      if ($allArrays) {
        $tmp = [];
        foreach ($list as $k => $v) {
          if (!isset($v['product_id']) && !isset($v['id']) && !isset($v['pid'])) $v['product_id'] = (string)$k;
          $tmp[] = $v;
        }
        $list = $tmp;
      }
    }
  }

$out = [];
  foreach ($list as $p) {
    if (!is_array($p)) continue;

    $pid = (string)($p['product_id'] ?? $p['id'] ?? $p['pid'] ?? '');
    if ($pid === '') continue;

    $name = (string)($p['product_name'] ?? $p['name'] ?? $p['title'] ?? ('Product ' . $pid));

    // Price (support both already-formatted "$9.99" strings and numeric values)
    $rawPrice = $p['price'] ?? $p['product_price'] ?? $p['amount'] ?? $p['cost'] ?? $p['usd'] ?? $p['price_usd'] ?? '';
    if (is_array($rawPrice)) {
      $rawPrice = $rawPrice['amount'] ?? $rawPrice['value'] ?? $rawPrice['price'] ?? '';
    }
    $price = trim((string)$rawPrice);

    $currencySymbol = trim((string)($p['currency_symbol'] ?? $p['currency_symboL'] ?? $p['symbol'] ?? ''));
    $currencyCode   = trim((string)($p['currency'] ?? $p['currency_code'] ?? $p['code'] ?? ''));

    // If numeric, format with currency if provided
    if ($price !== '' && preg_match('/^\d+(\.\d+)?$/', $price)) {
      if ($currencySymbol !== '') $price = $currencySymbol . $price;
      elseif ($currencyCode !== '') $price = $currencyCode . ' ' . $price;
    }

    // Description (optional)
    $desc = (string)($p['description'] ?? $p['product_description'] ?? $p['desc'] ?? '');

    // CashApp / payment (optional; can be per-product or global)
    $cash = trim((string)($p['cashapp'] ?? $p['cashapp_tag'] ?? $p['cashtag'] ?? $p['cash_tag'] ?? $p['cashapp_cashtag'] ?? $p['cashapp_handle'] ?? ''));
    if ($cash === '' && $globalCash !== '') $cash = $globalCash;
    if ($cash !== '' && $cash[0] !== '$') $cash = '$' . ltrim($cash, '@$');

    $payUrl = trim((string)($p['pay_url'] ?? $p['payment_url'] ?? $p['url'] ?? ''));
    if ($payUrl === '' && $cash !== '') $payUrl = 'https://cash.app/' . $cash;
    if ($payUrl !== '' && !preg_match('~^https?://~i', $payUrl)) {
      // allow "cash.app/$tag" or "www.cash.app/$tag"
      $payUrl = 'https://' . ltrim($payUrl, '/');
    }

    $out[$pid] = [
      'product_id' => $pid,
      'store_name' => $name,
      'price' => $price,
      'currency_symbol' => $currencySymbol,
      'currency_code' => $currencyCode,
      'store_description' => $desc,
      'cashapp' => $cash,
      'pay_url' => $payUrl,
    ];

    // Pass-through optional fields if your JSON provides them (nice for store UI / downloads)
    foreach (['category','badge','download_filename','current_version','type','kind','downloadable','is_downloadable'] as $k) {
      if (array_key_exists($k, $p)) {
        $out[$pid][$k] = $p[$k];
      }
    }
  }
  return $out;
}



function lb_is_zip(string $bytes): bool {
  return isset($bytes[0], $bytes[1]) && $bytes[0] === 'P' && $bytes[1] === 'K';
}
