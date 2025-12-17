<?php
// admin/change_password.php
// Handles password changes for admins + resellers from the top-right dropdown.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';

// Only allow logged-in admins or resellers
$is_admin = !empty($_SESSION['admin_id']);
$is_reseller = !empty($_SESSION['reseller_id']);
if (!$is_admin && !$is_reseller) {
  header('Location: signin.php');
  exit;
}

$is_ajax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
function _ajax_json($ok, $msg) {
  global $is_ajax;
  if (!$is_ajax) { return; }
  header('Content-Type: application/json');
  echo json_encode($ok ? ['ok' => true, 'message' => (string)$msg] : ['ok' => false, 'error' => (string)$msg]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['change_password'])) {
  // If someone visits directly, bounce back.
  $fallback = $is_admin ? 'dashboard.php' : 'reseller_dashboard.php';
  header('Location: '.$fallback);
  exit;
}

// Minimal CSRF mitigation: require same-origin referer from /admin
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref) {
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $okHost = $host && (stripos($ref, '://'.$host) !== false || stripos($ref, $host) !== false);
  $okPath = (strpos(parse_url($ref, PHP_URL_PATH) ?? '', '/admin/') === 0);
  if (!$okHost || !$okPath) {
    flash_set('Blocked: invalid request origin.', 'error');
    header('Location: '.($is_admin ? 'dashboard.php' : 'reseller_dashboard.php'));
    exit;
  }
}

$current = (string)($_POST['current_password'] ?? '');
$new     = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if ($current === '' || $new === '' || $confirm === '') {
  _ajax_json(false, 'Please fill all password fields.');
  flash_set('Please fill all password fields.', 'error');
  header('Location: '.($_SERVER['HTTP_REFERER'] ?? ($is_admin ? 'dashboard.php' : 'reseller_dashboard.php')));
  exit;
}

if ($new !== $confirm) {
  _ajax_json(false, 'New password and confirmation do not match.');
  flash_set('New password and confirmation do not match.', 'error');
  header('Location: '.($_SERVER['HTTP_REFERER'] ?? ($is_admin ? 'dashboard.php' : 'reseller_dashboard.php')));
  exit;
}

if (strlen($new) < 8) {
  _ajax_json(false, 'New password must be at least 8 characters.');
  flash_set('New password must be at least 8 characters.', 'error');
  header('Location: '.($_SERVER['HTTP_REFERER'] ?? ($is_admin ? 'dashboard.php' : 'reseller_dashboard.php')));
  exit;
}

try {
  $pdo = db();

  if ($is_admin) {
    $id = (int)$_SESSION['admin_id'];
    $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['password_hash']) || !password_verify($current, $row['password_hash'])) {
      _ajax_json(false, 'Current password is incorrect.');
      flash_set('Current password is incorrect.', 'error');
      header('Location: '.($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
      exit;
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $up = $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?');
    $up->execute([$hash, $id]);

    _ajax_json(true, 'Password updated.');
    flash_set('Password updated.', 'success');
  } else {
    $id = (int)$_SESSION['reseller_id'];
    $st = $pdo->prepare('SELECT password_hash FROM resellers WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['password_hash']) || !password_verify($current, $row['password_hash'])) {
      _ajax_json(false, 'Current password is incorrect.');
      flash_set('Current password is incorrect.', 'error');
      header('Location: '.($_SERVER['HTTP_REFERER'] ?? 'reseller_dashboard.php'));
      exit;
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    $up = $pdo->prepare('UPDATE resellers SET password_hash=? WHERE id=?');
    $up->execute([$hash, $id]);

    _ajax_json(true, 'Password updated.');
    flash_set('Password updated.', 'success');
  }
} catch (Exception $e) {
  flash_set('Password update failed: '.$e->getMessage(), 'error');
}

// Redirect back to wherever they were
$dest = $_SERVER['HTTP_REFERER'] ?? ($is_admin ? 'dashboard.php' : 'reseller_dashboard.php');
header('Location: '.$dest);
exit;
