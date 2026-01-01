<?php
// portal/_layout_top.php
// Requires portal/_init.php to define $user, $sub, $allowAdult

function _portal_active(string $file): string {
  $base = basename($_SERVER['PHP_SELF'] ?? '');
  return $base === $file ? 'active' : '';
}


function _portal_svg(string $name): string {
  // Simple, consistent sidebar icons (SVG) to avoid emoji baseline misalignment.
  // Uses currentColor so styling can be controlled via CSS.
  switch ($name) {
    case 'home':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"></path><path d="M5 10v10h14V10"></path><path d="M9 20v-6h6v6"></path></svg>';
    case 'tv':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="12" rx="2"></rect><path d="M7 7l-2-3"></path><path d="M17 7l2-3"></path></svg>';
    case 'calendar':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"></rect><path d="M16 2v4"></path><path d="M8 2v4"></path><path d="M3 10h18"></path></svg>';
    case 'support':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V6l-8-4-8 4v6c0 6 8 10 8 10z"></path><path d="M12 8v5"></path><path d="M12 16h.01"></path></svg>';
    case 'movies':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="15" rx="2"></rect><path d="M7 6l2 4"></path><path d="M17 6l-2 4"></path><path d="M3 10h18"></path></svg>';
    case 'series':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="M7 19v2"></path><path d="M17 19v2"></path><path d="M8 9l8 4-8 4V9z"></path></svg>';
    case 'watchlist':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17.3l-6.2 3.3 1.2-6.9L2 8.9l7-1L12 2l3 5.9 7 1-5 4.8 1.2 6.9z"></path></svg>';
    case 'user':
      return '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 0 0-16 0"></path><circle cx="12" cy="7" r="4"></circle></svg>';
    default:
      return '';
  }
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

// Fallback to VOD Enabler (system_settings) if plugin isn't enabled/configured
try {
  if (empty($__legalvod['base_url'])) {
    $base = (string)(system_setting_get($pdo, 'vod_enabler_base_url', '') ?? '');
    $movie_tpl = (string)(system_setting_get($pdo, 'vod_enabler_movie_template', '/movie/{id}/') ?? '/movie/{id}/');
    $tv_tpl = (string)(system_setting_get($pdo, 'vod_enabler_tv_template', '/tv/{id}/{season}/{episode}/') ?? '/tv/{id}/{season}/{episode}/');
    $enabled_raw = (string)(system_setting_get($pdo, 'vod_enabler_enabled', '0') ?? '0');
    $enabled_lc = strtolower(trim($enabled_raw));
    $enabled = in_array($enabled_lc, ['1','true','yes','on'], true);

    $base = rtrim(trim($base), '/');
    if ($base !== '' && $enabled) {
      $__legalvod['enabled'] = true;
      $__legalvod['base_url'] = $base;
      $__legalvod['movie_template'] = $movie_tpl !== '' ? $movie_tpl : '/movie/{id}/';
      $__legalvod['tv_template'] = $tv_tpl !== '' ? $tv_tpl : '/tv/{id}/{season}/{episode}/';
    }
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
        <div class="title">TREAM<span class="ui">ui</span></div>
        <div class="subtitle">GAME CHANGER</div>
      </div>
    </div>

    <div class="topnav">
      <a class="<?= _portal_active('index.php') ?>" href="/portal/">Home</a>
      <a class="<?= _portal_active('live.php') ?>" href="/portal/live/">Live TV</a>
      <a class="<?= _portal_active('movies.php') ?>" href="/portal/movies/">Movies</a>
      <a class="<?= _portal_active('series.php') ?>" href="/portal/series/">Series</a>
      <?php if (!empty($__watchlist_enabled)): ?>
      <a class="<?= _portal_active('watchlist.php') ?>" href="/portal/watchlist/">Watchlist</a>
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
<?php
  $req = (string)($_SERVER['REQUEST_URI'] ?? '');
  $isNotif = (strpos($req, '/portal/notifications') === 0);
  $unread = (int)($__notif_unread ?? 0);
?>
<a class="bell<?= $isNotif ? ' active' : '' ?>" href="/portal/notifications/" title="Notifications">
  <?= gc_svg_icon('bell') ?><?php if ($unread > 0): ?><span class="nbadge"><?= (int)$unread ?></span><?php endif; ?>
</a>


      <div class="avatar<?= $user ? '' : ' guest' ?>">
  <?php if ($avatarUrl): ?>
    <img src="<?= e($avatarUrl) ?>" alt="Avatar">
  <?php elseif (!$user): ?>
    <img src="/portal/assets/guest.svg" alt="Guest">
  <?php else: ?>
    <img src="/default-avatar.png" alt="Default Avatar">
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
        <span class="icon"><?=_portal_svg("home")?></span>
        Home
      </a>
      <a class="sideitem <?= _portal_active('live.php') ?>" href="/portal/live/">
        <span class="icon"><?=_portal_svg("tv")?></span>
        Live TV
      </a>
      <?php
        $req = (string)($_SERVER['REQUEST_URI'] ?? '');
        $isGuide = (strpos($req, '/portal/guide') === 0) || (strpos($req, '/portal/guide.php') === 0);
      ?>
      <a class="sideitem <?= $isGuide ? 'active' : '' ?>" href="/portal/guide/">
        <span class="icon"><?=_portal_svg("calendar")?></span>
        Guide
      </a>
      <?php if (!empty($__supportdesk_enabled)): ?>
      <?php $isSupport = (strpos($req, '/portal/support') === 0); ?>
      <a class="sideitem <?= $isSupport ? 'active' : '' ?>" href="/portal/support/">
        <span class="icon"><?=_portal_svg("support")?></span>
        Support
      </a>
      <?php endif; ?>
      <a class="sideitem <?= _portal_active('movies.php') ?>" href="/portal/movies/">
        <span class="icon"><?=_portal_svg("movies")?></span>
        Movies
      </a>
      <a class="sideitem <?= _portal_active('series.php') ?>" href="/portal/series/">
        <span class="icon"><?=_portal_svg("series")?></span>
        Series
      </a>
      <?php if (!empty($__watchlist_enabled)): ?>
      <a class="sideitem <?= _portal_active('watchlist.php') ?>" href="/portal/watchlist/">
        <span class="icon"><?=_portal_svg("watchlist")?></span>
        Watchlist
      </a>
      <?php endif; ?>
    </div>

    <div class="sidegroup">
      <div class="label">Account</div>
      <a class="sideitem" href="/dashboard.php">
        <span class="icon"><?=_portal_svg("user")?></span>
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