<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/_layout_top.php';
require_once __DIR__ . '/tmdb_common.php';

// Featured Live (randomized)
[$pkgSql, $pkgParams] = package_filter_sql($pkg_ids, 'c');
$sql = "SELECT c.id, c.name, c.tvg_id, c.tvg_logo, c.category_id, COALESCE(cat.name,'Uncategorized') AS category
        FROM channels c
        LEFT JOIN categories cat ON cat.id=c.category_id
        WHERE 1=1 {$pkgSql}";
if (!$allowAdult) $sql .= " AND IFNULL(c.is_adult,0)=0";
$sql .= " ORDER BY RAND() LIMIT 12";

$st = $pdo->prepare($sql);
$st->execute($pkgParams);
$channels = $st->fetchAll(PDO::FETCH_ASSOC);

// EPG "Now" map for featured channels (optional)
$epgNowMap = [];
try {
  $tvgIds = [];
  foreach ($channels as $c) {
    $id = trim((string)($c['tvg_id'] ?? ''));
    if ($id !== '') $tvgIds[$id] = true;
  }
  $tvgIds = array_keys($tvgIds);

  $chunkSize = 150;
  for ($i = 0; $i < count($tvgIds); $i += $chunkSize) {
    $chunk = array_slice($tvgIds, $i, $chunkSize);
    if (!$chunk) continue;

    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $sqlEpg = "
      SELECT p.channel_xmltv_id, p.title, p.start_utc, p.stop_utc
      FROM epg_programs p
      INNER JOIN (
        SELECT channel_xmltv_id, MAX(start_utc) AS max_start
        FROM epg_programs
        WHERE start_utc <= UTC_TIMESTAMP()
          AND stop_utc  > UTC_TIMESTAMP()
          AND channel_xmltv_id IN ($ph)
        GROUP BY channel_xmltv_id
      ) x
        ON x.channel_xmltv_id = p.channel_xmltv_id
       AND x.max_start = p.start_utc
    ";
    $stE = $pdo->prepare($sqlEpg);
    $stE->execute($chunk);
    foreach ($stE->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $epgNowMap[(string)$r['channel_xmltv_id']] = $r;
    }
  }
} catch (Throwable $e) {
  // Ignore EPG failures; portal still works without EPG.
}


// Trending (TMDB) for homepage "Latest" rows
$tmdbMovies = [];
$tmdbSeries = [];
$tmdbMovieErr = '';
$tmdbSeriesErr = '';

$trM = portal_tmdb_api('/trending/movie/week', ['page' => 1], 900);
if (!empty($trM['ok'])) {
  $tmdbMovies = array_slice(portal_tmdb_map_items((array)(($trM['data'] ?? [])['results'] ?? []), 'movie'), 0, 12);
} else {
  $tmdbMovieErr = (string)($trM['error'] ?? 'tmdb_error');
}

$trS = portal_tmdb_api('/trending/tv/week', ['page' => 1], 900);
if (!empty($trS['ok'])) {
  $tmdbSeries = array_slice(portal_tmdb_map_items((array)(($trS['data'] ?? [])['results'] ?? []), 'tv'), 0, 12);
} else {
  $tmdbSeriesErr = (string)($trS['error'] ?? 'tmdb_error');
}

// If trending items exist in your library (matched by tmdb_id), enable Play / View.
$libMoviesByTmdb = [];
if ($tmdbMovies) {
  $ids = array_values(array_unique(array_filter(array_map('intval', array_column($tmdbMovies, 'tmdb_id')))));
  if ($ids) {
    [$mvSql, $mvParams] = package_filter_sql_movies($pkg_ids, 'm');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = "SELECT m.id, m.tmdb_id FROM movies m WHERE m.tmdb_id IN ($in) {$mvSql}";
    if (!$allowAdult) $q .= " AND IFNULL(m.is_adult,0)=0";
    $st = $pdo->prepare($q);
    $st->execute(array_merge($ids, $mvParams));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $tid = (int)($r['tmdb_id'] ?? 0);
      if ($tid > 0) $libMoviesByTmdb[$tid] = (int)($r['id'] ?? 0);
    }
  }
}

$libSeriesByTmdb = [];
if ($tmdbSeries) {
  $ids = array_values(array_unique(array_filter(array_map('intval', array_column($tmdbSeries, 'tmdb_id')))));
  if ($ids) {
    [$srSql, $srParams] = package_filter_sql_series($pkg_ids, 's');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $q = "SELECT s.id, s.tmdb_id FROM series s WHERE s.tmdb_id IN ($in) {$srSql}";
    if (!$allowAdult) $q .= " AND IFNULL(s.is_adult,0)=0";
    $st = $pdo->prepare($q);
    $st->execute(array_merge($ids, $srParams));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $tid = (int)($r['tmdb_id'] ?? 0);
      if ($tid > 0) $libSeriesByTmdb[$tid] = (int)($r['id'] ?? 0);
    }
  }
}

?>

<div class="card hero">
  <h1>Welcome to XTREAM ui <span style="color:var(--accent2)">GAME CHANGER</span></h1>
  <p>Featured Live TV is pulled from your channel database. “Latest” Movies/Series are pulled from TMDB Trending (and will play if that title exists in your library).</p>

  <div class="big-buttons">
    <a class="btn primary" href="/portal/live.php">📺 Live TV</a>
    <a class="btn" href="/portal/movies.php">🎬 Movies</a>
    <a class="btn" href="/portal/series.php">📼 Series</a>
    <a class="btn ghost" href="/dashboard.php">👤 My Account</a>
  </div>
</div>

<div class="card row">
  <h2>Featured Live TV</h2>
  <div class="grid">
    <?php foreach ($channels as $c):
      [$playUrl] = portal_make_play_url((string)$user['username'], (int)$c['id'], 'live', 'm3u8');
      $logo = trim((string)($c['tvg_logo'] ?? ''));
      if ($logo === '') $logo = '/tv_icon.png';

      $catName = (string)($c['category'] ?? 'Uncategorized');
      $tvg = trim((string)($c['tvg_id'] ?? ''));
      $now = ($tvg !== '' && isset($epgNowMap[$tvg])) ? $epgNowMap[$tvg] : null;
      $nowTitle = $now ? (string)($now['title'] ?? '') : '';
      $desc = $nowTitle ? ('Now: ' . $nowTitle) : $catName;
    ?>
      <div class="tile channel js-play"
           data-play-url="<?= e($playUrl) ?>"
           data-title="<?= e($c['name']) ?>"
           data-desc="<?= e($desc) ?>">
        <img class="thumb" src="<?= e($logo) ?>" alt="">
        <div class="tpad">
          <div class="tname"><?= e($c['name']) ?></div>
          <div class="tmeta"><?= e($catName) ?></div>
          <?php if ($nowTitle): ?>
            <div class="tnow">Now: <?= e($nowTitle) ?></div>
          <?php else: ?>
            <div class="tnow muted">No EPG data</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$channels): ?>
      <div class="notice">No channels found yet. Import an M3U in admin, then come back.</div>
    <?php endif; ?>
  </div>
</div>


<div class="card row">
  <h2>Latest Movies</h2>
  <div class="grid">
    <?php foreach ($tmdbMovies as $it):
      $tid = (int)($it['tmdb_id'] ?? 0);
      $poster = trim((string)($it['poster_url'] ?? ''));
      if ($poster === '') $poster = '/tv_icon.png';

      $libId = (int)($libMoviesByTmdb[$tid] ?? 0);
      $playUrl = '';
      if ($libId > 0) {
        [$playUrl] = portal_make_play_url((string)$user['username'], $libId, 'movie', 'm3u8');
      }

      $cls = $playUrl !== '' ? 'js-play' : 'js-tmdb-open';
      $meta = trim(($it['year'] ?? '') . ($playUrl !== '' ? ' · In Library' : ' · TMDB'));
    ?>
      <div class="tile <?= $cls ?>" data-play-url="<?= e($playUrl) ?>" data-title="<?= e($it['title'] ?? '') ?>" data-desc="<?= e($it['plot'] ?? '') ?>" data-rating="<?= e($it['rating'] ?? '') ?>" data-year="<?= e($it['year'] ?? '') ?>">
        <img class="thumb" src="<?= e($poster) ?>" alt="">
        <div class="tpad">
          <div class="tname"><?= e($it['title'] ?? '') ?></div>
          <div class="tmeta"><?= e($meta) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$tmdbMovies): ?>
      <div class="notice">TMDB Movies: <?= e($tmdbMovieErr ?: 'no results') ?></div>
    <?php endif; ?>
  </div>
  <div style="margin-top:10px;"><a class="btn ghost" href="/portal/movies.php">Browse & Search Movies</a></div>
</div>

<div class="card row">
  <h2>Latest Series</h2>
  <div class="grid">
    <?php foreach ($tmdbSeries as $it):
      $tid = (int)($it['tmdb_id'] ?? 0);
      $poster = trim((string)($it['poster_url'] ?? ''));
      if ($poster === '') $poster = '/tv_icon.png';

      $libId = (int)($libSeriesByTmdb[$tid] ?? 0);
      $meta = trim(($it['year'] ?? '') . ($libId > 0 ? ' · In Library' : ' · TMDB'));
    ?>
      <?php if ($libId > 0): ?>
        <a class="tile" href="/portal/series_view.php?id=<?= (int)$libId ?>" style="text-decoration:none;color:inherit;">
          <img class="thumb" src="<?= e($poster) ?>" alt="">
          <div class="tpad">
            <div class="tname"><?= e($it['title'] ?? '') ?></div>
            <div class="tmeta"><?= e($meta) ?></div>
          </div>
        </a>
      <?php else: ?>
        <div class="tile js-tmdb-open" data-title="<?= e($it['title'] ?? '') ?>" data-desc="<?= e($it['plot'] ?? '') ?>" data-rating="<?= e($it['rating'] ?? '') ?>" data-year="<?= e($it['year'] ?? '') ?>">
          <img class="thumb" src="<?= e($poster) ?>" alt="">
          <div class="tpad">
            <div class="tname"><?= e($it['title'] ?? '') ?></div>
            <div class="tmeta"><?= e($meta) ?></div>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$tmdbSeries): ?>
      <div class="notice">TMDB Series: <?= e($tmdbSeriesErr ?: 'no results') ?></div>
    <?php endif; ?>
  </div>
  <div style="margin-top:10px;"><a class="btn ghost" href="/portal/series.php">Browse & Search Series</a></div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
