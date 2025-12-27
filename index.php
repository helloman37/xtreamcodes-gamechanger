<?php
$PUBLIC_TITLE = 'XTREAM ui GAME CHANGER — Store';
$PUBLIC_SIDEBAR = false;
require_once __DIR__ . '/gc_public_top.php';

// Global toggle (controlled from Admin -> Plans)
$trial_enabled = true;
try {
  $pdo = db();
  $trial_enabled = (system_setting_get($pdo, 'trial_enabled', '1') === '1');
} catch (Throwable $e) {
  $trial_enabled = true;
}
?>

<div class="card hero">
  <h1>Instant Activation</h1>
  <p>Pick a plan, pay, and your line opens automatically. Your playlist and login show up instantly in your account dashboard.</p>
  <div class="big-buttons">
    <a class="btn primary" href="/plans.php">View Plans</a>
    <?php if ($trial_enabled): ?>
      <a class="btn ghost" href="/trial_start.php">Start 7‑Day Trial</a>
    <?php endif; ?>
    <?php if (!empty($_SESSION['store_user'])): ?>
      <a class="btn" href="/portal/">Open Portal</a>
    <?php else: ?>
      <a class="btn" href="/login.php">Customer Login</a>
    <?php endif; ?>
  </div>

  <div class="hero-sub">
    <span class="chip">⚡ Fast activation</span>
    <span class="chip">📺 Works on any IPTV app</span>
    <span class="chip">🧾 M3U + XMLTV</span>
    <span class="chip">🔁 Renew anytime</span>
  </div>
</div>

<!-- TMDB Trending mix carousel (Movies + TV) -->
<div class="card row tmdbmix" style="margin-top:18px;">
  <div class="tmdbmix-head">
    <h2 style="margin:0;">Watch all of your favorites!</h2>
  </div>

  <div class="tmdbmix-wrap">
    <div id="tmdbMixCarousel" class="tmdb-carousel" role="region" aria-label="Rotating posters">
      <!-- lightweight placeholders (replaced by JS) -->
      <?php for ($i = 0; $i < 10; $i++): ?>
        <div class="tile tmdb-skel" aria-hidden="true">
          <div class="thumb"></div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<div class="card row" style="margin-top:18px;">
  <h2>Plans</h2>
  <?php include __DIR__ . '/plans_grid.php'; ?>
</div>

<script>
(() => {
  const scroller = document.getElementById('tmdbMixCarousel');
  if (!scroller) return;
  const state = {
    items: [],
    idx: 0,
    timer: null,
    paused: false,
  };
  const safe = (s) => (s == null ? '' : String(s));

  function tileHtml(it){
    const poster = safe(it.poster_url) || '/tv_icon.png';

    return `
      <div class="tile" aria-hidden="true">
        <img class="thumb" src="${poster}" alt="">
      </div>
    `;
  }

  function render(items){
    scroller.innerHTML = items.map(tileHtml).join('');
  }

  function tiles(){
    return Array.from(scroller.querySelectorAll('.tile'));
  }

  function scrollToIndex(i){
    const els = tiles();
    if (!els.length) return;
    state.idx = ((i % els.length) + els.length) % els.length;
    const el = els[state.idx];
    const left = el.offsetLeft - scroller.offsetLeft;
    scroller.scrollTo({ left, behavior: 'smooth' });
  }

  function next(){
    scrollToIndex(state.idx + 1);
  }
  function startAuto(){
    stopAuto();
    state.timer = window.setInterval(() => {
      if (state.paused) return;
      next();
    }, 3800);
  }
  function stopAuto(){
    if (state.timer) window.clearInterval(state.timer);
    state.timer = null;
  }

  // Pause on hover / touch
  scroller.addEventListener('mouseenter', () => { state.paused = true; });
  scroller.addEventListener('mouseleave', () => { state.paused = false; });
  scroller.addEventListener('touchstart', () => { state.paused = true; }, {passive:true});
  scroller.addEventListener('touchend', () => { state.paused = false; }, {passive:true});
  fetch('/tmdb_trending.php?limit=24', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok || !Array.isArray(j.items) || j.items.length === 0) {
        scroller.innerHTML = '<div class="notice" style="margin:10px 0;">Trending: unavailable</div>';
        return;
      }
      state.items = j.items;
      render(state.items);

      // Start from the 2nd tile so it looks alive.
      scrollToIndex(1);
      startAuto();
    })
    .catch(() => {
      scroller.innerHTML = '<div class="notice" style="margin:10px 0;">Trending: unavailable</div>';
    });
})();
</script>

<?php require_once __DIR__ . '/gc_public_bottom.php'; ?>