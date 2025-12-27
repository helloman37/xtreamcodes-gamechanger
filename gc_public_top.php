<?php
// Shared layout for public pages (index/plans/login/dashboard)
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$page = basename($_SERVER['PHP_SELF'] ?? '');

function _gc_active(string $file): string {
  $p = basename($_SERVER['PHP_SELF'] ?? '');
  return $p === $file ? 'active' : '';
}

$PUBLIC_TITLE = $PUBLIC_TITLE ?? 'XTREAM ui GAME CHANGER';
$PUBLIC_SIDEBAR = (bool)($PUBLIC_SIDEBAR ?? false);
$PUBLIC_PAGE = $PUBLIC_PAGE ?? $page;

$user = null;
$displayName = 'Guest';
$initial = 'G';
$avatarUrl = null;

try {
  if (!empty($_SESSION['store_user'])) {
    $pdo = db();
    $uid = is_array($_SESSION['store_user']) ? (int)($_SESSION['store_user']['id'] ?? 0) : (int)$_SESSION['store_user'];
    if ($uid > 0) {
      $st = $pdo->prepare('SELECT id, username, name FROM users WHERE id=?');
      $st->execute([$uid]);
      $user = $st->fetch();
      if ($user) {
        $displayName = trim((string)($user['name'] ?? ''));
        if ($displayName === '') $displayName = (string)($user['username'] ?? '');
        $initial = strtoupper(substr($displayName, 0, 1));

		$avatarUrl = gc_avatar_url((int)($user['id'] ?? 0));
}
    }
  }
} catch (Throwable $e) {
  // layout must never break pages
}

?><!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($PUBLIC_TITLE) ?></title>
  <link rel="stylesheet" href="/portal/assets/portal.css">
  <link rel="stylesheet" href="/portal/assets/public.css">
</head>
<body data-page="<?= e($PUBLIC_PAGE) ?>">

<?php
  // Sliding background wall (public home only)
  require_once __DIR__ . '/hero_wall.php';
  if (basename($_SERVER['PHP_SELF'] ?? '') === 'index.php') {
    echo gc_hero_wall_render();
  }
?>

<div class="portal<?= $PUBLIC_SIDEBAR ? '' : ' public' ?>">
  <div class="topbar">
    <div class="brand">
      <div class="logoX">X</div>
      <div class="textblock">
        <div class="title">TREAM<span class="ui">ui</span></div>
        <div class="subtitle">GAME CHANGER</div>
      </div>
    </div>

    <div class="topnav">
      <a class="<?= _gc_active('index.php') ?>" href="/index.php">Home</a>
      <a class="<?= _gc_active('plans.php') ?>" href="/plans.php">Plans</a>
      <?php if ($user): ?>
        <a href="/portal/">Portal</a>
        <a class="<?= _gc_active('dashboard.php') ?>" href="/dashboard.php">My Account</a>
      <?php else: ?>
        <a class="<?= _gc_active('login.php') ?>" href="/login.php">Login</a>
      <?php endif; ?>
    </div>

    <div class="userbox">
      <div class="avatar<?= $user ? '' : ' guest' ?>">
  <?php if ($avatarUrl): ?>
    <img src="<?= e($avatarUrl) ?>" alt="Avatar">
  <?php elseif (!$user): ?>
    <img src="/portal/assets/guest.svg" alt="Guest">
  <?php else: ?>
    <?= e($initial) ?>
  <?php endif; ?>
</div>
      <div>
        <div class="name"><?= e($displayName) ?></div>
        <div style="margin-top:2px;">
          <?php if ($user): ?>
            <a href="/logout.php">Logout</a>
          <?php else: ?>
            <a href="/register.php">Register</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($PUBLIC_SIDEBAR): ?>
  <div class="sidebar">
    <div class="sidegroup">
      <div class="label">Browse</div>
      <a class="sideitem" href="/portal/"><span class="icon">🏠</span>Portal Home</a>
      <a class="sideitem" href="/portal/live/"><span class="icon">📺</span>Live TV</a>
      <a class="sideitem" href="/portal/movies/"><span class="icon">🎬</span>Movies</a>
      <a class="sideitem" href="/portal/series/"><span class="icon">📼</span>Series</a>
    </div>

    <div class="sidegroup">
      <div class="label">Account</div>
      <a class="sideitem active" href="/dashboard.php"><span class="icon">👤</span>My Account</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="main">
    <div class="container">

<?php flash_show(); ?>

