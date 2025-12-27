<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

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

const VOD_ENABLER_KEY_BASE  = 'vod_enabler_base_url';
const VOD_ENABLER_KEY_MOVIE = 'vod_enabler_movie_template';
const VOD_ENABLER_KEY_TV    = 'vod_enabler_tv_template';
const VOD_ENABLER_KEY_LOGIN = 'vod_enabler_require_login';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ltrim((string)$path, '/');

if (preg_match('~^movie/(\\d+)/?$~', $path, $m)) {
  vod_enabler_movie($pdo, (string)$m[1]);
  exit;
}

if (preg_match('~^tv/(\\d+)/(\\d+)/(\\d+)/?$~', $path, $m)) {
  vod_enabler_tv($pdo, (string)$m[1], (string)$m[2], (string)$m[3]);
  exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found";

function vod_enabler_base(PDO $pdo): string {
  $b = (string)(system_setting_get($pdo, VOD_ENABLER_KEY_BASE, '') ?? '');
  return rtrim(trim($b), '/');
}

function vod_enabler_require_login(PDO $pdo): bool {
  $v = (string)(system_setting_get($pdo, VOD_ENABLER_KEY_LOGIN, '0') ?? '0');
  return $v === '1' || $v === 'true' || $v === 'yes';
}

function vod_enabler_tpl(PDO $pdo, string $key, string $default): string {
  $v = (string)(system_setting_get($pdo, $key, $default) ?? $default);
  return $v !== '' ? $v : $default;
}

function vod_enabler_not_configured(): void {
  http_response_code(503);
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta charset="utf-8"><title>VOD Enabler</title>';
  echo '<body style="font-family:system-ui;padding:20px;background:#0b0b0b;color:#e8e8e8">';
  echo '<h2 style="margin:0 0 10px 0;">VOD Enabler not configured</h2>';
  echo '<p style="margin:0;opacity:.85">Set the VOD Server Base URL in <b>Admin → Content → VOD Enabler</b>.</p>';
  echo '</body>';
}

function vod_enabler_movie(PDO $pdo, string $id): void {
  if (vod_enabler_require_login($pdo)) {
    require_once __DIR__ . '/auth.php';
    require_login();
  }

  $base = vod_enabler_base($pdo);
  if ($base === '' || !preg_match('~^https?://~i', $base)) {
    vod_enabler_not_configured();
    return;
  }

  $tpl = vod_enabler_tpl($pdo, VOD_ENABLER_KEY_MOVIE, '/movie/{id}/');
  $path = str_replace('{id}', rawurlencode($id), $tpl);
  $path = '/' . ltrim($path, '/');
  $iframe = $base . $path;

  header('Content-Type: text/html; charset=utf-8');
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Movie <?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
      html,body{height:100%;margin:0;background:#000}
      .wrap{position:fixed;inset:0;}
      iframe{width:100%;height:100%;border:0;}
    </style>
  </head>
  <body>
    <div class="wrap">
      <iframe
        src="<?= htmlspecialchars($iframe, ENT_QUOTES, 'UTF-8') ?>"
        allowfullscreen
        referrerpolicy="no-referrer"
        loading="lazy"></iframe>
    </div>
  </body>
  </html>
  <?php
}

function vod_enabler_tv(PDO $pdo, string $id, string $season, string $episode): void {
  if (vod_enabler_require_login($pdo)) {
    require_once __DIR__ . '/auth.php';
    require_login();
  }

  $base = vod_enabler_base($pdo);
  if ($base === '' || !preg_match('~^https?://~i', $base)) {
    vod_enabler_not_configured();
    return;
  }

  $tpl = vod_enabler_tpl($pdo, VOD_ENABLER_KEY_TV, '/tv/{id}/{season}/{episode}/');

  $path = $tpl;
  $path = str_replace('{id}', rawurlencode($id), $path);
  $path = str_replace('{season}', rawurlencode($season), $path);
  $path = str_replace('{episode}', rawurlencode($episode), $path);
  $path = '/' . ltrim($path, '/');
  $iframe = $base . $path;

  $season_i = max(1, (int)$season);
  $episode_i = max(1, (int)$episode);
  $max_season = max($season_i, 20);
  $max_episode = max($episode_i, 50);

  header('Content-Type: text/html; charset=utf-8');
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>TV <?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?> S<?= htmlspecialchars($season, ENT_QUOTES, 'UTF-8') ?>E<?= htmlspecialchars($episode, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
      :root{
        --bar-h:56px;
        --bg:#0a0a0a;
        --panel:rgba(10,10,10,.88);
        --line:rgba(255,255,255,.10);
        --text:rgba(255,255,255,.92);
        --muted:rgba(255,255,255,.65);
      }
      html,body{height:100%;margin:0;background:#000;color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}
      .wrap{position:fixed;inset:0;display:flex;flex-direction:column;}
      .bar{
        height:var(--bar-h);
        display:flex;
        align-items:center;
        gap:12px;
        padding:10px 14px;
        background:linear-gradient(180deg,var(--panel),rgba(0,0,0,.35));
        border-bottom:1px solid var(--line);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        box-sizing:border-box;
      }
      .badge{
        font-weight:800;
        letter-spacing:.5px;
        padding:6px 10px;
        border-radius:999px;
        background:rgba(255,255,255,.10);
        border:1px solid var(--line);
        user-select:none;
      }
      .controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
      .controls label{font-size:12px;color:var(--muted)}
      .controls select{
        background:rgba(0,0,0,.35);
        color:var(--text);
        border:1px solid var(--line);
        border-radius:10px;
        padding:6px 10px;
        outline:none;
        min-width:92px;
      }
      .spacer{flex:1}
      .hint{font-size:12px;color:var(--muted)}
      .frame{flex:1;min-height:0;}
      iframe{width:100%;height:100%;border:0;display:block;background:#000;}
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="bar">
        <div class="badge">VOD Enabler</div>

        <div class="controls">
          <label for="lvSeason">Season</label>
          <select id="lvSeason">
            <?php for ($i=1;$i<=$max_season;$i++): ?>
              <option value="<?= $i ?>" <?= $i===$season_i?'selected':'' ?>>S<?= $i ?></option>
            <?php endfor; ?>
          </select>

          <label for="lvEpisode">Episode</label>
          <select id="lvEpisode">
            <?php for ($i=1;$i<=$max_episode;$i++): ?>
              <option value="<?= $i ?>" <?= $i===$episode_i?'selected':'' ?>>E<?= $i ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="spacer"></div>
        <div class="hint">TV <?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?></div>
      </div>

      <div class="frame">
        <iframe
          id="lvFrame"
          src="<?= htmlspecialchars($iframe, ENT_QUOTES, 'UTF-8') ?>"
          allowfullscreen
          referrerpolicy="no-referrer"
          loading="lazy"></iframe>
      </div>
    </div>

    <script>
    (function(){
      const id = <?= json_encode($id) ?>;
      const base = <?= json_encode($base) ?>;
      const tpl = <?= json_encode($tpl) ?>;

      const seasonSel = document.getElementById('lvSeason');
      const episodeSel = document.getElementById('lvEpisode');
      const frame = document.getElementById('lvFrame');

      function buildPath(season, episode){
        let p = String(tpl || '/tv/{id}/{season}/{episode}/');
        p = p.replace('{id}', encodeURIComponent(String(id)));
        p = p.replace('{season}', encodeURIComponent(String(season)));
        p = p.replace('{episode}', encodeURIComponent(String(episode)));
        if (!p.startsWith('/')) p = '/' + p;
        return p;
      }
      function buildSrc(season, episode){
        const b = String(base || '').replace(/\/+$/,'');
        return b + buildPath(season, episode);
      }
      function sync(){
        const s = parseInt(seasonSel.value, 10) || 1;
        const e = parseInt(episodeSel.value, 10) || 1;
        frame.src = buildSrc(s, e);

        // Update URL path for copy/paste (no reload)
        let prefix = window.location.pathname || '';
        const idx = prefix.indexOf('/tv/');
        if (idx !== -1) prefix = prefix.slice(0, idx);
        const newPath = (prefix || '') + '/tv/' + encodeURIComponent(String(id)) + '/' + s + '/' + e + '/';
        try { history.replaceState({}, '', newPath); } catch(e) {}
      }

      seasonSel.addEventListener('change', function(){
        if (episodeSel) episodeSel.value = '1';
        sync();
      });
      episodeSel.addEventListener('change', sync);
    })();
    </script>
  </body>
  </html>
  <?php
}
