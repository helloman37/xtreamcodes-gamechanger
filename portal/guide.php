<?php
// portal/guide.php
// Native EPG Guide page (no plugin folder / install required)

require_once __DIR__ . '/_init.php';
$PORTAL_PAGE = 'guide';

// Settings (override via query string if you want)
$hours_ahead = (int)($_GET['hours_ahead'] ?? 6);
if ($hours_ahead < 2) $hours_ahead = 2;
if ($hours_ahead > 24) $hours_ahead = 24;

$channels_per_page = (int)($_GET['channels_per_page'] ?? 120);
if ($channels_per_page < 20) $channels_per_page = 20;
if ($channels_per_page > 400) $channels_per_page = 400;

$show_debug = (int)($_GET['debug'] ?? 0);

// timezone: allow browser to set ?tz=America/Chicago
if (isset($_GET['tz']) && is_string($_GET['tz']) && $_GET['tz'] !== '') {
  $_SESSION['gc_tz'] = preg_replace('~[^A-Za-z0-9_\-/]~', '', $_GET['tz']);
}
if (!empty($_SESSION['gc_tz'])) {
  @date_default_timezone_set((string)$_SESSION['gc_tz']);
}

// Time window (grid aligned to 30 minutes)
$now_real = time();
$gridStart = (int)(floor($now_real / 1800) * 1800);
$gridEnd = $gridStart + ($hours_ahead * 3600);

// Paging / filters
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $channels_per_page;

// Fetch channels (keep DB order: category sort -> channel sort)
$limit = max(1, (int)$channels_per_page);
$off   = max(0, (int)$offset);

$baseSql = "
  SELECT c.id, c.name, c.tvg_id, c.tvg_logo, c.category_id, COALESCE(cat.name,'Uncategorized') AS category
  FROM channels c
  LEFT JOIN categories cat ON cat.id=c.category_id
  WHERE 1=1
";

// Apply package restriction + adult gating so the guide never leaks adult channels.
[$pkgSql, $pkgParams] = package_filter_sql($pkg_ids, 'c');
$baseSql = rtrim($baseSql) . " {$pkgSql}";
if (!$allowAdult) {
  $baseSql .= " AND IFNULL(c.is_adult,0)=0";
  if (!empty($hasCatAdult)) {
    $baseSql .= " AND IFNULL(cat.is_adult,0)=0";
  }
}

$orderPrimary = " ORDER BY cat.sort_order ASC, c.sort_order ASC, c.id ASC";
$orderFallback = " ORDER BY cat.id ASC, c.sort_order ASC, c.id ASC";

try {
  $st = $pdo->prepare($baseSql . $orderPrimary . " LIMIT $limit OFFSET $off");
  $st->execute($pkgParams);
  $channels = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  // Older schemas (or restricted SQL modes) may not have cat.sort_order
  $st = $pdo->prepare($baseSql . $orderFallback . " LIMIT $limit OFFSET $off");
  $st->execute($pkgParams);
  $channels = $st->fetchAll(PDO::FETCH_ASSOC);
}


// Count only the channels the user is allowed to browse (packages + adult gating).
$countSql = "SELECT COUNT(*) FROM channels c LEFT JOIN categories cat ON cat.id=c.category_id WHERE 1=1 {$pkgSql}";
if (!$allowAdult) {
  $countSql .= " AND IFNULL(c.is_adult,0)=0";
  if (!empty($hasCatAdult)) {
    $countSql .= " AND IFNULL(cat.is_adult,0)=0";
  }
}
$stc = $pdo->prepare($countSql);
$stc->execute($pkgParams);
$totalChannels = (int)$stc->fetchColumn();
$totalPages = (int)max(1, ceil($totalChannels / max(1, $channels_per_page)));

$tvgIds = [];
foreach ($channels as $c) {
  $x = trim((string)($c['tvg_id'] ?? ''));
  if ($x !== '') $tvgIds[$x] = true;
}
$tvgIds = array_keys($tvgIds);

// Load EPG rows from local DB (same source as Live TV cards)
$programs = [];
$rowsLoaded = 0;

$startUtc = gmdate('Y-m-d H:i:s', $gridStart);
$endUtc   = gmdate('Y-m-d H:i:s', $gridEnd);

if ($tvgIds) {
  $chunkSize = 400;
  for ($off=0; $off<count($tvgIds); $off += $chunkSize) {
    $chunk = array_slice($tvgIds, $off, $chunkSize);
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $sql = "
      SELECT channel_xmltv_id, start_utc, stop_utc, title, descr
      FROM epg_programs
      WHERE channel_xmltv_id IN ($ph)
        AND stop_utc > ?
        AND start_utc < ?
      ORDER BY channel_xmltv_id, start_utc
    ";
    $params = array_merge($chunk, [$startUtc, $endUtc]);
    $stp = $pdo->prepare($sql);
    $stp->execute($params);
    while ($r = $stp->fetch(PDO::FETCH_ASSOC)) {
      $cid = (string)$r['channel_xmltv_id'];
      $s = strtotime((string)$r['start_utc'] . ' UTC');
      $e = strtotime((string)$r['stop_utc'] . ' UTC');
      if (!$s || !$e || $e <= $s) continue;
      $programs[$cid][] = [
        'start' => (int)$s,
        'stop'  => (int)$e,
        'title' => (string)$r['title'],
        'desc'  => (string)($r['descr'] ?? ''),
      ];
      $rowsLoaded++;
    }
  }
}

// Build ticks (every 30 minutes)
$ticks = [];
for ($t = $gridStart; $t <= $gridEnd; $t += 1800) $ticks[] = $t;

// Pixels: 180px per 30min
$px_per_sec = 180/1800;
$gridWidth = (int)(($gridEnd - $gridStart) * $px_per_sec);

require_once __DIR__ . '/_layout_top.php';
?>
<style>
.epgWrap{ padding:18px 22px; max-width:1200px; width:100%; margin:0 auto; box-sizing:border-box; }
.epgHeader{ display:flex; justify-content:space-between; gap:14px; align-items:flex-end; margin-bottom:12px; }
.epgHeader h1{ margin:0; font-size:22px; letter-spacing:.2px; }
.muted{ opacity:.75; font-size:13px; }

.epgGrid{ border:1px solid rgba(255,255,255,.08); border-radius:18px; overflow:hidden; background:rgba(0,0,0,.24);
  width:100%; max-width:100%; box-sizing:border-box; }
.epgTop{ display:flex; position:sticky; top:var(--gc-epg-top, 0px); z-index:50;
  background:rgba(0,0,0,.50); backdrop-filter: blur(10px);
  border-bottom:1px solid rgba(255,255,255,.06); }
.epgTop .left{ width:280px; flex:0 0 280px; padding:12px 14px; border-right:1px solid rgba(255,255,255,.06); }
.epgTop .time{ flex:1; overflow:hidden; min-width:0; }
.timeRow{ position:relative; height:44px; }
.timeRow:before{ content:""; position:absolute; inset:0;
  background-image: repeating-linear-gradient(to right, rgba(255,255,255,.06) 0, rgba(255,255,255,.06) 1px, transparent 1px, transparent 180px);
  opacity:.7; pointer-events:none; }
.tick{ position:absolute; top:0; bottom:0; width:180px; padding:12px 0 0 10px; font-weight:600; font-size:12px; color:rgba(255,255,255,.85); }
.tick:after{ content:""; position:absolute; left:0; top:0; bottom:0; width:1px; background:rgba(255,255,255,.06); }
.nowLine{ position:absolute; top:0; bottom:0; width:2px; background:rgba(255,80,80,.95);
  box-shadow:0 0 10px rgba(255,80,80,.35); pointer-events:none; }

.epgScroll{ overflow-x:auto; overflow-y:hidden; width:100%; max-width:100%; display:block; box-sizing:border-box; }
.epgRow{ display:flex; border-bottom:1px solid rgba(255,255,255,.05); }
.epgRow:last-child{ border-bottom:none; }

.epgChan{ width:280px; flex:0 0 280px; padding:10px 14px; display:flex; gap:12px; align-items:center;
  border-right:1px solid rgba(255,255,255,.06); background:rgba(0,0,0,.10);
  position:sticky; left:0; z-index:30; }
.epgChan img{ width:44px; height:44px; object-fit:contain; border-radius:12px; background:rgba(255,255,255,.06); padding:6px; }
.epgChan .name{ font-weight:800; line-height:1.12; font-size:14px; }
.epgChan .cat{ font-size:12px; opacity:.72; margin-top:2px; }

.epgLine{ flex:1; overflow:hidden; min-width:0; }

.epgInner{ width:100%; box-sizing:border-box; }
.epgLineInner{ position:relative; height:68px;
  background-image: repeating-linear-gradient(to right, rgba(255,255,255,.05) 0, rgba(255,255,255,.05) 1px, transparent 1px, transparent 180px); }
.epgProg{ position:absolute; top:10px; bottom:10px; padding:10px 12px; border-radius:16px;
  background:rgba(255,255,255,.10); border:1px solid rgba(255,255,255,.10); cursor:pointer; overflow:hidden;  box-sizing:border-box; }
.epgProg:hover{ background:rgba(255,255,255,.12); }
.epgProg .t{ font-weight:800; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.epgProg .b{ font-size:12px; opacity:.75; margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.epgEmpty{ opacity:.7; font-size:13px; line-height:68px; padding-left:12px; }

.epgPager{ display:flex; justify-content:space-between; align-items:center; margin-top:14px; gap:10px; }
.epgPager a{ padding:8px 12px; border:1px solid rgba(255,255,255,.10); border-radius:12px; text-decoration:none; color:inherit; }
.epgPager a:hover{ background:rgba(255,255,255,.06); }

.epgScroll::-webkit-scrollbar{ height:10px; }
.epgScroll::-webkit-scrollbar-thumb{ background:rgba(255,255,255,.12); border-radius:20px; }
.epgScroll::-webkit-scrollbar-track{ background:rgba(255,255,255,.04); }

@media (max-width: 900px){
  .epgWrap{ padding:12px 12px; }
  .epgTop .left, .epgChan{ width:240px; flex-basis:240px; }
}
</style>

<div class="epgWrap">
  <div class="epgHeader">
    <div>
      <h1>EPG Guide</h1>
      <div class="muted">
        Now: <span id="jsNow"><?= e(date('D M j, Y g:ia', $now_real)) ?></span> • Grid: <?= e(date('g:ia', $gridStart)) ?> → <?= e(date('g:ia', $gridEnd)) ?>
      </div>
    </div>
    <?php if ($show_debug): ?>
      <div class="muted">Rows: <?= (int)$rowsLoaded ?> • Channels on page: <?= (int)count($channels) ?> • With tvg_id: <?= (int)count($tvgIds) ?></div>
    <?php endif; ?>
  </div>

  <div class="epgGrid">
    <div class="epgTop">
      <div class="left muted">Channel</div>
      <div class="time">
        <div class="timeRow" id="epgTimeRow" style="min-width:<?= (int)$gridWidth ?>px;">
          <?php
            foreach ($ticks as $t):
              $left = (int)(($t - $gridStart) * $px_per_sec);
          ?>
            <div class="tick" style="left:<?= $left ?>px;"><?= e(date('g:ia', $t)) ?></div>
          <?php endforeach; ?>
          <?php
            $nowLeft = (int)(($now_real - $gridStart) * $px_per_sec);
            if ($nowLeft >= 0 && $nowLeft <= (int)(($gridEnd - $gridStart) * $px_per_sec)) :
          ?>
            <div class="nowLine" style="left:<?= $nowLeft ?>px;"></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="epgScroll" id="epgScroll"><div class="epgInner" style="min-width:<?= (int)($gridWidth + 280) ?>px;">

    <?php foreach ($channels as $ch):
      $xid = trim((string)($ch['tvg_id'] ?? ''));
      $plist = ($xid !== '' && isset($programs[$xid])) ? $programs[$xid] : [];
      [$playUrl] = portal_make_play_url((string)$user['username'], (int)$ch['id'], 'live', 'm3u8');
      $logo = trim((string)($ch['tvg_logo'] ?? ''));
      if ($logo === '') $logo = '/tv_icon.png';
    ?>
      <div class="epgRow">
        <div class="epgChan">
          <img src="<?= e($logo) ?>" alt="">
          <div>
            <div class="name"><?= e($ch['name']) ?></div>
            <div class="cat"><?= e($ch['category']) ?></div>
          </div>
        </div>
        <div class="epgLine"><div class="epgLineInner" style="width:<?= (int)$gridWidth ?>px;">
          <?php
            if (!$plist) {
              echo '<div class="epgEmpty">No EPG</div>';
            } else {
              usort($plist, function($a,$b){ return ($a['start'] ?? 0) <=> ($b['start'] ?? 0); });
              foreach ($plist as $p) {
                $s = max($gridStart, (int)$p['start']);
                $e = min($gridEnd, (int)$p['stop']);
                if ($e <= $s) continue;
                $left = ($s - $gridStart) * $px_per_sec;
                $width = max(24, ($e - $s) * $px_per_sec);
                $title = (string)($p['title'] ?? '');
                $desc = (string)($p['desc'] ?? '');
                $badge = date('g:ia', (int)$p['start']) . ' - ' . date('g:ia', (int)$p['stop']);
          ?>
              <div class="epgProg js-play"
                   style="left:<?= (int)$left ?>px; width:<?= (int)$width ?>px;"
                   data-play-url="<?= e($playUrl) ?>"
                   data-title="<?= e($ch['name']) ?>"
                   data-desc="<?= e($title . ' • ' . $badge . ($desc ? "\n\n".$desc : '')) ?>">
                <div class="t"><?= e($title) ?></div>
                <div class="b"><?= e($badge) ?></div>
              </div>
          <?php } } ?>
        </div></div>
      </div>
    <?php endforeach; ?>
    </div></div>
  </div>

  <div class="epgPager">
    <div class="muted">Page <?= (int)$page ?> / <?= (int)$totalPages ?> • Channels <?= (int)$offset+1 ?>-<?= (int)min($offset+count($channels), $totalChannels) ?> of <?= (int)$totalChannels ?></div>
    <div style="display:flex; gap:10px;">
      <?php if ($page > 1): ?><a href="/portal/guide/?p=<?= (int)($page-1) ?>">Prev</a><?php endif; ?>
      <?php if ($page < $totalPages): ?><a href="/portal/guide/?p=<?= (int)($page+1) ?>">Next</a><?php endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
  // Auto-detect browser timezone once (so PHP dates match the client)
  try {
    var url = new URL(window.location.href);
    if (!url.searchParams.get('tz')) {
      var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (tz) {
        url.searchParams.set('tz', tz);
        window.location.replace(url.toString());
        return;
      }
    }
  } catch(e) {}

  // Keep the "Now" label + red now-line moving without refreshing the page
  const __gridStart = <?= (int)$gridStart ?>;
  const __gridEnd   = <?= (int)$gridEnd ?>;
  const __pxPerSec  = <?= json_encode($px_per_sec) ?>;
  const __gridWidth = Math.round((__gridEnd - __gridStart) * __pxPerSec);

  function __updateNow() {
    const now = Math.floor(Date.now() / 1000);
    const left = Math.round((now - __gridStart) * __pxPerSec);

    const line = document.querySelector('.nowLine');
    if (line) {
      if (left < 0 || left > __gridWidth) {
        line.style.display = 'none';
      } else {
        line.style.display = '';
        line.style.left = left + 'px';
      }
    }

    const lbl = document.getElementById('jsNow');
    if (lbl) {
      try {
        lbl.textContent = new Date().toLocaleString(undefined, {
          weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
          hour: 'numeric', minute: '2-digit'
        });
      } catch(e) {}
    }
  }

  __updateNow();
  setInterval(__updateNow, 30000);

  const sc = document.getElementById('epgScroll');
  const tr = document.getElementById('epgTimeRow');
  if (sc && tr){
    const sync = () => { tr.style.transform = 'translateX(' + (-sc.scrollLeft) + 'px)'; };
    sc.addEventListener('scroll', sync, { passive:true });
    sync();
  }

  function calcTop(){
    // detect fixed/sticky top bars and place the sticky timebar directly under them (no extra gap)
    let maxBottom = 0;

    const selectors = [
      '.portalTop', '.topbar', '.topBar', '.gcTopNav', '.navbar', '.top-nav', '.topnav', 'header', 'nav'
    ];

    for (const sel of selectors){
      const els = document.querySelectorAll(sel);
      if (!els || !els.length) continue;
      els.forEach(el => {
        try{
          const cs = window.getComputedStyle(el);
          if (cs.position !== 'fixed' && cs.position !== 'sticky') return;
          const r = el.getBoundingClientRect();
          // must be at/near the top; ignore huge containers
          if (r.top <= 0 && r.bottom > maxBottom && r.bottom < 180){
            maxBottom = r.bottom;
          }
        }catch(e){}
      });
      if (maxBottom) break;
    }

    document.documentElement.style.setProperty('--gc-epg-top', (Math.max(0, Math.round(maxBottom))) + 'px');
  }
  calcTop();
  window.addEventListener('resize', calcTop);
})();
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
