<?php
require_once __DIR__ . '/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['store_user'])) {
  header('Location: /login.php');
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Location: /dashboard.php');
  exit;
}

csrf_validate();

$userId = is_array($_SESSION['store_user']) ? (int)($_SESSION['store_user']['id'] ?? 0) : (int)$_SESSION['store_user'];
if ($userId <= 0) { header('Location: /logout.php'); exit; }

gc_delete_avatar_files($userId);
flash_set('Avatar removed.', 'info');

header('Location: /dashboard.php');
exit;
