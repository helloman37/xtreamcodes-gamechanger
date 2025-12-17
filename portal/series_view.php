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
  <h2>Episodes</h2>
  <?php if (!$eps): ?>
    <div class="notice">No episodes found for this series yet.</div>
  <?php else: ?>
    <div class="list">
      <?php foreach ($eps as $epp):
        [$playUrl] = portal_make_play_url((string)$user['username'], (int)$epp['id'], 'episode', 'm3u8');
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
