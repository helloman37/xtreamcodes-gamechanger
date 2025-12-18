<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout_top.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
  header('Location: /portal/series.php');
  exit;
}

[$srSql, $srParams] = package_filter_sql_series($pkg_ids, 's');
$row = null;
try {
  $sql = "SELECT s.* FROM series s WHERE s.id=? {$srSql} LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$id], $srParams));
  $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $row = null;
}

if (!$row || (!$allowAdult && !empty($row['is_adult']))) {
  header('Location: /portal/series.php');
  exit;
}

// Episodes
$ep = $pdo->prepare("SELECT * FROM series_episodes WHERE series_id=? ORDER BY season_num, episode_num");
$ep->execute([$id]);
$eps = $ep->fetchAll(PDO::FETCH_ASSOC);

// Build a season/episode map for dropdown playback
$epMap = [];
if ($eps) {
  foreach ($eps as $epp) {
    $snum = (int)($epp['season_num'] ?? 1);
    $enum = (int)($epp['episode_num'] ?? 1);

    [$playUrl] = portal_make_play_url((string)$user['username'], (int)$epp['id'], 'episode', 'm3u8');

    // LegalVOD: use iframe when plugin enabled and series has TMDB id
    if (!empty($GC_LEGALVOD_CFG) && !empty($GC_LEGALVOD_CFG['enabled']) && !empty($row['tmdb_id'])) {
      $base = rtrim((string)($GC_LEGALVOD_CFG['base_url'] ?? ''), '/');
      $tpl  = (string)($GC_LEGALVOD_CFG['tv_template'] ?? '/tv/{id}/{season}/{episode}/');
      if ($base !== '') {
        $path = $tpl;
        $path = str_replace('{id}', rawurlencode((string)$row['tmdb_id']), $path);
        $path = str_replace('{season}', rawurlencode((string)($epp['season_num'] ?? 1)), $path);
        $path = str_replace('{episode}', rawurlencode((string)($epp['episode_num'] ?? 1)), $path);
        $path = '/' . ltrim($path, '/');
        $playUrl = 'iframe:' . $base . $path;
      }
    }

    if (!isset($epMap[$snum])) $epMap[$snum] = [];
    $epMap[$snum][] = [
      'episode_num' => $enum,
      'title' => (string)($epp['title'] ?? 'Episode'),
      'play' => (string)$playUrl,
    ];
  }
  ksort($epMap);
  foreach ($epMap as $sn => $list) {
    usort($list, fn($a,$b) => ((int)$a['episode_num']) <=> ((int)$b['episode_num']));
    $epMap[$sn] = $list;
  }
}

$cover = trim((string)($row['cover_url'] ?? ''));
if ($cover === '') $cover = '/tv_icon.png';
?>

<div class="card hero">
  <h1><?= e($row['name']) ?></h1>
  <p><?= e($row['plot'] ?? '') ?></p>
  <div class="big-buttons">
    <a class="btn" href="/portal/series.php">← Back to Series</a>
  </div>
</div>

<div class="card row">
  <h2>Player</h2>
  <?php if (!$eps): ?>
    <div class="notice">No episodes found for this series yet.</div>
  <?php else: ?>
    <div class="toolbar" style="gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <div>
        <div class="tmeta" style="margin-bottom:6px;">Season</div>
        <select id="gcSeason" class="input select"></select>
      </div>
      <div>
        <div class="tmeta" style="margin-bottom:6px;">Episode</div>
        <select id="gcEpisode" class="input select" style="min-width:260px;"></select>
      </div>
      <button class="btn" type="button" id="gcPlayEpisode">Play</button>
    </div>

    <script>
      (function(){
        const EP_MAP = <?= json_encode($epMap, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        const SERIES_NAME = <?= json_encode((string)($row['name'] ?? ''), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
        const SERIES_PLOT = <?= json_encode((string)($row['plot'] ?? ''), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

        const seasonSel = document.getElementById('gcSeason');
        const epSel = document.getElementById('gcEpisode');
        const playBtn = document.getElementById('gcPlayEpisode');
        if (!seasonSel || !epSel || !playBtn) return;

        const pad2 = (n) => String(n||0).padStart(2,'0');

        const seasons = Object.keys(EP_MAP || {}).map(x => parseInt(x,10)).filter(n => !isNaN(n)).sort((a,b)=>a-b);
        if (!seasons.length) return;

        const fillSeasons = () => {
          seasonSel.innerHTML = '';
          seasons.forEach(s => {
            const o = document.createElement('option');
            o.value = String(s);
            o.textContent = 'S' + s;
            seasonSel.appendChild(o);
          });
        };

        const fillEpisodes = (season, pickEp) => {
          const list = (EP_MAP && EP_MAP[String(season)]) ? EP_MAP[String(season)] : [];
          epSel.innerHTML = '';
          list.forEach(e => {
            const o = document.createElement('option');
            o.value = String(e.episode_num);
            o.textContent = 'E' + e.episode_num + (e.title ? (' • ' + e.title) : '');
            epSel.appendChild(o);
          });
          if (pickEp) epSel.value = String(pickEp);
        };

        fillSeasons();
        seasonSel.value = String(seasons[0]);
        fillEpisodes(seasons[0], (EP_MAP[String(seasons[0])] && EP_MAP[String(seasons[0])][0]) ? EP_MAP[String(seasons[0])][0].episode_num : 1);

        seasonSel.addEventListener('change', () => {
          const s = parseInt(seasonSel.value,10) || seasons[0];
          const first = (EP_MAP[String(s)] && EP_MAP[String(s)][0]) ? EP_MAP[String(s)][0].episode_num : 1;
          fillEpisodes(s, first);
        });

        playBtn.addEventListener('click', () => {
          const s = parseInt(seasonSel.value,10) || seasons[0];
          const e = parseInt(epSel.value,10) || 1;
          const list = (EP_MAP && EP_MAP[String(s)]) ? EP_MAP[String(s)] : [];
          const found = list.find(x => (parseInt(x.episode_num,10)||0) === e) || list[0];
          if (!found || !found.play) return;

          const label = 'S' + pad2(s) + 'E' + pad2(e) + (found.title ? (' · ' + found.title) : '');
          const title = (SERIES_NAME ? (SERIES_NAME + ' — ' + label) : label);

          const raw = String(found.play);
          if (!window.GC_OPEN_MODAL) {
            alert('Player UI not loaded. Refresh the page.');
            return;
          }
          if (raw.startsWith('iframe:')) {
            window.GC_OPEN_MODAL({ title, desc: SERIES_PLOT, badges: [], url: '', iframeUrl: raw.slice('iframe:'.length) });
          } else {
            window.GC_OPEN_MODAL({ title, desc: SERIES_PLOT, badges: [], url: raw, iframeUrl: '' });
          }
        });
      })();
    </script>
  <?php endif; ?>
</div>

<div class="card row">
  <h2>Episodes</h2>
  <?php if (!$eps): ?>
    <div class="notice">No episodes found for this series yet.</div>
  <?php else: ?>
    <div class="list">
      <?php foreach ($eps as $epp):
        [$playUrl] = portal_make_play_url((string)$user['username'], (int)$epp['id'], 'episode', 'm3u8');
        // LegalVOD: use iframe when plugin enabled and series has TMDB id
        if (!empty($GC_LEGALVOD_CFG) && !empty($GC_LEGALVOD_CFG['enabled']) && !empty($row['tmdb_id'])) {
          $base = rtrim((string)($GC_LEGALVOD_CFG['base_url'] ?? ''), '/');
          $tpl  = (string)($GC_LEGALVOD_CFG['tv_template'] ?? '/tv/{id}/{season}/{episode}/');
          if ($base !== '') {
            $path = $tpl;
            $path = str_replace('{id}', rawurlencode((string)$row['tmdb_id']), $path);
            $path = str_replace('{season}', rawurlencode((string)($epp['season_num'] ?? 1)), $path);
            $path = str_replace('{episode}', rawurlencode((string)($epp['episode_num'] ?? 1)), $path);
            $path = '/' . ltrim($path, '/');
            $playUrl = 'iframe:' . $base . $path;
          }
        }
        $title = 'S' . str_pad((string)($epp['season_num'] ?? 1), 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)($epp['episode_num'] ?? 1), 2, '0', STR_PAD_LEFT) . ' · ' . (string)($epp['title'] ?? 'Episode');
      ?>
        <div class="item js-play" data-play-url="<?= e($playUrl) ?>" data-title="<?= e($row['name'] . ' — ' . $title) ?>" data-desc="<?= e($row['plot'] ?? '') ?>">
          <img src="<?= e($cover) ?>" alt="">
          <div>
            <div class="name"><?= e($title) ?></div>
            <div class="meta"><?= e($row['name']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
