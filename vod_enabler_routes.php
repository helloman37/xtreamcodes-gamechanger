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
const VOD_ENABLER_KEY_ENABLED = 'vod_enabler_enabled';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = ltrim((string)$path, '/');

$enabled = vod_enabler_is_enabled($pdo);

if (preg_match('~^movie/(\\d+)/?$~', $path, $m)) {
  vod_enabler_movie($pdo, (string)$m[1], $enabled);
  exit;
}

if (preg_match('~^tv/(\\d+)/(\\d+)/(\\d+)/?$~', $path, $m)) {
  vod_enabler_tv($pdo, (string)$m[1], (string)$m[2], (string)$m[3], $enabled);
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

function vod_enabler_is_enabled(PDO $pdo): bool {
  // Explicit toggle only. If the key is missing, treat as disabled.
  $raw = (string)(system_setting_get($pdo, VOD_ENABLER_KEY_ENABLED, '0') ?? '0');
  $raw_lc = strtolower(trim($raw));
  return in_array($raw_lc, ['1','true','yes','on'], true);
}

function vod_enabler_tpl(PDO $pdo, string $key, string $default): string {
  $v = (string)(system_setting_get($pdo, $key, $default) ?? $default);
  return $v !== '' ? $v : $default;
}

function vod_enabler_find_movie(PDO $pdo, string $tmdbId): array {
  try {
    $st = $pdo->prepare("SELECT id,name,plot,poster_url,backdrop_url,release_date,rating,tmdb_id FROM movies WHERE tmdb_id=? LIMIT 1");
    $st->execute([$tmdbId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function vod_enabler_find_series(PDO $pdo, string $tmdbId): array {
  try {
    $st = $pdo->prepare("SELECT id,name,plot,cover_url,backdrop_url,release_date,rating,tmdb_id FROM series WHERE tmdb_id=? LIMIT 1");
    $st->execute([$tmdbId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function vod_enabler_find_episode(PDO $pdo, int $seriesId, int $season, int $episode): array {
  try {
    $st = $pdo->prepare("SELECT id,title,season_num,episode_num FROM series_episodes WHERE series_id=? AND season_num=? AND episode_num=? LIMIT 1");
    $st->execute([$seriesId, $season, $episode]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: [];
  } catch (Throwable $e) {
    return [];
  }
}

function vod_enabler_page(array $data): void {
  // $data keys: title, poster, backdrop, plot, badges[], meta[], enabled(bool), iframeUrl(string), warning(string), selector(array|null)
  $title   = (string)($data['title'] ?? '');
  $poster  = (string)($data['poster'] ?? '');
  $backdrop = (string)($data['backdrop'] ?? '');
  $plot    = (string)($data['plot'] ?? '');
  $badges  = (array)($data['badges'] ?? []);
  $meta    = (array)($data['meta'] ?? []);
  $enabled = !empty($data['enabled']);
  $iframe  = (string)($data['iframeUrl'] ?? '');
  $warning = (string)($data['warning'] ?? '');
  $selector = $data['selector'] ?? null; // ['kind'=>'tv','id'=>'','season'=>1,'episode'=>1,'max_season'=>20,'max_episode'=>50,'base'=>'','tpl'=>'']

  if ($poster === '') $poster = '/tv_icon.png';

  header('Content-Type: text/html; charset=utf-8');
  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title ?: 'VOD', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
      :root{
        --bg:#0b0b0b;
        --card:#111;
        --line:rgba(255,255,255,.10);
        --text:rgba(255,255,255,.92);
        --muted:rgba(255,255,255,.65);
        --good:rgba(62,207,142,.18);
        --bad:rgba(255,88,88,.18);
      }
      html,body{height:100%;margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}
      .hero{position:relative;padding:22px 16px 18px 16px;border-bottom:1px solid var(--line);overflow:hidden}
      .hero::before{
        content:"";position:absolute;inset:0;
        background:
          linear-gradient(180deg, rgba(0,0,0,.55), rgba(0,0,0,.92)),
          url('<?= htmlspecialchars($backdrop ?: $poster, ENT_QUOTES, 'UTF-8') ?>');
        background-size:cover;background-position:center;filter:saturate(1.05);
        transform:scale(1.04);
      }
      .hero > *{position:relative}
      .row{max-width:1020px;margin:0 auto;display:flex;gap:16px;align-items:flex-start}
      .poster{width:132px;min-width:132px;height:198px;border-radius:16px;overflow:hidden;border:1px solid var(--line);background:#000}
      .poster img{width:100%;height:100%;object-fit:cover;display:block}
      h1{margin:0;font-size:22px;line-height:1.25}
      .badges{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
      .badge{font-weight:700;font-size:12px;padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.08)}
      .badge.good{background:var(--good)}
      .badge.bad{background:var(--bad)}
      .plot{margin:10px 0 0 0;color:var(--muted);font-size:13px;max-width:720px}
      .wrap{max-width:1020px;margin:16px auto;padding:0 16px 28px 16px}
      .card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px 14px 16px 14px}
      .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
      .kv{padding:10px 12px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.03)}
      .k{font-size:11px;color:var(--muted);margin-bottom:4px}
      .v{font-size:13px}
      .notice{margin-top:12px;padding:10px 12px;border-radius:14px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--muted)}
      .player{margin-top:14px;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#000}
      .player .ratio{position:relative;width:100%;padding-top:56.25%}
      .player iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
      .toolbar{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:14px}
      label{font-size:12px;color:var(--muted)}
      select{background:rgba(0,0,0,.35);color:var(--text);border:1px solid var(--line);border-radius:12px;padding:8px 10px;outline:none;min-width:92px}
      @media (max-width:640px){
        .row{flex-direction:row}
        .poster{width:108px;min-width:108px;height:162px}
        .grid{grid-template-columns:1fr}
      }
    </style>
  </head>
  <body>
    <div class="hero">
      <div class="row">
        <div class="poster"><img src="<?= htmlspecialchars($poster, ENT_QUOTES, 'UTF-8') ?>" alt=""></div>
        <div>
          <h1><?= htmlspecialchars($title ?: 'VOD', ENT_QUOTES, 'UTF-8') ?></h1>
          <div class="badges">
            <span class="badge">VOD Enabler</span>
            <span class="badge <?= $enabled ? 'good' : 'bad' ?>"><?= $enabled ? 'Playback Enabled' : 'Playback Disabled' ?></span>
            <?php foreach ($badges as $b): ?>
              <span class="badge"><?= htmlspecialchars((string)$b, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
          </div>
          <?php if ($plot !== ''): ?>
            <p class="plot"><?= htmlspecialchars($plot, ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="wrap">
      <div class="card">
        <?php if (!empty($meta)): ?>
          <div class="grid">
            <?php foreach ($meta as $k => $v): ?>
              <div class="kv"><div class="k"><?= htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') ?></div><div class="v"><?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?></div></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($warning !== ''): ?>
          <div class="notice"><?= htmlspecialchars($warning, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (is_array($selector) && !empty($selector['kind']) && $selector['kind'] === 'tv' && $enabled): ?>
          <div class="toolbar">
            <div>
              <label for="lvSeason">Season</label><br>
              <select id="lvSeason">
                <?php for ($i=1;$i<=(int)($selector['max_season'] ?? 20);$i++): ?>
                  <option value="<?= $i ?>" <?= $i===(int)($selector['season'] ?? 1)?'selected':'' ?>>S<?= $i ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div>
              <label for="lvEpisode">Episode</label><br>
              <select id="lvEpisode">
                <?php for ($i=1;$i<=(int)($selector['max_episode'] ?? 50);$i++): ?>
                  <option value="<?= $i ?>" <?= $i===(int)($selector['episode'] ?? 1)?'selected':'' ?>>E<?= $i ?></option>
                <?php endfor; ?>
              </select>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($enabled && $iframe !== ''): ?>
          <div class="player">
            <div class="ratio">
              <iframe src="<?= htmlspecialchars($iframe, ENT_QUOTES, 'UTF-8') ?>" allowfullscreen referrerpolicy="no-referrer" loading="lazy"></iframe>
            </div>
          </div>
        <?php elseif ($enabled && $iframe === ''): ?>
          <div class="notice">Playback is enabled but the VOD Server Base URL is not configured. Set it in Admin → Content → VOD Enabler.</div>
        <?php else: ?>
          <div class="notice">Playback is disabled. Enable it in Admin → Content → VOD Enabler to show the player.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (is_array($selector) && !empty($selector['kind']) && $selector['kind'] === 'tv' && $enabled): ?>
      <script>
      (function(){
        const id = <?= json_encode((string)($selector['id'] ?? '')) ?>;
        const base = <?= json_encode((string)($selector['base'] ?? '')) ?>;
        const tpl = <?= json_encode((string)($selector['tpl'] ?? '/tv/{id}/{season}/{episode}/')) ?>;
        const seasonSel = document.getElementById('lvSeason');
        const episodeSel = document.getElementById('lvEpisode');
        const frame = document.querySelector('.player iframe');
        if (!seasonSel || !episodeSel || !frame) return;

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
          // Update URL path (copy/paste friendly)
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
    <?php endif; ?>
  </body>
  </html>
  <?php
}

function vod_enabler_movie(PDO $pdo, string $tmdbId, bool $enabled): void {
  if (vod_enabler_require_login($pdo)) {
    require_once __DIR__ . '/auth.php';
    require_login();
  }

  $m = vod_enabler_find_movie($pdo, $tmdbId);
  $title = !empty($m['name']) ? (string)$m['name'] : ('Movie #' . $tmdbId);
  $plot  = (string)($m['plot'] ?? '');
  $poster = trim((string)($m['poster_url'] ?? ''));
  $backdrop = trim((string)($m['backdrop_url'] ?? ''));

  $meta = [];
  if (!empty($m['release_date'])) $meta['Year'] = substr((string)$m['release_date'], 0, 4);
  if (!empty($m['rating'])) $meta['Rating'] = (string)$m['rating'];
  $meta['TMDB ID'] = $tmdbId;

  $base = vod_enabler_base($pdo);
  $iframe = '';
  $warning = '';

  if ($enabled) {
    if ($base !== '' && preg_match('~^https?://~i', $base)) {
      $tpl = vod_enabler_tpl($pdo, VOD_ENABLER_KEY_MOVIE, '/movie/{id}/');
      $path = str_replace('{id}', rawurlencode($tmdbId), $tpl);
      $path = '/' . ltrim($path, '/');
      $iframe = $base . $path;
    }
  }

  vod_enabler_page([
    'title' => $title,
    'poster' => $poster,
    'backdrop' => $backdrop,
    'plot' => $plot,
    'badges' => ['Movie'],
    'meta' => $meta,
    'enabled' => $enabled,
    'iframeUrl' => $iframe,
    'warning' => $warning,
  ]);
}

function vod_enabler_tv(PDO $pdo, string $tmdbId, string $season, string $episode, bool $enabled): void {
  if (vod_enabler_require_login($pdo)) {
    require_once __DIR__ . '/auth.php';
    require_login();
  }

  $season_i = max(1, (int)$season);
  $episode_i = max(1, (int)$episode);

  $s = vod_enabler_find_series($pdo, $tmdbId);
  $seriesTitle = !empty($s['name']) ? (string)$s['name'] : ('Series #' . $tmdbId);
  $plot  = (string)($s['plot'] ?? '');
  $poster = trim((string)($s['cover_url'] ?? ''));
  $backdrop = trim((string)($s['backdrop_url'] ?? ''));

  $epTitle = '';
  if (!empty($s['id'])) {
    $ep = vod_enabler_find_episode($pdo, (int)$s['id'], $season_i, $episode_i);
    if (!empty($ep['title'])) $epTitle = (string)$ep['title'];
  }

  $title = $seriesTitle . ' — S' . str_pad((string)$season_i, 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)$episode_i, 2, '0', STR_PAD_LEFT);
  if ($epTitle !== '') $title .= ' · ' . $epTitle;

  $meta = [
    'Series' => $seriesTitle,
    'Season' => (string)$season_i,
    'Episode' => (string)$episode_i,
  ];
  if ($epTitle !== '') $meta['Episode Title'] = $epTitle;
  if (!empty($s['release_date'])) $meta['Year'] = substr((string)$s['release_date'], 0, 4);
  if (!empty($s['rating'])) $meta['Rating'] = (string)$s['rating'];
  $meta['TMDB ID'] = $tmdbId;

  $base = vod_enabler_base($pdo);
  $iframe = '';
  $tpl = vod_enabler_tpl($pdo, VOD_ENABLER_KEY_TV, '/tv/{id}/{season}/{episode}/');

  if ($enabled) {
    if ($base !== '' && preg_match('~^https?://~i', $base)) {
      $path = $tpl;
      $path = str_replace('{id}', rawurlencode($tmdbId), $path);
      $path = str_replace('{season}', rawurlencode((string)$season_i), $path);
      $path = str_replace('{episode}', rawurlencode((string)$episode_i), $path);
      $path = '/' . ltrim($path, '/');
      $iframe = $base . $path;
    }
  }

  $max_season = max($season_i, 20);
  $max_episode = max($episode_i, 50);

  vod_enabler_page([
    'title' => $title,
    'poster' => $poster,
    'backdrop' => $backdrop,
    'plot' => $plot,
    'badges' => ['TV'],
    'meta' => $meta,
    'enabled' => $enabled,
    'iframeUrl' => $iframe,
    'warning' => '',
    'selector' => [
      'kind' => 'tv',
      'id' => $tmdbId,
      'season' => $season_i,
      'episode' => $episode_i,
      'max_season' => $max_season,
      'max_episode' => $max_episode,
      'base' => $base,
      'tpl' => $tpl,
    ],
  ]);
}
