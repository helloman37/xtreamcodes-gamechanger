<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/tmdb_common.php';
$PORTAL_PAGE = 'live';
require_once __DIR__ . '/_layout_top.php';

// Categories
$cats = $pdo->query("SELECT id, name, IFNULL(is_adult,0) AS is_adult FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

[$pkgSql, $pkgParams] = package_filter_sql($pkg_ids, 'c');
$sql = "SELECT c.id, c.name, c.tvg_id, c.tvg_logo, c.category_id, COALESCE(cat.name, 'Uncategorized') AS category
        FROM channels c
        LEFT JOIN categories cat ON cat.id=c.category_id
        WHERE 1=1 {$pkgSql}";
if (!$allowAdult) $sql .= " AND IFNULL(c.is_adult,0)=0 AND IFNULL(cat.is_adult,0)=0";
$sql .= " ORDER BY cat.name, c.name";

$st = $pdo->prepare($sql);
$st->execute($pkgParams);

$channels = $st->fetchAll(PDO::FETCH_ASSOC);

// EPG "Now" titles (batch by tvg_id)
$epgNowMap = [];
try {
  $tvgIds = [];
  foreach ($channels as $c) {
    $tid = trim((string)($c['tvg_id'] ?? ''));
    if ($tid !== '') $tvgIds[$tid] = true;
  }
  $tvgIds = array_keys($tvgIds);

  $chunkSize = 400;
  for ($off = 0; $off < count($tvgIds); $off += $chunkSize) {
    $chunk = array_slice($tvgIds, $off, $chunkSize);
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
      $cid = (string)$r['channel_xmltv_id'];
      $epgNowMap[$cid] = $r;
    }
  }
} catch (Throwable $e) {
  // Ignore EPG failures; portal still works without EPG.
}


$hero = portal_tmdb_pick_hero('tv');
$heroBg = (!empty($hero['ok']) && !empty($hero['backdrop_url'])) ? (string)$hero['backdrop_url'] : '';
$heroTitle = (!empty($hero['ok']) && !empty($hero['title'])) ? (string)$hero['title'] : '';
$heroYear  = (!empty($hero['ok']) && !empty($hero['year']))  ? (string)$hero['year']  : '';

?>

<div class="card hero <?= $heroBg ? 'tmdb' : '' ?>" <?= $heroBg ? 'style="--hero-bg:url(\'' . e($heroBg) . '\')"' : '' ?>>
  <h1>Live TV</h1>
  <p>Search, filter by category, click to play. Playback uses secure token URLs so the password never shows.</p>
  <?php if ($heroTitle): ?>
    <div class="hero-sub">
      <span class="chip">Trending TV: <b><?= e($heroTitle) ?></b><?= $heroYear ? ' · ' . e($heroYear) : '' ?></span>
    </div>
  <?php endif; ?>
</div>

<div class="card row">
  <div class="toolbar">
    <input id="q" class="input" placeholder="Search channels..." style="min-width:240px;">
    <select id="cat" class="input select">
      <option value="all">All Categories</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= (int)$c['id'] ?>" data-adult="<?= (int)($c['is_adult'] ?? 0) ?>"><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="grid channel-grid">
    <?php foreach ($channels as $c):
      [$playUrl] = portal_make_play_url((string)$user['username'], (int)$c['id'], 'live', 'm3u8');
      $logo = trim((string)($c['tvg_logo'] ?? ''));
      if ($logo === '') $logo = '/tv_icon.png';
      $catName = (string)($c['category'] ?? 'Uncategorized');
      $tvg = trim((string)($c['tvg_id'] ?? ''));
      $now = ($tvg !== '' && isset($epgNowMap[$tvg])) ? $epgNowMap[$tvg] : null;
      $nowTitle = $now ? (string)($now['title'] ?? '') : '';
      $filter = trim((string)$c['name'] . ' ' . $catName . ' ' . $nowTitle);
      $desc = $nowTitle ? ('Now: ' . $nowTitle) : $catName;
    ?>
      <div class="tile channel js-play js-filter"
           data-kind="live" data-id="<?= (int)$c['id'] ?>"
           data-cat="<?= (int)($c['category_id'] ?? 0) ?>"
           data-filter="<?= e($filter) ?>"
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
      <div class="notice">No channels found.</div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
