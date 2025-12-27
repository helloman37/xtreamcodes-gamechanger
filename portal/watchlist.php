<?php
require_once __DIR__ . '/_init.php';
$PORTAL_PAGE = 'watchlist';
require_once __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/lib_watchlist.php';

try {
  if (isset($pdo) && $pdo instanceof PDO) {
    wl_db_init($pdo);
    $items = wl_list($pdo, (int)$userId);
  } else {
    $items = [];
  }
} catch (Throwable $t) {
  $items = [];
}

function wl_kind_label(string $k): string {
  $k = strtolower($k);
  if ($k === 'tmdb_movie') return 'TMDB Movie';
  if ($k === 'tmdb_tv') return 'TMDB TV';
  if ($k === 'movie') return 'Movie';
  if ($k === 'series') return 'Series';
  if ($k === 'live') return 'Live';
  return strtoupper($k);
}
?>
<div class="wrap">
  <div class="hero" style="min-height:120px;">
    <div class="htext">
      <div class="htitle">Your Watchlist</div>
      <div class="hmeta"><?= e((string)count($items)) ?> item(s)</div>
    </div>
  </div>

  <div class="section">
    <div class="grid">
      <?php foreach ($items as $it):
        $kind = (string)($it['kind'] ?? '');
        $item_id = (string)($it['item_id'] ?? '');
        $title = (string)($it['title'] ?? '');
        $poster = trim((string)($it['poster'] ?? ''));
        if ($poster === '') $poster = '/tv_icon.png';

        $tileClass = 'tile js-filter';
        $attrs = '';
        $attrs .= ' data-kind="' . e($kind) . '"';
        $attrs .= ' data-id="' . e($item_id) . '"';
        $attrs .= ' data-title="' . e($title) . '"';
        $attrs .= ' data-desc="' . e(wl_kind_label($kind)) . '"';
        $attrs .= ' data-filter="' . e($title . ' ' . wl_kind_label($kind)) . '"';

        $tag = 'div';
        $href = '';
        $playUrl = '';
        $uname = (string)($user['username'] ?? '');

        if ($kind === 'movie' && ctype_digit($item_id)) {
          [$playUrl] = portal_make_play_url($uname, (int)$item_id, 'movie', 'm3u8');
          $tileClass .= ' js-play';
          $attrs .= ' data-play-url="' . e($playUrl) . '"';
        } elseif ($kind === 'live' && ctype_digit($item_id)) {
          [$playUrl] = portal_make_play_url($uname, (int)$item_id, 'live', 'm3u8');
          $tileClass .= ' js-play channel';
          $attrs .= ' data-play-url="' . e($playUrl) . '"';
        } elseif ($kind === 'series' && ctype_digit($item_id)) {
          $tag = 'a';
          $href = '/portal/series_view.php?id=' . (int)$item_id;
        } elseif ($kind === 'tmdb_movie' || $kind === 'tmdb_tv') {
          $tileClass .= ' js-tmdb-tile';
          $attrs .= ' data-tmdb-id="' . e($item_id) . '"';
        }

      ?>
        <<?= $tag ?> class="<?= e($tileClass) ?>" <?= $attrs ?> <?= $tag==='a' ? 'href="'.e($href).'" style="text-decoration:none;color:inherit;"' : '' ?>>
          <img class="thumb" src="<?= e($poster) ?>" alt="">
          <div class="tpad">
            <div class="tname"><?= e($title) ?></div>
            <div class="tmeta"><?= e(wl_kind_label($kind)) ?></div>
          </div>
        </<?= $tag ?>>
      <?php endforeach; ?>

      <?php if (!$items): ?>
        <div class="notice">No watchlist items yet. Tap the ★ on movies/series/live to add.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
require_once __DIR__ . '/_layout_bottom.php';
