<?php
// portal/_layout_top.php
// Requires portal/_init.php to define $user, $sub, $allowAdult

function _portal_active(string $file): string {
  $base = basename($_SERVER['PHP_SELF'] ?? '');
  return $base === $file ? 'active' : '';
}

$uname = (string)($user['username'] ?? '');
$displayName = trim((string)($user['name'] ?? ''));
if ($displayName === '') $displayName = $uname;
$initial = strtoupper(substr($displayName, 0, 1));


$avatarUrl = $user ? gc_avatar_url((int)($user['id'] ?? 0)) : null;
?><!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>XTREAM ui GAME CHANGER</title>
  <link rel="stylesheet" href="/portal/assets/portal.css">
  <!-- jQuery + jPlayer (player UI) -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jplayer@2.9.2/dist/jplayer/jquery.jplayer.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jplayer@2.9.2/dist/skin/blue.monday/jplayer.blue.monday.min.css">
  <!-- HLS.js (bridge for .m3u8 in non-Safari browsers) -->
  <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
</head>
<body data-page="<?= e($PORTAL_PAGE ?? '') ?>">

<div class="portal">
  <div class="topbar">
    <div class="brand">
      <div class="logoX">X</div>
      <div class="textblock">
        <div class="title">TREAM<span style="font-weight:800; opacity:.92;">ui</span></div>
        <div class="subtitle">GAME CHANGER</div>
      </div>
    </div>

    <div class="topnav">
      <a class="<?= _portal_active('index.php') ?>" href="/portal/">Home</a>
      <a class="<?= _portal_active('live.php') ?>" href="/portal/live.php">Live TV</a>
      <a class="<?= _portal_active('movies.php') ?>" href="/portal/movies.php">Movies</a>
      <a class="<?= _portal_active('series.php') ?>" href="/portal/series.php">Series</a>
      <a href="/dashboard.php">My Account</a>
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
          <a href="/logout.php">Logout</a>
        </div>
      </div>
    </div>
  </div>

  <div class="sidebar">
    <div class="sidegroup">
      <div class="label">Browse</div>
      <a class="sideitem <?= _portal_active('index.php') ?>" href="/portal/">
        <span class="icon">🏠</span>
        Home
      </a>
      <a class="sideitem <?= _portal_active('live.php') ?>" href="/portal/live.php">
        <span class="icon">📺</span>
        Live TV
      </a>
      <a class="sideitem <?= _portal_active('movies.php') ?>" href="/portal/movies.php">
        <span class="icon">🎬</span>
        Movies
      </a>
      <a class="sideitem <?= _portal_active('series.php') ?>" href="/portal/series.php">
        <span class="icon">📼</span>
        Series
      </a>
    </div>

    <div class="sidegroup">
      <div class="label">Account</div>
      <a class="sideitem" href="/dashboard.php">
        <span class="icon">👤</span>
        My Account
      </a>
    </div>
  </div>

  <div class="main">
    <div class="container">

<?php flash_show(); ?>


<?php if (!$sub): ?>
  <div class="card notice">
    No active subscription found. <a href="/plans.php">Buy a Plan</a>
  </div>
<?php endif; ?>
