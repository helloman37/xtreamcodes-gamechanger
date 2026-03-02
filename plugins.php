<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/plugins_core.php';

$pdo = db();
gc_plugins_db_init($pdo);

// URL path, without leading slash
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ltrim((string)$path, '/');

// Allow plugins to handle the request
if (gc_plugins_dispatch($pdo, $path)) {
  exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found";
