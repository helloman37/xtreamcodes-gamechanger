<?php
// reseller/change_password.php
// Handles password changes for resellers from the reseller top-right dropdown.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

$is_ajax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
function _ajax_json_reseller($ok, $msg) {
  global $is_ajax;
  if (!$is_ajax) { return; }
  header('Content-Type: application/json');
  echo json_encode($ok ? ['ok' => true, 'message' => (string)$msg] : ['ok' => false, 'error' => (string)$msg]);
  exit;
}

// Only allow logged-in reseller
if (empty($_SESSION['reseller_id'])) {
  _ajax_json_reseller(false, 'Please sign in again.');
  header('Location: reseller_signin.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['change_password'])) {
  _ajax_json_reseller(false, 'Invalid request.');
  header('Location: reseller_dashboard.php');
  exit;
}

// Minimal same-origin check (allows /reseller/* pages)
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref) {
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $okHost = $host && (stripos($ref, '://'.$host) !== false || stripos($ref, $host) !== false);
  $path = (string)(parse_url($ref, PHP_URL_PATH) ?? '');
  $okPath = (strpos($path, '/reseller/') === 0);
  if (!$okHost || !$okPath) {
    _ajax_json_reseller(false, 'Blocked: invalid request origin.');
    flash_set('Blocked: invalid request origin.', 'error');
    header('Location: reseller_dashboard.php');
    exit;
  }
}

$current = (string)($_POST['current_password'] ?? '');
$new     = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
  _ajax_json_reseller(false, 'Please fill all password fields.');
  flash_set('Please fill all password fields.', 'error');
  header('Location: reseller_dashboard.php');
  exit;
}

if ($new !== $confirm) {
  _ajax_json_reseller(false, 'New password and confirmation do not match.');
  flash_set('New password and confirmation do not match.', 'error');
  header('Location: reseller_dashboard.php');
  exit;
}

if (strlen($new) < 8) {
  _ajax_json_reseller(false, 'New password must be at least 8 characters.');
  flash_set('New password must be at least 8 characters.', 'error');
  header('Location: reseller_dashboard.php');
  exit;
}

try {
  $pdo = db();
  $id = (int)$_SESSION['reseller_id'];

  $st = $pdo->prepare('SELECT password_hash FROM resellers WHERE id=? LIMIT 1');
  $st->execute([$id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row || empty($row['password_hash']) || !password_verify($current, $row['password_hash'])) {
    _ajax_json_reseller(false, 'Current password is incorrect.');
    flash_set('Current password is incorrect.', 'error');
    header('Location: reseller_dashboard.php');
    exit;
  }

  $hash = password_hash($new, PASSWORD_BCRYPT);
  $up = $pdo->prepare('UPDATE resellers SET password_hash=? WHERE id=?');
  $up->execute([$hash, $id]);

  _ajax_json_reseller(true, 'Password updated.');
  flash_set('Password updated.', 'success');

} catch (Exception $e) {
  _ajax_json_reseller(false, 'Password update failed.');
  flash_set('Password update failed: '.$e->getMessage(), 'error');
}

header('Location: reseller_dashboard.php');
exit;
