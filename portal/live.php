<?php
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/tmdb_common.php';
$PORTAL_PAGE = 'live';
require_once __DIR__ . '/_layout_top.php';

// Categories
// Only show categories that contain at least one channel the current user is allowed to view.
// (Otherwise Adult categories show up as empty and confuse users.)
[$pkgSql, $pkgParams] = package_filter_sql($pkg_ids, 'c');

$catAdultSel = !empty($hasCatAdult) ? "IFNULL(cat.is_adult,0) AS is_adult" : "0 AS is_adult";
$catAdultGate = (!empty($hasCatAdult)) ? " AND IFNULL(cat.is_adult,0)=0 " : "";

$sqlCats = "
  SELECT cat.id, cat.name, {$catAdultSel}
  FROM categories cat
  JOIN channels c ON c.category_id = cat.id
  WHERE 1=1 {$pkgSql}
";
if (!$allowAdult) $sqlCats .= " AND IFNULL(c.is_adult,0)=0 {$catAdultGate} ";
$sqlCats .= " GROUP BY cat.id, cat.name, cat.sort_order ORDER BY cat.sort_order, cat.id";

$stCats = $pdo->prepare($sqlCats);
$stCats->execute($pkgParams);
$cats = $stCats->fetchAll(PDO::FETCH_ASSOC);

// Selected category: numeric category id OR 'all'
$selectedCat = isset($_GET['cat']) ? trim((string)$_GET['cat']) : 'all';
if ($selectedCat === '') $selectedCat = 'all';
if ($selectedCat !== 'all' && !preg_match('/^\d+$/', $selectedCat)) {
  $selectedCat = 'all';
}

// $pkgSql / $pkgParams already computed above for categories.

// Base WHERE (packages + adult gating)
$where = " WHERE 1=1 {$pkgSql}";
if (!$allowAdult) $where .= " AND IFNULL(c.is_adult,0)=0 {$catAdultGate}";

// Live TV listing behavior:
// - All Categories: show a mixed/rotating set of up to 42 channels across categories.
// - Specific category: show all channels in that category.
$channels = [];

if ($selectedCat !== 'all') {
  $cid = (int)$selectedCat;
  $sql = "SELECT c.id, c.name, c.tvg_id, c.tvg_logo, c.category_id, COALESCE(cat.name, 'Uncategorized') AS category
          FROM channels c
          LEFT JOIN categories cat ON cat.id=c.category_id
          {$where} AND IFNULL(c.category_id,0) = {$cid}
          ORDER BY c.name";
  $st = $pdo->prepare($sql);
  $st->execute($pkgParams);
  $channels = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
  // Try a true round-robin mix (1 channel per category per pass) for variety.
  // If window functions / SQL features are limited on the host, fall back to simple random LIMIT 42.
  try {
    $sqlCnt = "SELECT IFNULL(c.category_id,0) AS cid, COUNT(*) AS cnt
               FROM channels c
               LEFT JOIN categories cat ON cat.id=c.category_id
               {$where}
               GROUP BY IFNULL(c.category_id,0)";
    $stCnt = $pdo->prepare($sqlCnt);
    $stCnt->execute($pkgParams);
    $rows = $stCnt->fetchAll(PDO::FETCH_ASSOC);

    $catsWithCounts = [];
    foreach ($rows as $r) {
      $cid = (int)($r['cid'] ?? 0);
      $cnt = (int)($r['cnt'] ?? 0);
      if ($cnt > 0) $catsWithCounts[] = ['cid' => $cid, 'cnt' => $cnt];
    }

    if ($catsWithCounts) {
      // Shuffle categories for rotation each refresh.
      shuffle($catsWithCounts);

      $need = 42;
      $picked = [];
      $passes = 0;
      while (count($picked) < $need && $passes < 5) {
        foreach ($catsWithCounts as $meta) {
          if (count($picked) >= $need) break;
          $cid = (int)$meta['cid'];
          $cnt = (int)$meta['cnt'];
          if ($cnt <= 0) continue;

          // Random offset inside this category
          $off = 0;
          if ($cnt > 1) {
            $off = random_int(0, $cnt - 1);
          }

          $sqlOne = "SELECT c.id, c.name, c.tvg_id, c.tvg_logo, c.category_id, COALESCE(cat.name, 'Uncategorized') AS category
                     FROM channels c
                     LEFT JOIN categories cat ON cat.id=c.category_id
                     {$where} AND IFNULL(c.category_id,0) = {$cid}
                     ORDER BY c.id
                     LIMIT 1 OFFSET {$off}";
          $stOne = $pdo->prepare($sqlOne);
          $stOne->execute($pkgParams);
          $one = $stOne->fetch(PDO::FETCH_ASSOC);
          if (!$one) continue;
          $id = (int)($one['id'] ?? 0);
          if ($id && !isset($picked[$id])) {
            $picked[$id] = $one;
          }
        }
        $passes++;
      }
      $channels = array_values($picked);

      // Top-up to 42 if we couldn't reach it (e.g., very few categories or lots of duplicates)
      if (count($channels) < 42) {
        $sqlExtra = "SELECT c.id, c.name, c.tvg_id, c.tvg_logo, c.category_id, COALESCE(cat.name, 'Uncategorized') AS category
                     FROM channels c
                     LEFT JOIN categories cat ON cat.id=c.category_id
                     {$where}
                     ORDER BY RAND()
                     LIMIT 200";
        $stEx = $pdo->prepare($sqlExtra);
        $stEx->execute($pkgParams);
        foreach ($stEx->fetchAll(PDO::FETCH_ASSOC) as $one) {
          $id = (int)($one['id'] ?? 0);
          if (!$id || isset($picked[$id])) continue;
          $picked[$id] = $one;
          if (count($picked) >= 42) break;
        }
        $channels = array_values($picked);
      }
    }
  } catch (Throwable $e) {
    // fall through
  }

  // Fallback: simple random sample (still limited to 42)
  if (!$channels) {
    $sql = "SELECT c.id, c.name, c.tvg_id, c.tvg_logo, c.category_id, COALESCE(cat.name, 'Uncategorized') AS category
            FROM channels c
            LEFT JOIN categories cat ON cat.id=c.category_id
            {$where}
            ORDER BY RAND()
            LIMIT 42";
    $st = $pdo->prepare($sql);
    $st->execute($pkgParams);
    $channels = $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

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
      <option value="all" <?= $selectedCat === 'all' ? 'selected' : '' ?>>All Categories</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= (int)$c['id'] ?>" data-adult="<?= (int)($c['is_adult'] ?? 0) ?>" <?= ($selectedCat !== 'all' && (int)$selectedCat === (int)$c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($selectedCat === 'all'): ?>
    <div class="notice muted" style="margin:8px 0 0;">Showing a rotating mix of up to 42 channels. Pick a category to browse the full list.</div>
  <?php endif; ?>

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
