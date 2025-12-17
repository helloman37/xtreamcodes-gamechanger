<?php
require_once __DIR__ . '/_init.php';
$PORTAL_PAGE = 'movies';
require_once __DIR__ . '/tmdb_common.php';
require_once __DIR__ . '/_layout_top.php';

$cats = $pdo->query("SELECT id, name FROM vod_categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

[$mvSql, $mvParams] = package_filter_sql_movies($pkg_ids, 'm');
$sql = "SELECT m.id, m.name, m.poster_url, m.plot, m.release_date, m.rating, m.tmdb_id, m.category_id, COALESCE(vc.name,'Uncategorized') AS cat_name
        FROM movies m
        LEFT JOIN vod_categories vc ON vc.id=m.category_id
        WHERE 1=1 {$mvSql}";
if (!$allowAdult) $sql .= " AND IFNULL(m.is_adult,0)=0";
$sql .= " ORDER BY vc.name, m.name";

$st = $pdo->prepare($sql);
$st->execute($mvParams);
$movies = $st->fetchAll(PDO::FETCH_ASSOC);

$hero = portal_tmdb_pick_hero('movie');
$heroBg = (!empty($hero['ok']) && !empty($hero['backdrop_url'])) ? (string)$hero['backdrop_url'] : '';
$heroTitle = (!empty($hero['ok']) && !empty($hero['title'])) ? (string)$hero['title'] : '';
$heroYear  = (!empty($hero['ok']) && !empty($hero['year']))  ? (string)$hero['year']  : '';
$heroRating = (!empty($hero['ok']) && !empty($hero['rating'])) ? (string)$hero['rating'] : '';

?>

<div class="card hero <?= $heroBg ? 'tmdb' : '' ?>" <?= $heroBg ? 'style="--hero-bg:url(\'' . e($heroBg) . '\')"' : '' ?>>
  <h1>Movies</h1>
  <p>Search and explore movies.</p>
  <?php if ($heroTitle): ?>
    <div class="hero-sub">
      <span class="chip">Trending Movie: <b><?= e($heroTitle) ?></b><?= $heroYear ? ' · ' . e($heroYear) : '' ?><?= $heroRating ? ' · ⭐ ' . e($heroRating) : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<div class="card row">
  <div class="toolbar">
    <div class="seg" id="modeSeg">
      <button class="segbtn on" type="button" data-mode="tmdb">TMDB</button>
      <button class="segbtn" type="button" data-mode="library">Library</button>
    </div>

    <input id="q" class="input" placeholder="Search TMDB movies..." style="min-width:240px;">

    <select id="tmdb_mode" class="input select">
      <option value="trending">Trending</option>
      <option value="popular">Popular</option>
      <option value="top">Top Rated</option>
    </select>

    <select id="cat" class="input select" style="display:none;">
      <option value="all">All Categories</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div id="tmdbPanel">
    <div class="grid" id="tmdbGrid"></div>
    <div class="pager"><button class="btn ghost" type="button" id="tmdbMore">Load more</button></div>
    <div class="notice" id="tmdbErr" style="display:none"></div>
  </div>

  <div id="libraryPanel" style="display:none;">
    <div class="grid">
      <?php foreach ($movies as $m):
        [$playUrl] = portal_make_play_url((string)$user['username'], (int)$m['id'], 'movie', 'm3u8');
        $poster = trim((string)($m['poster_url'] ?? ''));
        $missing = ($poster === '' && !empty($m['tmdb_id']));
        if ($poster === '') $poster = '/tv_icon.png';
      ?>
        <div class="tile js-play js-filter <?= $missing ? 'js-tmdb-missing' : '' ?>"
             data-kind="movie" data-id="<?= (int)$m['id'] ?>"
             data-cat="<?= (int)($m['category_id'] ?? 0) ?>"
             data-filter="<?= e($m['name'].' '.$m['cat_name']) ?>"
             data-play-url="<?= e($playUrl) ?>"
             data-title="<?= e($m['name']) ?>"
             data-desc="<?= e($m['plot'] ?? '') ?>"
             data-rating="<?= e($m['rating'] ?? '') ?>"
             data-year="<?= e(substr((string)($m['release_date'] ?? ''),0,4)) ?>">
          <img class="thumb" src="<?= e($poster) ?>" alt="">
          <div class="tpad">
            <div class="tname"><?= e($m['name']) ?></div>
            <div class="tmeta"><?= e($m['cat_name'] ?? 'Uncategorized') ?> · <?= e(substr((string)($m['release_date'] ?? ''),0,4)) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$movies): ?>
        <div class="notice">No movies in your library yet.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
