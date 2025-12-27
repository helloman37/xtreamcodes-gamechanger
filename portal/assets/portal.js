(function(){
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));

  const PAGE = (document.body && document.body.getAttribute('data-page')) || '';

  function legalvodCfg(){
    const cfg = (window.GC_PLUGINS && window.GC_PLUGINS.legalvod) ? window.GC_PLUGINS.legalvod : null;
    if (!cfg || !cfg.enabled) return null;
    const base = String(cfg.base_url || '').replace(/\/+$/, '').trim();
    if (!base) return null;
    return {
      base,
      movie_template: String(cfg.movie_template || '/movie/{id}/'),
      tv_template: String(cfg.tv_template || '/tv/{id}/{season}/{episode}/')
    };
  }

  function legalvodBuildMovie(id){
    const cfg = legalvodCfg();
    if (!cfg) return '';
    let path = cfg.movie_template.replace('{id}', encodeURIComponent(String(id)));
    path = '/' + path.replace(/^\/+/, '');
    return cfg.base + path;
  }

  function legalvodBuildTv(id, season, episode){
    const cfg = legalvodCfg();
    if (!cfg) return '';
    let path = cfg.tv_template
      .replace('{id}', encodeURIComponent(String(id)))
      .replace('{season}', encodeURIComponent(String(season)))
      .replace('{episode}', encodeURIComponent(String(episode)));
    path = '/' + path.replace(/^\/+/, '');
    return cfg.base + path;
  }


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

  function openModal({title, desc, badges, url, iframeUrl}){
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
    const iwrap = document.getElementById('gc_iframe_wrap');
    const iframe = document.getElementById('gc_iframe_player');

    // reset iframe
    if (iframe) iframe.src = 'about:blank';
    if (iwrap) iwrap.style.display = 'none';
    if (!url && !iframeUrl) {
      // Info-only modal (TMDB browse)
      jpStop();
      if (player) player.style.display = 'none';
      if (iwrap) iwrap.style.display = 'none';
      if (iframe) iframe.src = 'about:blank';
      return;
    }

    if (iframeUrl) {
      // Iframe playback mode
      jpStop();
      if (player) player.style.display = 'none';
      if (iwrap) iwrap.style.display = '';
      if (iframe) iframe.src = iframeUrl;

      // LegalVOD TV: show Season/Episode dropdowns in the badge row (updates iframe src live)
      try { legalvodMaybeControls(m, iframeUrl); } catch(e) {}

      return;
    }
    if (player) player.style.display = '';
    const ok = jpPlay(url, title || 'Now Playing');
    if (!ok) {
      // If CDN blocks jPlayer, at least show a friendly message
      d.textContent = (desc ? (desc + "\n\n") : "") + "Player library not loaded (jPlayer). Check server outbound access or include local jPlayer files.";
    }
  }

  // Expose a tiny API for pages that want to trigger the modal programmatically
  // (ex: Series page dropdown Play button)
  try {
    window.GC_OPEN_MODAL = openModal;
    window.GC_CLOSE_MODAL = closeModal;
  } catch(e) {}

  function closeModal(){
    const m = modal();
    if (!m) return;
    jpStop();
    const iwrap = document.getElementById('gc_iframe_wrap');
    const iframe = document.getElementById('gc_iframe_player');
    if (iframe) iframe.src = 'about:blank';
    if (iwrap) iwrap.style.display = 'none';
    m.classList.remove('on');
  }

  function wireClicks(){
    $$('.js-play').forEach(el => {
      el.addEventListener('click', () => {
        const raw = el.getAttribute('data-play-url') || '';
        if (!raw) return;
        const title = el.getAttribute('data-title') || 'Now Playing';

        // Iframe mode: prefix with "iframe:"
        if (raw.startsWith('iframe:')) {
          const iframeUrl = raw.slice('iframe:'.length);
          openModal({title, desc, badges: [], url: '', iframeUrl});
          return;
        }

        const url = raw;
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
        const tid = el.getAttribute('data-tmdb-id') || '';
        const kind = el.getAttribute('data-kind') || (PAGE === 'series' ? 'tv' : 'movie');
        let iframeUrl = '';
        if (tid) {
          if (kind === 'movie') {
            iframeUrl = legalvodBuildMovie(tid);
          } else if (kind === 'tv') {
            const season = parseInt(el.getAttribute('data-season') || '1', 10) || 1;
            const episode = parseInt(el.getAttribute('data-episode') || '1', 10) || 1;
            iframeUrl = legalvodBuildTv(tid, season, episode);
          }
        }
        openModal({title, desc, badges, url: '', iframeUrl});
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
        // Use absolute path so clean URLs like /portal/movies/ don't break relative fetches.
        const res = await fetch('/portal/tmdb_enrich.php?kind=' + encodeURIComponent(kind) + '&ids=' + encodeURIComponent(ids), {
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
      cat.addEventListener('change', () => {
        // Live TV can have huge lists; switching categories should reload server-side.
        if (PAGE === 'live') {
          const v = (cat.value || 'all').trim();
          // Keep canonical clean URL (trailing slash) to avoid broken relative paths.
          const base = '/portal/live/';
          if (!v || v === 'all') {
            window.location.href = base;
          } else {
            window.location.href = base + '?cat=' + encodeURIComponent(v);
          }
          return;
        }
        applyLocalFilters();
      });
    }
  }

  function tmdbTile(it){
    const div = document.createElement('div');
    div.className = 'tile js-tmdb-tile';
    div.setAttribute('data-title', it.title || '');
    div.setAttribute('data-desc', it.plot || '');
    div.setAttribute('data-rating', it.rating || '');
    div.setAttribute('data-year', it.year || '');
    div.setAttribute('data-tmdb-id', (it.tmdb_id || it.id || ''));
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

      // LegalVOD: movies + series can play immediately in iframe (TMDB browse)
      if ((window.__legalvod && window.__legalvod.enabled) && it.tmdb_id) {
        let iframeUrl = '';
        if (it.type === 'movie') iframeUrl = buildLegalvodUrl('movie', it.tmdb_id);
        if (it.type === 'tv') iframeUrl = buildLegalvodUrl('tv', it.tmdb_id, 1, 1);
        if (iframeUrl) {
          badges.push({text: 'LegalVOD', kind: 'good'});
          openModal({title: it.title, desc: it.plot, badges, url: '', iframeUrl});
          return;
        }
      }

      const tid = it.tmdb_id || it.id || '';
      let iframeUrl = '';
      // If LegalVOD is enabled and we can build an iframe URL, prefer iframe playback
      if (tid && (it.type === 'movie' || PAGE === 'movies')) iframeUrl = legalvodBuildMovie(tid);
      if (tid && (it.type === 'tv' || PAGE === 'series')) iframeUrl = legalvodBuildTv(tid, 1, 1);
      openModal({title: it.title, desc: it.plot, badges, url: '', iframeUrl});
    });
    return div;
  }

  
  function buildLegalvodUrl(kind, id, season, episode){
    try {
      const cfg = window.__legalvod || null;
      if (!cfg || !cfg.enabled) return '';
      const base = (cfg.base_url || '').replace(/\/+$/, '');
      if (!base) return '';
      let tpl = (kind === 'tv') ? (cfg.tv_template || '/tv/{id}/{season}/{episode}/') : (cfg.movie_template || '/movie/{id}/');
      tpl = (tpl || '').trim();
      if (!tpl) tpl = (kind === 'tv') ? '/tv/{id}/{season}/{episode}/' : '/movie/{id}/';
      let path = tpl;
      path = path.replace('{id}', encodeURIComponent(String(id || '')));
      if (kind === 'tv') {
        path = path.replace('{season}', encodeURIComponent(String(season || 1)));
        path = path.replace('{episode}', encodeURIComponent(String(episode || 1)));
      }
      if (!path.startsWith('/')) path = '/' + path;
      return base + path;
    } catch(e) {
      return '';
    }
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
      // Absolute path so clean URLs like /portal/movies/ don't break relative fetches.
      url = `/portal/tmdb_search.php?type=${encodeURIComponent(kind)}&q=${encodeURIComponent(q)}&page=${page}`;
    } else {
      // Absolute path so clean URLs like /portal/movies/ don't break relative fetches.
      url = `/portal/tmdb_browse.php?type=${encodeURIComponent(kind)}&mode=${encodeURIComponent(mode)}&page=${page}`;
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

  function legalvodParseTvUrl(url){
    if (!url) return null;
    try {
      const u = String(url);
      // match /tv/{id}/{season}/{episode}/ (allow missing trailing slash)
      const m = u.match(/\/tv\/([^\/]+)\/(\d+)\/(\d+)(?:\/|\?|$)/i);
      if (!m) return null;
      return { id: m[1], season: parseInt(m[2],10)||1, episode: parseInt(m[3],10)||1 };
    } catch(e){
      return null;
    }
  }

  function legalvodMaybeControls(modalEl, iframeUrl){
    const cfg = legalvodCfg();
    const b = $('.js-modal-badges', modalEl);
    if (!b) return;

    // remove old controls
    $$('.js-lv-control', b).forEach(n => n.remove());

    if (!cfg || !iframeUrl) return;

    const tv = legalvodParseTvUrl(iframeUrl);
    if (!tv) return;

    const makeLabel = (txt) => {
      const s = document.createElement('span');
      s.className = 'badge js-lv-control';
      s.textContent = txt;
      return s;
    };

    const makeSelect = () => {
      const sel = document.createElement('select');
      sel.className = 'badge js-lv-control';
      sel.style.border = '1px solid rgba(255,255,255,.16)';
      sel.style.background = 'rgba(255,255,255,.04)';
      sel.style.color = 'rgba(255,255,255,.92)';
      sel.style.cursor = 'pointer';
      sel.style.outline = 'none';
      sel.style.colorScheme = 'dark';
      sel.style.padding = '6px 10px';
      sel.style.borderRadius = '999px';
      return sel;
    };

    const selSeason = makeSelect();
    const selEp = makeSelect();
    selSeason.disabled = true;
    selEp.disabled = true;

    const setLoading = (sel, label) => {
      sel.innerHTML = '';
      const o = document.createElement('option');
      o.value = '';
      o.textContent = label;
      o.style.background = '#0b0f14';
      o.style.color = 'rgba(255,255,255,.92)';
      sel.appendChild(o);
    };

    const setOptions = (sel, opts, value) => {
      sel.innerHTML = '';
      (opts || []).forEach(x => {
        const o = document.createElement('option');
        o.value = String(x.value);
        o.textContent = String(x.label || x.value);
        o.style.background = '#0b0f14';
        o.style.color = 'rgba(255,255,255,.92)';
        sel.appendChild(o);
      });
      if (value !== undefined && value !== null && value !== '') {
        sel.value = String(value);
      }
    };

    const fetchJson = async (url) => {
      const r = await fetch(url, { credentials: 'same-origin' });
      if (!r.ok) throw new Error('http_' + r.status);
      const t = await r.text();
      return JSON.parse(t);
    };

    let seasonsMeta = null;

    const updateIframe = () => {
      const s = parseInt(selSeason.value, 10) || 1;
      const e = parseInt(selEp.value, 10) || 1;
      const newUrl = legalvodBuildTv(tv.id, s, e);
      if (!newUrl) return;
      const iframe = $('.js-modal-iframe', modalEl);
      if (iframe) iframe.src = newUrl;
    };

    const fillEpisodesNumeric = (episodeCount, pickEpisode) => {
      const n = Math.max(1, parseInt(episodeCount, 10) || 1);
      const opts = [];
      for (let i = 1; i <= n; i++) opts.push({ value: i, label: 'E' + i });
      setOptions(selEp, opts, pickEpisode);
      selEp.disabled = false;
    };

    const loadEpisodes = async (seasonNumber, pickEpisode) => {
      const sNum = parseInt(seasonNumber, 10) || 1;
      selEp.disabled = true;
      setLoading(selEp, 'Loading…');

      // Find episode_count from seasons meta as fallback
      let epCount = 50;
      if (Array.isArray(seasonsMeta)) {
        const sm = seasonsMeta.find(x => (parseInt(x.season_number, 10) || 0) === sNum);
        if (sm && sm.episode_count) epCount = parseInt(sm.episode_count, 10) || epCount;
      }

      try {
        const j = await fetchJson('/portal/tmdb_tvepisodes.php?id=' + encodeURIComponent(tv.id) + '&season=' + encodeURIComponent(String(sNum)));
        const eps = (j && j.ok && Array.isArray(j.episodes)) ? j.episodes : [];
        if (eps.length) {
          const opts = eps
            .filter(e => e && e.episode_number != null)
            .map(e => ({
              value: e.episode_number,
              label: 'E' + e.episode_number + (e.name ? (' • ' + e.name) : '')
            }));
          setOptions(selEp, opts, pickEpisode);
          selEp.disabled = false;
          return;
        }
      } catch (e) {
        // fall through to numeric
      }

      fillEpisodesNumeric(epCount, pickEpisode);
    };

    const loadSeasons = async () => {
      selSeason.disabled = true;
      selEp.disabled = true;
      setLoading(selSeason, 'Loading…');
      setLoading(selEp, 'Loading…');

      try {
        const j = await fetchJson('/portal/tmdb_tvmeta.php?id=' + encodeURIComponent(tv.id));
        const seasons = (j && j.ok && Array.isArray(j.seasons)) ? j.seasons : [];
        if (!seasons.length) throw new Error('no_seasons');

        // Keep full meta for episode_count fallback
        seasonsMeta = seasons.slice();

        // Build season options (skip negatives; allow 0 Specials)
        const opts = seasons
          .filter(s => s && s.season_number != null && parseInt(s.season_number, 10) >= 0)
          .map(s => ({
            value: parseInt(s.season_number, 10),
            label: 'S' + s.season_number + (s.name ? (' • ' + s.name) : '')
          }));

        if (!opts.length) throw new Error('no_opts');

        const preferred = opts.some(o => parseInt(o.value,10) === tv.season) ? tv.season : opts[0].value;
        setOptions(selSeason, opts, preferred);
        selSeason.disabled = false;

        // Episodes for selected season
        const pickEp = (parseInt(selSeason.value, 10) === tv.season) ? tv.episode : 1;
        await loadEpisodes(parseInt(selSeason.value, 10) || 1, pickEp);
        return;
      } catch (e) {
        // Fallback: numeric seasons/episodes
        seasonsMeta = null;
        const maxSeasons = 50;
        const opts = [];
        for (let i = 1; i <= maxSeasons; i++) opts.push({ value: i, label: 'S' + i });
        setOptions(selSeason, opts, tv.season);
        selSeason.disabled = false;
        await loadEpisodes(tv.season, tv.episode);
      }
    };

    selSeason.addEventListener('change', async () => {
      const s = parseInt(selSeason.value, 10) || 1;
      await loadEpisodes(s, 1);
      updateIframe();
    });
    selEp.addEventListener('change', updateIframe);

    b.appendChild(makeLabel('Season'));
    b.appendChild(selSeason);
    b.appendChild(makeLabel('Episode'));
    b.appendChild(selEp);

    // kick off TMDB-backed population
    loadSeasons();
  }


})();
