<?php
// Dead Stream Hunter - shared helpers

function dsh_db_init(PDO $pdo): void {
  // VOD/episode health (channels health already exists as stream_health)
  $pdo->exec("CREATE TABLE IF NOT EXISTS dsh_vod_health (
    item_type ENUM('movie','episode') NOT NULL,
    item_id INT NOT NULL,
    last_ok TIMESTAMP NULL,
    last_fail TIMESTAMP NULL,
    fail_count INT NOT NULL DEFAULT 0,
    last_http INT NULL,
    last_error VARCHAR(255) NULL,
    PRIMARY KEY (item_type, item_id),
    INDEX idx_dsh_fail (fail_count, last_fail),
    INDEX idx_dsh_ok (last_ok)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function dsh_setting_get(PDO $pdo, string $plugin_id, string $key, string $default=''): string {
  $st = $pdo->prepare("SELECT v FROM plugin_settings WHERE plugin_id=? AND k=? LIMIT 1");
  $st->execute([$plugin_id, $key]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $v = is_array($row) ? (string)($row['v'] ?? '') : '';
  return $v !== '' ? $v : $default;
}

function dsh_setting_set(PDO $pdo, string $plugin_id, string $key, string $val): void {
  $st = $pdo->prepare("INSERT INTO plugin_settings (plugin_id,k,v,updated_at)
    VALUES (?,?,?,NOW())
    ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=VALUES(updated_at)");
  $st->execute([$plugin_id, $key, $val]);
}

function dsh_parse_sources_json(?string $raw): array {
  if (!$raw) return [];
  $j = json_decode($raw, true);
  if (is_array($j)) {
    $out = [];
    // accept either list of urls or objects with url property
    foreach ($j as $it) {
      if (is_string($it)) $out[] = $it;
      if (is_array($it) && isset($it['url']) && is_string($it['url'])) $out[] = $it['url'];
    }
    return array_values(array_filter(array_map('trim',$out)));
  }
  return [];
}

// A slightly more reliable probe than a pure HEAD request.
// - If server blocks HEAD (405/403) we fall back to a small GET.
// - For .m3u8 we GET and check the playlist header.
function dsh_probe_url(string $url, int $timeout=8, bool $insecure_tls=false): array {
  $url = trim($url);
  if ($url === '') return ['works'=>false,'code'=>0,'error'=>'empty url'];

  $is_m3u8 = (stripos($url, '.m3u8') !== false) || (stripos($url, 'm3u8') !== false && stripos($url, 'live') !== false);

  $common = [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_CONNECTTIMEOUT => $timeout,
    CURLOPT_USERAGENT => 'DeadStreamHunter/1.0',
    CURLOPT_SSL_VERIFYPEER => $insecure_tls ? false : true,
    CURLOPT_SSL_VERIFYHOST => $insecure_tls ? 0 : 2,
  ];

  // Try HEAD first for non-m3u8
  if (!$is_m3u8) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $common + [
      CURLOPT_NOBODY => true,
      CURLOPT_HEADER => false,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = (string)curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 400) {
      return ['works'=>true,'code'=>$code,'error'=>''];
    }

    // Some stream origins reject HEAD. Try a tiny GET.
    if (in_array($code, [0, 400, 401, 403, 405, 406, 415, 500, 502, 503, 504], true)) {
      $ch = curl_init($url);
      curl_setopt_array($ch, $common + [
        CURLOPT_NOBODY => false,
        CURLOPT_HEADER => false,
        CURLOPT_RANGE => '0-4096',
      ]);
      $body = curl_exec($ch);
      $code2 = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      $err2  = (string)curl_error($ch);
      curl_close($ch);

      $ok = ($code2 >= 200 && $code2 < 400) && (is_string($body) ? strlen($body) > 0 : true);
      return ['works'=>$ok,'code'=>$code2,'error'=> $ok ? '' : ($err2 ?: $err ?: 'GET failed')];
    }

    return ['works'=>false,'code'=>$code,'error'=>$err ?: 'unhealthy'];
  }

  // m3u8: GET the playlist
  $ch = curl_init($url);
  curl_setopt_array($ch, $common + [
    CURLOPT_NOBODY => false,
    CURLOPT_HEADER => false,
    CURLOPT_RANGE => '0-65535',
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err  = (string)curl_error($ch);
  curl_close($ch);

  $works = ($code >= 200 && $code < 400);
  if ($works && is_string($body)) {
    $b = ltrim($body);
    // Validate it's actually a playlist
    if (stripos($b, '#EXTM3U') !== 0) {
      // Some origins return html; treat as fail
      $works = false;
      if ($err === '') $err = 'not m3u8';
    } else {
      // ensure it has at least one segment/variant line
      if (!preg_match('~\n[^#\s].+~', $b)) {
        $works = false;
        if ($err === '') $err = 'empty playlist';
      }
    }
  }

  return ['works'=>$works,'code'=>$code,'error'=>$works ? '' : ($err ?: 'unhealthy')];
}

// returns: ['ok'=>bool,'used_url'=>string,'code'=>int,'error'=>string,'switched'=>bool,'checked'=>int]
function dsh_check_with_sources(string $primary, array $sources, int $timeout, bool $insecure_tls): array {
  $urls = [];
  $primary = trim($primary);
  if ($primary !== '') $urls[] = $primary;
  foreach ($sources as $u) {
    $u = trim((string)$u);
    if ($u === '') continue;
    if (!in_array($u, $urls, true)) $urls[] = $u;
  }

  $checked = 0;
  $last = ['works'=>false,'code'=>0,'error'=>'no sources'];
  foreach ($urls as $u) {
    $checked++;
    $r = dsh_probe_url($u, $timeout, $insecure_tls);
    $last = $r;
    if (!empty($r['works'])) {
      return ['ok'=>true,'used_url'=>$u,'code'=>(int)$r['code'],'error'=>'','switched'=> ($primary !== '' && $u !== $primary),'checked'=>$checked];
    }
  }

  return ['ok'=>false,'used_url'=>$primary,'code'=>(int)($last['code'] ?? 0),'error'=>(string)($last['error'] ?? ''),'switched'=>false,'checked'=>$checked];
}

function dsh_vod_health_upsert(PDO $pdo, string $type, int $id, bool $ok, ?int $code, string $err): void {
  $err = $err !== '' ? mb_substr($err, 0, 255) : null;
  if ($ok) {
    $st = $pdo->prepare("INSERT INTO dsh_vod_health (item_type,item_id,last_ok,last_fail,fail_count,last_http,last_error)
      VALUES (?,?,NOW(),NULL,0,?,NULL)
      ON DUPLICATE KEY UPDATE last_ok=NOW(), last_fail=NULL, fail_count=0, last_http=VALUES(last_http), last_error=NULL");
    $st->execute([$type, $id, $code]);
  } else {
    $st = $pdo->prepare("INSERT INTO dsh_vod_health (item_type,item_id,last_ok,last_fail,fail_count,last_http,last_error)
      VALUES (?,?,NULL,NOW(),1,?,?)
      ON DUPLICATE KEY UPDATE last_fail=NOW(), fail_count=fail_count+1, last_http=VALUES(last_http), last_error=VALUES(last_error)");
    $st->execute([$type, $id, $code, $err]);
  }
}
