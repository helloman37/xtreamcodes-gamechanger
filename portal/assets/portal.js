(function(){
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));

  const PAGE = (document.body && document.body.getAttribute('data-page')) || '';

  // DEFAULT_MODE_BY_PAGE
  if (!window.__portalMode) {
    window.__portalMode = (PAGE === 'movies' || PAGE === 'series') ? 'tmdb' : 'library';
  }

  function debounce(fn, ms){
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  // ---- jPlayer (with HLS.js bridge) ----
  let jpInited = false;
  let jpHls = null;

  function hasJp(){
    return !!(window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.jPlayer === 'function');
  }

  function jpInit(){
    if (jpInited) return true;
    if (!hasJp()) return false;
    try {
      window.jQuery("#jquery_jplayer").jPlayer({
        supplied: "m3u8, m4v, webmv, ogv, mp3",
        solution: "html",
        cssSelectorAncestor: "#jp_container",
        size: { width: "100%", height: "100%" },
        useStateClassSkin: true,
        autoBlur: false,
        smoothPlayBar: true,
        keyEnabled: true,
        preload: "metadata",
        muted: false,
        errorAlerts: false,
        warningAlerts: false
      });
      jpInited = true;
      return true;
    } catch(e) {
      return false;
    }
  }

  function jpStop(){
    try {
      if (jpHls) { try { jpHls.destroy(); } catch(e) {} jpHls = null; }
      if (hasJp()) {
        window.jQuery("#jquery_jplayer").jPlayer("stop");
        window.jQuery("#jquery_jplayer").jPlayer("clearMedia");
      }
    } catch(e) {}
  }

  function jpPlay(url, title){
    if (!jpInit()) return false;

    const lower = (url || '').toLowerCase();
    const media = { title: title || '' };
    if (lower.includes('.m3u8') || lower.includes('m3u8')) media.m3u8 = url;
    else if (lower.includes('.mp4') || lower.includes('.m4v')) media.m4v = url;
    else if (lower.includes('.webm')) media.webmv = url;
    else if (lower.includes('.ogv') || lower.includes('.ogg')) media.ogv = url;
    else if (lower.includes('.mp3')) media.mp3 = url;
    else media.m3u8 = url; // assume HLS proxy

    jpStop();
    window.jQuery("#jquery_jplayer").jPlayer("setMedia", media).jPlayer("play");

    // HLS bridge for browsers that cannot play m3u8 natively
    setTimeout(() => {
      try {
        const jp = window.jQuery("#jquery_jplayer").data("jPlayer");
        const videoEl = jp && jp.htmlElement && jp.htmlElement.video;
        if (!videoEl) return;

        const isHls = !!media.m3u8;
        if (!isHls) return;

        if (window.Hls && window.Hls.isSupported() && !videoEl.canPlayType("application/vnd.apple.mpegurl")) {
          jpHls = new window.Hls({ lowLatencyMode: true, backBufferLength: 30 });
          jpHls.loadSource(url);
          jpHls.attachMedia(videoEl);
          videoEl.play && videoEl.play().catch(()=>{});
        }
      } catch(e) {}
    }, 0);
    return true;
  }

  function modal(){
    return document.getElementById('portalModal');
  }

  function openModal({title, desc, badges, url}){
    const m = modal();
    if (!m) return;
    m.classList.add('on');
    $('.js-modal-title', m).textContent = title || 'Now Playing';
    const d = $('.js-modal-desc', m);
    d.textContent = desc || '';

    const b = $('.js-modal-badges', m);
    b.innerHTML = '';
    (badges || []).forEach(x => {
      const span = document.createElement('span');
      span.className = 'badge ' + (x.kind || '');
      span.textContent = x.text || '';
      b.appendChild(span);
    });

    const player = document.getElementById('jp_container');
    if (!url) {
      // Info-only modal (TMDB browse)
      jpStop();
      if (player) player.style.display = 'none';
      return;
    }
    if (player) player.style.display = '';
    const ok = jpPlay(url, title || 'Now Playing');
    if (!ok) {
      // If CDN blocks jPlayer, at least show a friendly message
      d.textContent = (desc ? (desc + "\n\n") : "") + "Player library not loaded (jPlayer). Check server outbound access or include local jPlayer files.";
    }
  }

  function closeModal(){
    const m = modal();
    if (!m) return;
    jpStop();
    m.classList.remove('on');
  }

  function wireClicks(){
    $$('.js-play').forEach(el => {
      el.addEventListener('click', () => {
        const url = el.getAttribute('data-play-url') || '';
        if (!url) return;
        const title = el.getAttribute('data-title') || 'Now Playing';
        const desc = el.getAttribute('data-desc') || '';
        const badges = [];
        const rating = el.getAttribute('data-rating');
        if (rating) badges.push({text: '★ ' + rating, kind:'good'});
        const year = el.getAttribute('data-year');
        if (year) badges.push({text: year});
        openModal({title, desc, badges, url});
      });
    });

    const m = modal();
    if (m) {
      $('.js-modal-close', m)?.addEventListener('click', closeModal);
      m.addEventListener('click', (e) => {
        if (e.target === m) closeModal();
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeModal();
      });
    }
  }

  function wireTmdbOpens(){
    $$('.js-tmdb-open').forEach(el => {
      if (el.__tmdbWired) return;
      el.__tmdbWired = true;
      el.addEventListener('click', () => {
        const title = el.getAttribute('data-title') || 'TMDB';
        const desc = el.getAttribute('data-desc') || '';
        const badges = [];
        const rating = el.getAttribute('data-rating');
        if (rating) badges.push({text: '★ ' + rating, kind:'good'});
        const year = el.getAttribute('data-year');
        if (year) badges.push({text: year});
        badges.push({text:'TMDB'});
        openModal({title, desc, badges, url: ''});
      });
    });
  }

  async function enrichTmdb(){
    const nodes = $$('.js-tmdb-missing');
    if (!nodes.length) return;

    // group by kind
    const byKind = {};
    nodes.forEach(n => {
      const kind = n.getAttribute('data-kind');
      const id = n.getAttribute('data-id');
      if (!kind || !id) return;
      (byKind[kind] ||= []).push(id);
    });

    for (const kind of Object.keys(byKind)) {
      const ids = byKind[kind].slice(0, 80).join(',');
      try {
        const res = await fetch('tmdb_enrich.php?kind=' + encodeURIComponent(kind) + '&ids=' + encodeURIComponent(ids), {
          credentials: 'same-origin'
        });
        if (!res.ok) continue;
        const json = await res.json();
        if (!json || !json.items) continue;
        json.items.forEach(it => {
          const n = document.querySelector('.js-tmdb-missing[data-kind="' + kind + '"][data-id="' + it.id + '"]');
          if (!n) return;
          const img = n.querySelector('img');
          if (img && it.poster_url) img.src = it.poster_url;
          const desc = n.getAttribute('data-desc');
          if (!desc && it.plot) n.setAttribute('data-desc', it.plot);
          if (it.rating) n.setAttribute('data-rating', it.rating);
          if (it.release_date) n.setAttribute('data-year', (it.release_date + '').slice(0,4));
          n.classList.remove('js-tmdb-missing');
        });
      } catch(e) {}
    }
  }

  function wireSearch(){
    const q = document.getElementById('q');
    if (!q) return;

    function applyLocalFilters(){
      const s = (q.value || '').trim().toLowerCase();
      const cat = document.getElementById('cat');
      const v = cat ? (cat.value || '') : '';
      $$('.js-filter').forEach(el => {
        const t = (el.getAttribute('data-filter') || '').toLowerCase();
        const c = el.getAttribute('data-cat') || '';
        const okText = (!s || t.includes(s));
        const okCat  = (!v || v === 'all' || c === v);
        el.style.display = (okText && okCat) ? '' : 'none';
      });
    }
    // In TMDB mode, search is remote; in library mode it filters local tiles.
    q.addEventListener('input', () => {
      const mode = window.__portalMode || 'tmdb';
      if (mode === 'tmdb') {
        window.__portalTmdbQuery = q.value.trim();
        window.__portalTmdbPage = 1;
        fetchTmdb(true);
        return;
      }
      applyLocalFilters();
    });

    const cat = document.getElementById('cat');
    if (cat) {
      cat.addEventListener('change', () => applyLocalFilters());
    }
  }

  function tmdbTile(it){
    const div = document.createElement('div');
    div.className = 'tile js-tmdb-tile';
    div.setAttribute('data-title', it.title || '');
    div.setAttribute('data-desc', it.plot || '');
    div.setAttribute('data-rating', it.rating || '');
    div.setAttribute('data-year', it.year || '');
    div.innerHTML = `
      <img class="thumb" src="${it.poster_url || '/tv_icon.png'}" alt="">
      <div class="tpad">
        <div class="tname">${escapeHtml(it.title || '')}</div>
        <div class="tmeta">${escapeHtml((it.type || '').toUpperCase())} · ${escapeHtml(it.year || '')}</div>
      </div>
    `;
    div.addEventListener('click', () => {
      const badges = [];
      if (it.rating) badges.push({text: '★ ' + it.rating, kind:'good'});
      if (it.year) badges.push({text: it.year});
      badges.push({text: 'TMDB', kind: ''});
      openModal({title: it.title, desc: it.plot, badges, url: ''});
    });
    return div;
  }

  function escapeHtml(s){
    return String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  const fetchTmdb = debounce(async function(reset){
    const grid = document.getElementById('tmdbGrid');
    if (!grid) return;
    const err = document.getElementById('tmdbErr');
    if (err) { err.style.display = 'none'; err.textContent = ''; }

    const kind = PAGE === 'series' ? 'tv' : 'movie';
    const q = (window.__portalTmdbQuery || '').trim();
    const modeSel = document.getElementById('tmdb_mode');
    const mode = (modeSel && modeSel.value) ? modeSel.value : 'trending';
    let page = window.__portalTmdbPage || 1;
    if (reset) page = 1;
    if (reset) grid.innerHTML = '';

    let url;
    if (q) {
      url = `tmdb_search.php?type=${encodeURIComponent(kind)}&q=${encodeURIComponent(q)}&page=${page}`;
    } else {
      url = `tmdb_browse.php?type=${encodeURIComponent(kind)}&mode=${encodeURIComponent(mode)}&page=${page}`;
    }

    try {
      const res = await fetch(url, {credentials:'same-origin'});
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json || json.ok !== true) {
        const msg = (json && json.error) ? json.error : ('HTTP ' + res.status);
        if (err) { err.style.display = ''; err.textContent = 'TMDB error: ' + msg; }
        return;
      }
      const items = Array.isArray(json.items) ? json.items : [];
      items.forEach(it => grid.appendChild(tmdbTile(it)));
      window.__portalTmdbPage = page;
    } catch(e) {
      if (err) { err.style.display = ''; err.textContent = 'TMDB error: ' + (e && e.message ? e.message : 'network'); }
    }
  }, 250);

  function wireTmdb(){
    const seg = document.getElementById('modeSeg');
    const lib = document.getElementById('libraryPanel');
    const tmdb = document.getElementById('tmdbPanel');
    const cat = document.getElementById('cat');
    const tmode = document.getElementById('tmdb_mode');
    const q = document.getElementById('q');
    const more = document.getElementById('tmdbMore');

    if (!tmdb) return;
    window.__portalMode = 'tmdb';
    window.__portalTmdbQuery = '';
    window.__portalTmdbPage = 1;

    function setMode(m){
      window.__portalMode = m;
      if (seg) {
        $$('.segbtn', seg).forEach(b => b.classList.toggle('on', (b.getAttribute('data-mode')||'') === m));
      }
      if (m === 'tmdb') {
        if (tmdb) tmdb.style.display = '';
        if (lib) lib.style.display = 'none';
        if (cat) cat.style.display = 'none';
        if (tmode) tmode.style.display = '';
        if (q) q.placeholder = PAGE === 'series' ? 'Search TMDB series...' : 'Search TMDB movies...';
        fetchTmdb(true);
      } else {
        if (tmdb) tmdb.style.display = 'none';
        if (lib) lib.style.display = '';
        if (cat) cat.style.display = '';
        if (tmode) tmode.style.display = 'none';
        if (q) q.placeholder = PAGE === 'series' ? 'Search series...' : 'Search movies...';
      }
    }

    if (seg) {
      seg.addEventListener('click', (e) => {
        const b = e.target && e.target.closest && e.target.closest('.segbtn');
        if (!b) return;
        const m = b.getAttribute('data-mode');
        if (!m) return;
        setMode(m);
      });
    }

    if (tmode) {
      tmode.addEventListener('change', () => {
        if (window.__portalMode === 'tmdb') { window.__portalTmdbPage = 1; fetchTmdb(true); }
      });
    }

    if (more) {
      more.addEventListener('click', () => {
        if (window.__portalMode !== 'tmdb') return;
        window.__portalTmdbPage = (window.__portalTmdbPage || 1) + 1;
        fetchTmdb(false);
      });
    }

    // Default TMDB mode
    setMode('tmdb');
  }

  document.addEventListener('DOMContentLoaded', () => {
    wireClicks();
    wireTmdbOpens();
    wireSearch();
    wireTmdb();
    enrichTmdb();
  });
})();
