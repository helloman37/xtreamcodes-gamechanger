(function(){
  const cfg = window.__watchlist || {enabled:false, api:'/portal/watchlist_api.php'};
  if (!cfg || !cfg.enabled) return;

  function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  // Use form-encoded POST for maximum shared-host compatibility (some WAFs block JSON bodies).
  async function api(payload){
    const p = payload || {};
    const body = new URLSearchParams();
    Object.keys(p).forEach(k => {
      if (p[k] === undefined || p[k] === null) return;
      body.append(k, String(p[k]));
    });
    const res = await fetch(cfg.api, {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body
    });
    const txt = await res.text();
    let j;
    try { j = JSON.parse(txt); } catch(e) { j = {ok:false,error:'bad_json',message:(txt||'').slice(0,180)}; }
    if (!j || !j.ok) throw j;
    return j;
  }

  const state = {
    loaded:false,
    set: new Set(),
    loading:false
  };

  async function loadSet(){
    if (state.loaded || state.loading) return;
    state.loading = true;
    try{
      const j = await api({action:'list'});
      const set = j.set || {};
      Object.keys(set).forEach(k => state.set.add(k));
      state.loaded = true;
    }catch(e){
      // ignore, but still allow toggles (server will create)
      state.loaded = true;
    }finally{
      state.loading = false;
    }
  }

  function getKindAndId(tile){
    // Allow overrides without breaking existing portal behavior.
    let kind = tile.getAttribute('data-wl-kind') || tile.getAttribute('data-kind') || '';
    let id = tile.getAttribute('data-wl-id') || tile.getAttribute('data-id') || '';

    const tmdbId = tile.getAttribute('data-tmdb-id') || '';
    if (tmdbId && !String(id||'').trim()){
      // TMDB tile (or hybrid tile missing a library id)
      const dk = String(tile.getAttribute('data-kind') || '').toLowerCase().trim();
      if (dk === 'tv' || dk === 'series') kind = 'tmdb_tv';
      else if (dk === 'movie') kind = 'tmdb_movie';
      else {
        const page = (document.body && document.body.getAttribute('data-page')) || '';
        kind = (page === 'series') ? 'tmdb_tv' : 'tmdb_movie';
      }
      id = tmdbId;
    }

    kind = String(kind||'').trim();
    id = String(id||'').trim();
    return {kind, id};
  }

  function getMeta(tile){
    const img = tile.querySelector('img.thumb');
    const title = tile.getAttribute('data-title') || tile.querySelector('.tname')?.textContent || '';
    const poster = (img && img.getAttribute('src')) ? img.getAttribute('src') : '';
    // For anchor tiles (series cards)
    let url = '';
    if (tile.tagName === 'A' && tile.getAttribute('href')) url = tile.getAttribute('href');
    return {title: String(title||'').trim(), poster: String(poster||'').trim(), url: String(url||'').trim()};
  }

  function ensureStar(tile){
    if (!tile || tile.__wlStarAttached) return;
    const {kind, id} = getKindAndId(tile);
    if (!kind || !id) return;

    tile.__wlStarAttached = true;

    const key = kind + ':' + id;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'wl-star' + (state.set.has(key) ? ' wl-on' : '');
    btn.title = 'Watchlist';
    btn.setAttribute('aria-label','Watchlist');
    btn.innerHTML = `<svg class="ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.24l-5 4.87L18.18 22 12 18.56 5.82 22 7 14.11 2 9.24l6.91-.98z"/></svg>`;

    btn.addEventListener('click', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      const meta = getMeta(tile);
      try{
        const j = await api({action:'toggle', kind, id, title: meta.title, poster: meta.poster, url: meta.url});
        if (j.in_watchlist){
          state.set.add(key);
          btn.classList.add('wl-on');
        } else {
          state.set.delete(key);
          btn.classList.remove('wl-on');
        }
      }catch(err){
        console.error('Watchlist API error', err);
        // Minimal user feedback (without being annoying)
        try{
          btn.classList.add('wl-bad');
          setTimeout(()=>btn.classList.remove('wl-bad'), 1200);
        }catch(e){}
      }
    }, {passive:false});

    tile.insertBefore(btn, tile.firstChild);
  }

  function scan(){
    // Library tiles: .tile with data-kind/data-id
    document.querySelectorAll('.tile').forEach(ensureStar);
    // TMDB tiles: .js-tmdb-tile
    document.querySelectorAll('.js-tmdb-tile').forEach(ensureStar);
  }

  async function init(){
    await loadSet();
    scan();

    // Watch for new TMDB tiles appended
    const grid = document.querySelector('.grid') || document.body;
    const obs = new MutationObserver(() => scan());
    obs.observe(grid, {childList:true, subtree:true});
  }

  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();