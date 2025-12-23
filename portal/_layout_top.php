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


// Plugin nav flags
$__supportdesk_enabled = true;
$__watchlist_enabled = true;
try {
  if (isset($pdo) && $pdo instanceof PDO) {
    $__supportdesk_enabled = ((int)system_setting_get($pdo, 'supportdesk_portal_enabled', '1') === 1);
  }
} catch (Throwable $t) { /* ignore */ }

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
  <?php if (!empty($__watchlist_enabled)): ?>
  <link rel="stylesheet" href="/portal/assets/watchlist.css?v=3">
  <?php endif; ?>
  <!-- jQuery + jPlayer (player UI) -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jplayer@2.9.2/dist/jplayer/jquery.jplayer.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jplayer@2.9.2/dist/skin/blue.monday/jplayer.blue.monday.min.css">
  <!-- HLS.js (bridge for .m3u8 in non-Safari browsers) -->
  <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<?php
// Expose plugin config to portal JS (for iframe-based players like LegalVOD)
$__legalvod = ['enabled'=>false,'base_url'=>'','movie_template'=>'/movie/{id}/','tv_template'=>'/tv/{id}/{season}/{episode}/'];
// Also used to conditionally show portal navigation entries (ex: Support Desk)
$__supportdesk_enabled = $__supportdesk_enabled ?? true;
try {
  if (isset($pdo) && $pdo instanceof PDO) {
    require_once __DIR__ . '/../plugins_core.php';
    gc_plugins_db_init($pdo);
    $st = $pdo->prepare("SELECT enabled FROM plugins WHERE id=? LIMIT 1");
    $st->execute(['legalvod']);
    $en = (int)($st->fetchColumn() ?: 0);
    $__legalvod['enabled'] = ($en === 1);
    $__legalvod['base_url'] = (string)gc_plugin_settings_get($pdo, 'legalvod', 'base_url', '');
    $__legalvod['movie_template'] = (string)gc_plugin_settings_get($pdo, 'legalvod', 'movie_template', '/movie/{id}/');
    $__legalvod['tv_template'] = (string)gc_plugin_settings_get($pdo, 'legalvod', 'tv_template', '/tv/{id}/{season}/{episode}/');
}
} catch (Throwable $t) {
  // ignore
}
?>
<script>
window.GC_PLUGINS = window.GC_PLUGINS || {};
window.GC_PLUGINS.legalvod = <?= json_encode($__legalvod, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
window.__legalvod = window.GC_PLUGINS.legalvod;
window.__allowAdult = <?= $allowAdult ? 'true' : 'false' ?>;
window.__watchlist = {enabled: <?= !empty($__watchlist_enabled) ? 'true' : 'false' ?>, api: '/portal/watchlist_api.php'};
</script>

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
      <?php if (!empty($__watchlist_enabled)): ?>
      <a class="<?= _portal_active('watchlist.php') ?>" href="/portal/watchlist.php">Watchlist</a>
      <?php endif; ?>
      <?php
        $req = (string)($_SERVER['REQUEST_URI'] ?? '');
        $isGuide = (strpos($req, '/portal/guide') === 0) || (strpos($req, '/portal/guide.php') === 0);
      ?>
      <a class="<?= $isGuide ? 'active' : '' ?>" href="/portal/guide/">Guide</a>

      <?php if ($__supportdesk_enabled):
        $req = (string)($_SERVER['REQUEST_URI'] ?? '');
        $isSupport = (strpos($req, '/portal/support') === 0);
      ?>
        <a class="<?= $isSupport ? 'active' : '' ?>" href="/portal/support/">Support</a>
      <?php endif; ?>
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
      <?php
        $req = (string)($_SERVER['REQUEST_URI'] ?? '');
        $isGuide = (strpos($req, '/portal/guide') === 0) || (strpos($req, '/portal/guide.php') === 0);
      ?>
      <a class="sideitem <?= $isGuide ? 'active' : '' ?>" href="/portal/guide/">
        <span class="icon">🗓️</span>
        Guide
      </a>
      <?php if (!empty($__supportdesk_enabled)): ?>
      <?php $isSupport = (strpos($req, '/portal/support') === 0); ?>
      <a class="sideitem <?= $isSupport ? 'active' : '' ?>" href="/portal/support/">
        <span class="icon">🆘</span>
        Support
      </a>
      <?php endif; ?>
      <a class="sideitem <?= _portal_active('movies.php') ?>" href="/portal/movies.php">
        <span class="icon">🎬</span>
        Movies
      </a>
      <a class="sideitem <?= _portal_active('series.php') ?>" href="/portal/series.php">
        <span class="icon">📼</span>
        Series
      </a>
      <?php if (!empty($__watchlist_enabled)): ?>
      <a class="sideitem <?= _portal_active('watchlist.php') ?>" href="/portal/watchlist.php">
        <span class="icon">⭐</span>
        Watchlist
      </a>
      <?php endif; ?>
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