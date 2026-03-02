<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/email_lib.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$pdo = db();

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
  flash_set('Invalid verification link.', 'error');
  header('Location: /login.php');
  exit;
}

$st = $pdo->prepare("SELECT id FROM users WHERE email_verify_token=? LIMIT 1");
$st->execute([$token]);
$uid = (int)($st->fetchColumn() ?: 0);
if ($uid < 1) {
  flash_set('Verification link expired or already used.', 'error');
  header('Location: /login.php');
  exit;
}

$pdo->prepare("UPDATE users SET email_verified_at=NOW(), email_verify_token=NULL WHERE id=?")
  ->execute([$uid]);

flash_set('Email verified! You can now use the service.', 'success');

// If user is logged in, keep them logged in and send them to portal/dashboard.
if (!empty($_SESSION['store_user'])) {
  header('Location: /dashboard.php');
  exit;
}

header('Location: /login.php');
exit;
?>
