<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers.php';
require_admin();

$pdo = db();

// Ensure system_settings exists (older installs safety)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(190) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  // ignore
}

const VOD_ENABLER_KEY_BASE   = 'vod_enabler_base_url';
const VOD_ENABLER_KEY_MOVIE  = 'vod_enabler_movie_template';
const VOD_ENABLER_KEY_TV     = 'vod_enabler_tv_template';
const VOD_ENABLER_KEY_LOGIN  = 'vod_enabler_require_login';
const VOD_ENABLER_KEY_ENABLED= 'vod_enabler_enabled';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vod_enabler_save'])) {
  $base = trim((string)($_POST['base_url'] ?? ''));
  $movie_tpl = trim((string)($_POST['movie_template'] ?? '/movie/{id}/'));
  $tv_tpl = trim((string)($_POST['tv_template'] ?? '/tv/{id}/{season}/{episode}/'));
  $require_login = isset($_POST['require_login']) ? '1' : '0';
  $enabled = isset($_POST['enabled']) ? '1' : '0';

  $base = rtrim($base, '/');

  if ($enabled === '1' && $base === '') {
    flash_set('Base URL is required when VOD Enabler is enabled.', 'error');
  } elseif ($base !== '' && !preg_match('~^https?://~i', $base)) {
    flash_set('Base URL must start with http:// or https://', 'error');
  } else {
    system_setting_set($pdo, VOD_ENABLER_KEY_BASE, $base);
    system_setting_set($pdo, VOD_ENABLER_KEY_MOVIE, $movie_tpl !== '' ? $movie_tpl : '/movie/{id}/');
    system_setting_set($pdo, VOD_ENABLER_KEY_TV, $tv_tpl !== '' ? $tv_tpl : '/tv/{id}/{season}/{episode}/');
    system_setting_set($pdo, VOD_ENABLER_KEY_LOGIN, $require_login);
    system_setting_set($pdo, VOD_ENABLER_KEY_ENABLED, ($base!=='')?$enabled:'0');
    flash_set('Saved.', 'ok');
    header('Location: vod_enabler.php');
    exit;
  }
}

$base = (string)(system_setting_get($pdo, VOD_ENABLER_KEY_BASE, '') ?? '');
$movie_tpl = (string)(system_setting_get($pdo, VOD_ENABLER_KEY_MOVIE, '/movie/{id}/') ?? '/movie/{id}/');
$tv_tpl = (string)(system_setting_get($pdo, VOD_ENABLER_KEY_TV, '/tv/{id}/{season}/{episode}/') ?? '/tv/{id}/{season}/{episode}/');
$require_login = (int)(system_setting_get($pdo, VOD_ENABLER_KEY_LOGIN, '0') ?? '0');

$enabled_raw = (string)(system_setting_get($pdo, VOD_ENABLER_KEY_ENABLED, '0') ?? '0');
$enabled_raw_lc = strtolower(trim($enabled_raw));
// Explicit toggle only. If the key is missing, treat as disabled.
$enabled = in_array($enabled_raw_lc, ['1','true','yes','on'], true) ? 1 : 0;


$base = rtrim(trim($base), '/');


function vod_enabler_example(string $base, string $tpl, array $vars): string {
  $u = $tpl;
  foreach ($vars as $k => $v) {
    $u = str_replace('{' . $k . '}', (string)$v, $u);
  }
  $u = '/' . ltrim($u, '/');
  return rtrim($base, '/') . $u;
}

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);

?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>VOD Enabler</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <h2>VOD Enabler <span class="pill <?= $enabled ? 'good' : 'bad' ?>" style="margin-left:8px; vertical-align:middle;"><?= $enabled ? 'Enabled' : 'Disabled' ?></span></h2>
  <?php flash_show(); ?>

  <p class="muted" style="margin-top:-6px;">
    This powers the clean viewer routes <span class="code">/movie/{id}/</span> and <span class="code">/tv/{id}/{season}/{episode}/</span>
    by embedding your chosen server inside an iframe.
  </p>

  <div class="notice" style="margin:10px 0 12px 0;">
    <b>If the player is blank:</b>
    your VOD server is probably blocking iframe embedding (X-Frame-Options / CSP), or you are embedding <b>http</b> inside an <b>https</b> panel (mixed-content block), or you accidentally pointed the Base URL back to this same panel (infinite iframe loop).
  </div>

  <form method="post" autocomplete="off">
    <input type="hidden" name="vod_enabler_save" value="1">

    <label style="display:flex; gap:10px; align-items:center; margin:10px 0 14px 0;">
      <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
      <span><b>Enable VOD Enabler</b> <span class="muted">(when off, /movie and /tv pages still show details, but no player)</span></span>
    </label>


    <label>VOD Server Base URL</label>
    <input name="base_url" value="<?= e($base) ?>" placeholder="https://your-vod-server">

    <div class="row">
      <div>
        <label>Movie Path Template</label>
        <input name="movie_template" value="<?= e($movie_tpl) ?>" placeholder="/movie/{id}/">
        <div class="muted" style="margin-top:6px;">Use <span class="code">{id}</span></div>
      </div>
      <div>
        <label>TV Path Template</label>
        <input name="tv_template" value="<?= e($tv_tpl) ?>" placeholder="/tv/{id}/{season}/{episode}/">
        <div class="muted" style="margin-top:6px;">Use <span class="code">{id}</span>, <span class="code">{season}</span>, <span class="code">{episode}</span></div>
      </div>
    </div>

    <label style="display:flex; gap:10px; align-items:center; margin-top:8px;">
      <input type="checkbox" name="require_login" value="1" <?= $require_login ? 'checked' : '' ?>>
      <span>Require user login to view these routes</span>
    </label>

    <button class="btn" type="submit" style="margin-top:12px;">Save</button>
  </form>

  <hr style="border:0; border-top:1px solid var(--line); margin:14px 0;">

  <h3 style="margin:0 0 6px 0;">Examples</h3>
  <div class="muted" style="margin-bottom:10px;">What the iframe will point at when a user visits your panel routes:</div>

  <div style="display:grid; gap:8px;">
    <div><span class="code">/movie/123/</span> → <span class="code"><?= e(vod_enabler_example($base, $movie_tpl, ['id' => 123])) ?></span></div>
    <div><span class="code">/tv/42/1/3/</span> → <span class="code"><?= e(vod_enabler_example($base, $tv_tpl, ['id' => 42, 'season' => 1, 'episode' => 3])) ?></span></div>
  </div>
</div>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
