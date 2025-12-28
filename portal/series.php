<?php
require_once __DIR__ . '/_init.php';
$PORTAL_PAGE = 'series';
require_once __DIR__ . '/tmdb_common.php';
require_once __DIR__ . '/_layout_top.php';

[$srSql, $srParams] = package_filter_sql_series($pkg_ids, 's');

// Categories (only ones with at least one allowed series)
$sqlCats = "SELECT sc.id, sc.name
            FROM series_categories sc
            JOIN series s ON s.category_id = sc.id
            WHERE 1=1 {$srSql}";
if (!$allowAdult) $sqlCats .= " AND IFNULL(s.is_adult,0)=0";
$sqlCats .= " GROUP BY sc.id, sc.name ORDER BY sc.name";
$stCats = $pdo->prepare($sqlCats);
$stCats->execute($srParams);
$cats = $stCats->fetchAll(PDO::FETCH_ASSOC);
$sql = "SELECT s.id, s.name, s.cover_url, s.plot, s.release_date, s.rating, s.tmdb_id, s.category_id, COALESCE(sc.name,'Uncategorized') AS cat_name
        FROM series s
        LEFT JOIN series_categories sc ON sc.id=s.category_id
        WHERE 1=1 {$srSql}";
if (!$allowAdult) $sql .= " AND IFNULL(s.is_adult,0)=0";
$sql .= " ORDER BY sc.name, s.name";

$st = $pdo->prepare($sql);
$st->execute($srParams);
$series = $st->fetchAll(PDO::FETCH_ASSOC);

$hero = portal_tmdb_pick_hero('tv');
$heroBg = (!empty($hero['ok']) && !empty($hero['backdrop_url'])) ? (string)$hero['backdrop_url'] : '';
$heroTitle = (!empty($hero['ok']) && !empty($hero['title'])) ? (string)$hero['title'] : '';
$heroYear  = (!empty($hero['ok']) && !empty($hero['year']))  ? (string)$hero['year']  : '';
$heroRating = (!empty($hero['ok']) && !empty($hero['rating'])) ? (string)$hero['rating'] : '';
?>

<div class="card hero <?= $heroBg ? 'tmdb' : '' ?>" <?= $heroBg ? 'style="--hero-bg:url(\'' . e($heroBg) . '\')"' : '' ?>>
  <h1>Series</h1>
  <p>Search and explore series.</p>
  <?php if ($heroTitle): ?>
    <div class="hero-sub">
      <span class="chip">Trending Series: <b><?= e($heroTitle) ?></b><?= $heroYear ? ' · ' . e($heroYear) : '' ?><?= $heroRating ? ' · ⭐ ' . e($heroRating) : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<div class="card row">
  <div class="toolbar">
    <div class="seg" id="modeSeg">
      <button class="segbtn on" type="button" data-mode="tmdb">TMDB</button>
      <button class="segbtn" type="button" data-mode="library">Library</button>
    </div>

    <input id="q" class="input" placeholder="Search TMDB series..." style="min-width:240px;">

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
      <?php foreach ($series as $s):
        $cover = trim((string)($s['cover_url'] ?? ''));
        $missing = ($cover === '' && !empty($s['tmdb_id']));
        if ($cover === '') $cover = '/tv_icon.png';
      ?>
        <a class="tile js-filter <?= $missing ? 'js-tmdb-missing' : '' ?>"
           data-kind="series" data-id="<?= (int)$s['id'] ?>"
           data-cat="<?= (int)($s['category_id'] ?? 0) ?>"
           data-filter="<?= e($s['name'].' '.$s['cat_name']) ?>"
           href="/portal/series_view.php?id=<?= (int)$s['id'] ?>" style="text-decoration:none;color:inherit;">
          <img class="thumb" src="<?= e($cover) ?>" alt="">
          <div class="tpad">
            <div class="tname"><?= e($s['name']) ?></div>
            <div class="tmeta"><?= e($s['cat_name'] ?? 'Uncategorized') ?> · <?= e(substr((string)($s['release_date'] ?? ''),0,4)) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (!$series): ?>
        <div class="notice">No series in your library yet.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
