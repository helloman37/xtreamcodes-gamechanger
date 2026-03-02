<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

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

if (empty($_FILES['avatar']) || !is_uploaded_file($_FILES['avatar']['tmp_name'])) {
  flash_set('No file uploaded.', 'error');
  header('Location: /dashboard.php');
  exit;
}

$f = $_FILES['avatar'];
if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
  flash_set('Upload failed (error ' . (int)$f['error'] . ').', 'error');
  header('Location: /dashboard.php');
  exit;
}

$max = 2 * 1024 * 1024; // 2MB
if (($f['size'] ?? 0) > $max) {
  flash_set('Avatar too large. Max 2MB.', 'error');
  header('Location: /dashboard.php');
  exit;
}

$tmp = $f['tmp_name'];
$mime = '';
if (class_exists('finfo')) {
  $fi = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$fi->file($tmp);
}

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];

$ext = $allowed[$mime] ?? '';
if (!$ext) {
  // fallback by name extension
  $name = strtolower((string)($f['name'] ?? ''));
  if (preg_match('/\.(jpe?g|png|webp)$/', $name, $m)) $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
}
if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
  flash_set('Invalid file type. Use JPG/PNG/WEBP.', 'error');
  header('Location: /dashboard.php');
  exit;
}

$dir = gc_avatar_dir();
if (!is_dir($dir)) @mkdir($dir, 0755, true);

// Basic hardening for Apache (safe even if ignored elsewhere)
$ht = $dir . '/.htaccess';
if (!is_file($ht)) {
  @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\nDeny from all\n</FilesMatch>\n");
}

gc_delete_avatar_files($userId);

$target = $dir . '/u' . $userId . '.' . $ext;

// Re-encode via GD when possible (strips weird payloads)
$ok = false;
$data = @file_get_contents($tmp);
if ($data !== false && function_exists('imagecreatefromstring')) {
  $im = @imagecreatefromstring($data);
  if ($im) {
    // Convert everything to PNG for consistency when possible
    $target = $dir . '/u' . $userId . '.png';
    imagesavealpha($im, true);
    $ok = @imagepng($im, $target, 6);
    imagedestroy($im);
  }
}

if (!$ok) {
  $ok = @move_uploaded_file($tmp, $target);
}

if ($ok) {
  flash_set('Avatar updated.', 'success');
} else {
  flash_set('Could not save avatar. Check permissions on uploads/avatars.', 'error');
}

header('Location: /dashboard.php');
exit;
