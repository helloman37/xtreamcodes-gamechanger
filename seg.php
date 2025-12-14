<?php
// seg.php - router for /seg/... segment proxy
require_once __DIR__ . '/helpers.php';

// Path: /seg/{u}/{pass_or_token}/{id}
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$parts = array_values(array_filter(explode('/', $uri)));

if (count($parts) < 4 || strtolower($parts[0]) !== 'seg') {
  http_response_code(404);
  exit('Not Found');
}

$u = (string)$parts[1];
$cred = (string)$parts[2];
$id = (int)$parts[3];

$exp = (int)($_GET['exp'] ?? 0);
$token = '';

// Token-only mode
if (preg_match('/^[a-f0-9]{64}$/i', $cred)) {
  $token = $cred;
  $_GET['p'] = '';
} else {
  $_GET['p'] = $cred;
  $token = (string)($_GET['token'] ?? '');
}

$_GET['u'] = $u;
$_GET['id'] = $id;
$_GET['exp'] = $exp;
$_GET['token'] = $token;

// Note: querystring params like type=, st=, src= are passed through automatically.
require __DIR__ . '/stream/segment.php';
