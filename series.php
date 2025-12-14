<?php
// series.php - router for Xtream-style /series/... links
require_once __DIR__ . '/helpers.php';

// Path: /series/{u}/{pass_or_token}/{id}.{ext}
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$parts = array_values(array_filter(explode('/', $uri)));

if (count($parts) < 4 || strtolower($parts[0]) !== 'series') {
  http_response_code(404);
  exit('Not Found');
}

$u = (string)$parts[1];
$cred = (string)$parts[2];
$idPart = (string)$parts[3];

// Parse id + optional extension (e.g. 123.ts, 123.m3u8, 123.mp4)
$id = 0;
$ext = '';
if (preg_match('~^(\d+)(?:\.([a-z0-9]+))?$~i', $idPart, $m)) {
  $id = (int)$m[1];
  $ext = strtolower($m[2] ?? '');
} else {
  http_response_code(404);
  exit('Not Found');
}

$exp = (int)($_GET['exp'] ?? 0);
$token = '';

// Token-only mode: /series/u/{token}/{id}.ext?exp=...
if (preg_match('/^[a-f0-9]{64}$/i', $cred)) {
  $token = $cred;
  $_GET['p'] = '';
} else {
  // Legacy/basic mode: /series/u/{password}/{id}.ext
  $_GET['p'] = $cred;
  // Optional token can also be passed via querystring
  $token = (string)($_GET['token'] ?? '');
}

$_GET['u'] = $u;
$_GET['id'] = $id;
$_GET['exp'] = $exp;
$_GET['token'] = $token;
$_GET['type'] = 'episode';
// Note: $ext is not required by the stream engine; the upstream URL determines the real container.
$_GET['ext'] = $ext;

require __DIR__ . '/stream/index.php';
