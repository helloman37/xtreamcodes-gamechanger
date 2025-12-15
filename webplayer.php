<?php
// IPTV Web Player (get.php only • jPlayer) - PHP deploy-ready (Option C)
// - Auto-detects get.php/xmltv.php base path
// - Auto-fills creds from storefront session if present
// - Uses ONLY get.php + xmltv.php (no player_api)

session_start();

// Storefront session keys (adjust here if your storefront uses different names)
$autoUser = $_SESSION['store_user_username'] ?? $_SESSION['iptv_username'] ?? '';
$autoPass = $_SESSION['store_pass_plain'] ?? $_SESSION['iptv_password_plain'] ?? '';

// Optional: allow querystring autologin when you need it (kept off by default)
// $autoUser = $autoUser ?: ($_GET['username'] ?? '');
// $autoPass = $autoPass ?: ($_GET['password'] ?? '');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>IPTV Web Player (get.php only • jPlayer)</title>

  <!-- jQuery + jPlayer -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/jplayer@2.9.2/dist/jplayer/jquery.jplayer.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jplayer@2.9.2/dist/skin/blue.monday/jplayer.blue.monday.min.css">

  <!-- HLS.js bridge -->
  <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.18/dist/hls.min.js"></script>

  <style>
    :root{
      --bg:#0b0d12;
      --panel:#121621;
      --panel2:#0f1320;
      --text:#e6e9ef;
      --muted:#9aa3b2;
      --accent:#4dd2ff;
      --accent2:#7cffb2;
      --danger:#ff6b6b;
      --border:rgba(255,255,255,.08);
      font-synthesis: style;
    }
    *{box-sizing:border-box}
    body{
      margin:0; background:var(--bg); color:var(--text);
      font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
      height:100vh; display:grid; grid-template-columns:360px 1fr; grid-template-rows:auto 1fr;
    }
    header{
      grid-column:1/-1; padding:12px 16px; display:flex; gap:12px; align-items:center;
      background:linear-gradient(180deg,#0f1220,#0b0d12); border-bottom:1px solid var(--border);
    }
    header h1{font-size:18px;margin:0;font-weight:700;letter-spacing:.4px}
    header .pill{font-size:12px;color:var(--muted);padding:4px 8px;border:1px solid var(--border);border-radius:999px}

    aside{
      border-right:1px solid var(--border); background:var(--panel);
      display:flex; flex-direction:column; min-height:0;
    }
    .section{padding:12px;border-bottom:1px solid var(--border)}
    .section h2{
      margin:0 0 8px; font-size:13px; color:var(--muted);
      font-weight:700; text-transform:uppercase; letter-spacing:.8px;
    }

    .login-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    label{font-size:12px;color:var(--muted);display:block;margin-bottom:4px}
    .ui input,.ui select,.ui button{
      width:100%; padding:9px 10px; border-radius:10px; border:1px solid var(--border);
      background:var(--panel2); color:var(--text); outline:none;
    }
    .ui input::placeholder{color:#657089}

    .ui button{
      cursor:pointer; font-weight:700; letter-spacing:.3px; transition:.15s ease;
      background:linear-gradient(180deg,#1b2240,#10162b);
      border:1px solid rgba(77,210,255,.35);
      box-shadow:0 0 0 1px rgba(77,210,255,.1) inset;
    }
    .ui button:hover{transform:translateY(-1px)}
    .ui button.secondary{border-color:var(--border);box-shadow:none;font-weight:600}

    .status{font-size:12px;color:var(--muted);margin-top:8px;min-height:18px;white-space:pre-wrap}
    .status.ok{color:var(--accent2)}
    .status.err{color:var(--danger)}

    .search-row{display:flex;gap:8px}
    .search-row input{flex:1}

    .groups{display:flex;flex-wrap:wrap;gap:6px;max-height:140px;overflow:auto;padding-right:4px}
    .group-chip{
      font-size:12px;padding:6px 8px;border-radius:999px;border:1px solid var(--border);
      background:#0c1020;cursor:pointer;user-select:none;white-space:nowrap;
    }
    .group-chip.active{
      border-color:rgba(77,210,255,.6);color:#bfefff;box-shadow:0 0 0 1px rgba(77,210,255,.15) inset;
    }

    .channel-list{overflow:auto;padding:6px;display:flex;flex-direction:column;gap:6px;min-height:0}
    .channel{
      display:grid;grid-template-columns:40px 1fr auto;gap:8px;align-items:center;padding:8px;
      border-radius:12px;border:1px solid var(--border);background:var(--panel2);cursor:pointer;transition:.08s ease;
    }
    .channel:hover{transform:translateY(-1px)}
    .channel.active{border-color:rgba(124,255,178,.6);box-shadow:0 0 0 1px rgba(124,255,178,.2) inset}
    .logo{
      width:40px;height:40px;border-radius:8px;object-fit:cover;background:#0a0d18;border:1px solid var(--border);
    }
    .ch-name{font-size:14px;font-weight:700;line-height:1.1}
    .ch-meta{font-size:11px;color:var(--muted)}

    main{display:grid;grid-template-rows:auto 1fr;min-height:0}

    .player-wrap{
      background:#000;border-bottom:1px solid var(--border);padding:0;position:relative;min-height:260px;
      display:flex;align-items:stretch;justify-content:stretch;
    }
    #jp_container{width:100%;height:100%;position:relative}
    #jquery_jplayer, #jquery_jplayer video{
      width:100% !important; height:100% !important; background:#000;
    }
    #jp_container .jp-gui{
      position:absolute;left:0;right:0;bottom:0;padding:8px;
      background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.7));
    }
    #jp_container .jp-interface{
      background:#000;border:1px solid var(--border);border-radius:12px;
    }


    /* --- User tweak: kill bottom black bar + timestamps, keep only channel name overlay --- */
    #jp_container .jp-gui{
      background: transparent !important;
      padding: 0 !important;
      opacity: 0;
      transition: opacity .15s ease;
      pointer-events: none;
    }
    /* show controls only when hovering player */
    .player-wrap:hover #jp_container .jp-gui{
      opacity: 1;
      pointer-events: auto;
      padding: 8px !important;
      background: linear-gradient(180deg, rgba(0,0,0,0), rgba(0,0,0,.35)) !important;
    }
    #jp_container .jp-interface{
      background: transparent !important;
      border: none !important;
      box-shadow: none !important;
    }
    #jp_container .jp-progress,
    #jp_container .jp-volume-controls,
    #jp_container .jp-time-holder,
    #jp_container .jp-current-time,
    #jp_container .jp-duration{
      display: none !important;
    }

    /* jPlayer buttons must NOT inherit our UI button styles */
    #jp_container button{
      width:auto !important; padding:0 !important; margin:0 !important;
      background:transparent !important; border:none !important; box-shadow:none !important;
      border-radius:0 !important; font-weight:normal !important; letter-spacing:0 !important;
      transform:none !important; color:transparent !important; text-indent:-9999px; overflow:hidden;
    }
    #jp_container .jp-play-bar{background:var(--accent)}
    #jp_container .jp-volume-bar-value{background:var(--accent2)}

    .now-playing{
      position:absolute;left:12px;bottom:12px;background:rgba(0,0,0,.55);padding:8px 10px;border-radius:10px;font-size:13px;
      backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,.12);max-width:calc(100% - 24px);
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none;
    }

    .info{padding:12px;color:var(--muted);font-size:13px;overflow:auto}

    @media (max-width:900px){
      body{grid-template-columns:1fr;grid-template-rows:auto auto 1fr}
      aside{height:48vh;border-right:none;border-bottom:1px solid var(--border)}
    }

    /* --- XMLTV EPG styling --- */
    #epgPanel{line-height:1.35}
    .epg-item{
      padding:8px;
      border:1px solid var(--border);
      background:var(--panel2);
      border-radius:10px;
      margin-bottom:6px;
    }
    .epg-title{font-weight:700; font-size:14px}
    .epg-time{font-size:12px; color:var(--muted)}
    .epg-desc{font-size:12px; color:#c7cbd6; margin-top:4px}

  </style>
</head>
<body>
  <header>
    <h1>IPTV Web Player</h1>
    <div id="counts" class="pill" style="display:none"></div>
  </header>

  <aside class="ui">
    <div class="section">
      <h2>Login</h2>
      <div class="login-grid">
        <div>
          <label>Username</label>
          <input id="username" placeholder="your username" autocomplete="username">
        </div>
        <div>
          <label>Password</label>
          <input id="password" placeholder="your password" type="password" autocomplete="current-password">
        </div>
<div>
          <label>Output (browser needs HLS)</label>
          <select id="outputMode">
            <option value="hls" selected>hls (web compatible)</option>
            <option value="ts">ts (VLC / apps)</option>
          </select>
        </div>
        <div style="grid-column:1/-1;display:flex;gap:6px">
          <button id="loadBtn">Load Playlist</button>
          <button class="secondary" id="clearBtn">Clear</button>
        </div>
      </div>
      <div class="status" id="status"></div>
    </div>

    <div class="section">
      <h2>Filter</h2>
      <div style="display:flex; flex-direction:column; gap:8px;">
        <input id="search" placeholder="search channels...">
        <select id="groupSelect">
          <option value="">All Groups</option>
        </select>
      </div>
<div class="groups" id="groupChips"></div>
    </div>

    <div class="channel-list" id="channelList"></div>
  </aside>

  <main>
    <div class="player-wrap">
      <div id="jp_container" class="jp-video jp-video-360p" role="application" aria-label="media player">
        <div class="jp-type-single">
          <div id="jquery_jplayer" class="jp-jplayer"></div>
          <div class="jp-gui">
            <div class="jp-interface">
              <div class="jp-controls">
                <button class="jp-play">play</button>
                <button class="jp-pause">pause</button>
                <button class="jp-stop">stop</button>
                <button class="jp-mute">mute</button>
                <button class="jp-unmute">unmute</button>
                <button class="jp-volume-max">max volume</button>
              </div>
              <div class="jp-progress">
                <div class="jp-seek-bar"><div class="jp-play-bar"></div></div>
              </div>
              <div class="jp-volume-controls">
                <div class="jp-volume-bar"><div class="jp-volume-bar-value"></div></div>
              </div>
              <div class="jp-time-holder">
                <div class="jp-current-time">&nbsp;</div>
                <div class="jp-duration">&nbsp;</div>
              </div>
              <div class="jp-toggles">
                <button class="jp-full-screen">full screen</button>
                <button class="jp-restore-screen">restore screen</button>
              </div>
            </div>
          </div>
          <div class="jp-no-solution">
            <span>Update Required</span>
            Your browser can’t play this stream.
          </div>
        </div>
      </div>
      <div class="now-playing" id="nowPlaying">Nothing playing</div>
    </div>

    <div class="info" id="epgPanel">
      <div style="font-weight:700; margin-bottom:6px;">TV Guide (XMLTV)</div>
      <div id="epgStatus" style="font-size:12px; color:var(--muted); margin-bottom:6px;">EPG not loaded yet.</div>
      <div id="epgNow" style="margin-bottom:8px;"></div>
      <div id="epgNext"></div>
    </div>
  </main>

<script>
(() => {
  const els = {
    username: document.getElementById('username'),
    password: document.getElementById('password'),    outputMode: document.getElementById('outputMode'),
    loadBtn: document.getElementById('loadBtn'),
    clearBtn: document.getElementById('clearBtn'),
    status: document.getElementById('status'),
    search: document.getElementById('search'),
    groupSelect: document.getElementById('groupSelect'),
    groupChips: document.getElementById('groupChips'),
    channelList: document.getElementById('channelList'),
    nowPlaying: document.getElementById('nowPlaying'),
    counts: document.getElementById('counts'),
  };

  let channels = [];
  let groups = [];
  let activeGroup = '';
  let activeChannelId = null;
  let favorites = new Set(JSON.parse(localStorage.getItem('iptv_favs') || '[]'));
  let hls = null;

  // ----------- base-path autodetect (get.php / xmltv.php) -----------
  let baseCache = null;
  async function probe(url){
    try{
      const r = await fetch(url, {method:"GET", cache:"no-store"});
      // get.php/xmltv.php return 400/401 when called without creds; treat as "exists"
      return r && (r.ok || [400,401,403].includes(r.status));
    }catch(e){ return false; }
  }
async function detectBase(){
    if (baseCache) return baseCache;
    const here = window.location.pathname.replace(/\/[^\/]*$/, "/");
    const candidates = [
      here,
      here.replace(/\/$/, "") + "/../",
      "/"
    ];
    for (const c of candidates){
      const clean = c.replace(/\/\.\.\//g, "/");
      if (await probe(clean + "get.php")){
        baseCache = clean;
        return baseCache;
      }
    }
    baseCache = "/";
    return baseCache;
  }
  async function getPhpUrl(u,p){
    const base = await detectBase();
    return `${base}get.php?username=${encodeURIComponent(u)}&password=${encodeURIComponent(p)}&type=m3u_plus&output=hls&link=auto`;
  }
  async function xmltvUrl(u,p){
    const base = await detectBase();
    return `${base}xmltv.php?username=${encodeURIComponent(u)}&password=${encodeURIComponent(p)}`;
  }
  // ---------------------------------------------------------------

  // ---------------- XMLTV EPG ----------------
  let epgXmlText = null;
  let epgMap = null;        // channelId -> array of programmes
  let epgAlias = null;      // normalized alias -> channelId

  function epgSetStatus(msg, type=''){
    const el = document.getElementById('epgStatus');
    if (!el) return;
    el.style.color = type==='err' ? 'var(--danger)' : 'var(--muted)';
    el.textContent = msg;
  }

  function normalizeKey(s){
    return (s||"")
      .toLowerCase()
      .replace(/&amp;/g,"&")
      .replace(/[^a-z0-9]+/g,"")
      .trim();
  }

  function fmtTimeRange(startDate, stopDate){
    const opts = { hour: '2-digit', minute: '2-digit' };
    return `${startDate.toLocaleTimeString([], opts)} - ${stopDate.toLocaleTimeString([], opts)}`;
  }

  function parseXmltvDate(s){
    const m = (s||"").match(/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/);
    if (!m) return null;
    const [_,Y,Mo,D,H,Mi,S] = m;
    return new Date(+Y, +Mo-1, +D, +H, +Mi, +S);
  }

  function buildEpgMapFromXml(xmlText){
    const parser = new DOMParser();
    const xml = parser.parseFromString(xmlText, "text/xml");

    const map = new Map();
    const alias = new Map();

    // Build alias map from <channel> entries
    const chans = Array.from(xml.getElementsByTagName("channel"));
    for (const ch of chans){
      const id = ch.getAttribute("id") || "";
      if (!id) continue;
      if (!alias.has(normalizeKey(id))) alias.set(normalizeKey(id), id);

      const dnames = Array.from(ch.getElementsByTagName("display-name")).map(x=>x.textContent.trim());
      for (const dn of dnames){
        if (!dn) continue;
        alias.set(normalizeKey(dn), id);

        const stripped = dn.replace(/^\s*[A-Z]{2,3}\s*\|\s*/i, "").trim();
        if (stripped && stripped !== dn){
          alias.set(normalizeKey(stripped), id);
        }
      }
    }

    // Programmes
    const progs = Array.from(xml.getElementsByTagName("programme"));
    for (const p of progs){
      const chId = p.getAttribute("channel") || "";
      if (!chId) continue;

      const start = parseXmltvDate(p.getAttribute("start") || "");
      const stop  = parseXmltvDate(p.getAttribute("stop") || "");
      const titleEl = p.getElementsByTagName("title")[0];
      const descEl  = p.getElementsByTagName("desc")[0];

      const title = titleEl ? titleEl.textContent.trim() : "Untitled";
      const desc  = descEl ? descEl.textContent.trim() : "";

      const item = { start, stop, title, desc, channel: chId };
      if (!map.has(chId)) map.set(chId, []);
      map.get(chId).push(item);
    }
    for (const [k, arr] of map){
      arr.sort((a,b)=> (a.start||0) - (b.start||0));
    }
    return { map, alias };
  }

  async function loadEpg(u, p){
    try{
      epgSetStatus("Loading EPG...");
      const epgUrl = await xmltvUrl(u,p);
      const res = await fetch(epgUrl, { cache:"no-store" });
      const text = await res.text();
      if (!res.ok || !text.includes("<tv")){
        epgSetStatus("EPG load failed (bad response).", "err");
        return;
      }
      epgXmlText = text;
      const built = buildEpgMapFromXml(text);
      epgMap = built.map;
      epgAlias = built.alias;
      epgSetStatus("EPG loaded.");
    }catch(e){
      epgSetStatus("EPG load error: " + e.message, "err");
    }
  }

  function findBestEpgIdForChannel(ch){
    if (!epgMap || !epgAlias) return null;

    const candidates = [ch.tvgId, ch.tvgName, ch.name]
      .filter(Boolean)
      .map(x=>x.trim());

    for (const c of candidates){
      if (epgMap.has(c)) return c;
    }

    for (const c of candidates){
      const nk = normalizeKey(c);
      if (epgAlias.has(nk)) return epgAlias.get(nk);

      const stripped = c.replace(/^\s*[A-Z]{2,3}\s*\|\s*/i, "").trim();
      const nk2 = normalizeKey(stripped);
      if (epgAlias.has(nk2)) return epgAlias.get(nk2);
    }

    return null;
  }

  function renderEpgForChannel(ch){
    const nowBox = document.getElementById("epgNow");
    const nextBox = document.getElementById("epgNext");
    if (!nowBox || !nextBox) return;

    nowBox.innerHTML = "";
    nextBox.innerHTML = "";

    if (!epgMap){
      epgSetStatus("EPG not loaded.");
      return;
    }

    const id = findBestEpgIdForChannel(ch);
    if (!id){
      epgSetStatus("No EPG match for this channel.");
      return;
    }

    const list = epgMap.get(id) || [];
    const now = new Date();

    const upcoming = list.filter(it => it.start && it.stop && it.stop > now).slice(0, 8);
    if (!upcoming.length){
      epgSetStatus("No upcoming programmes.");
      return;
    }

    const current = upcoming.find(it => it.start <= now && it.stop >= now) || upcoming[0];
    epgSetStatus(`Guide for: ${id}`);

    const curPct = (current.start && current.stop)
      ? Math.min(100, Math.max(0, ((now-current.start)/(current.stop-current.start))*100))
      : 0;

    nowBox.innerHTML = `
      <div class="epg-item">
        <div class="epg-title">Now: ${escapeHtml(current.title)}</div>
        <div class="epg-time">${current.start && current.stop ? fmtTimeRange(current.start, current.stop) : ""}</div>
        <div style="height:6px;background:#111;border-radius:999px;overflow:hidden;margin-top:6px;">
          <div style="height:100%;width:${curPct}%;background:var(--accent);"></div>
        </div>
        ${current.desc ? `<div class="epg-desc">${escapeHtml(current.desc)}</div>` : ""}
      </div>
    `;

    const rest = upcoming.filter(it => it !== current);
    nextBox.innerHTML = rest.map(it => `
      <div class="epg-item">
        <div class="epg-title">${escapeHtml(it.title)}</div>
        <div class="epg-time">${it.start && it.stop ? fmtTimeRange(it.start, it.stop) : ""}</div>
        ${it.desc ? `<div class="epg-desc">${escapeHtml(it.desc)}</div>` : ""}
      </div>
    `).join("");
  }
  // -------------- /XMLTV EPG --------------

  // Init jPlayer once
  $("#jquery_jplayer").jPlayer({
    supplied: "m3u8, m4v, webmv, ogv, oga, mp3",
    solution: "html, flash",
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

  // Restore creds (but force HLS default for web)
  els.username.value = localStorage.getItem('iptv_user') || '';
  els.password.value = localStorage.getItem('iptv_pass') || '';  els.outputMode.value = localStorage.getItem('iptv_out') || 'hls';
  if (els.outputMode.value !== 'hls') {
    els.outputMode.value = 'hls'; // force sane default
  }

  function setStatus(msg, type='') {
    els.status.className = 'status ' + type;
    els.status.textContent = msg || '';
  }

  function saveCreds() {
    localStorage.setItem('iptv_user', els.username.value.trim());
    localStorage.setItem('iptv_pass', els.password.value);    localStorage.setItem('iptv_out', els.outputMode.value);
  }

  function clearCreds() {
    localStorage.removeItem('iptv_user');
    localStorage.removeItem('iptv_pass');    localStorage.removeItem('iptv_out');
    els.username.value = '';
    els.password.value = '';    els.outputMode.value = 'hls';
  }

  function parseExtinf(line) {
    const attrs = {};
    const attrRe = /(\w[\w-]*)="([^"]*)"/g;
    let m;
    while ((m = attrRe.exec(line)) !== null) attrs[m[1]] = m[2];
    const commaIdx = line.indexOf(',');
    const name = commaIdx >= 0 ? line.slice(commaIdx + 1).trim() : 'Unknown';
    return {
      name,
      group: attrs['group-title'] || 'Other',
      logo: attrs['tvg-logo'] || '',
      tvgId: attrs['tvg-id'] || '',
      tvgName: attrs['tvg-name'] || ''
    };
  }

  function parseM3U(text) {
    const lines = text.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
    const out = [];
    let pending = null;
    let idCounter = 1;

    for (const line of lines) {
      if (line.startsWith('#EXTINF:')) {
        pending = parseExtinf(line);
        continue;
      }
      if (!line.startsWith('#') && pending) {
        out.push({ id: idCounter++, ...pending, url: line });
        pending = null;
      }
    }

    // Fallback if provider returns bare URLs without EXTINF
    if (out.length === 0) {
      for (const line of lines) {
        if (!line.startsWith('#')) {
          const guessName = decodeURIComponent(line.split('/').pop().split('?')[0] || 'Stream');
          out.push({ id: idCounter++, name: guessName, group: 'Streams', logo: '', url: line });
        }
      }
    }
    return out;
  }

  function buildGroups() {
    groups = [...new Set(channels.map(c => c.group))].sort((a,b)=>a.localeCompare(b));
    els.groupSelect.innerHTML = `<option value="">All Groups</option>` +
      groups.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
    els.groupChips.innerHTML = groups.map(g => `
      <div class="group-chip ${g===activeGroup?'active':''}" data-group="${escapeHtml(g)}">${escapeHtml(g)}</div>
    `).join('');
  }

  function filterChannels() {
    const q = els.search.value.toLowerCase().trim();
    const g = activeGroup || els.groupSelect.value || '';
    return channels.filter(c => {
      const matchQ = !q || c.name.toLowerCase().includes(q);
      const matchG = !g || c.group === g;
      return matchQ && matchG;
    });
  }

  function renderChannels() {
    const list = filterChannels();
    els.counts.textContent = `${list.length} channels`;

    els.channelList.innerHTML = list.map(c => `
      <div class="channel ${c.id===activeChannelId?'active':''}" data-id="${c.id}">
        <img class="logo" src="${escapeAttr(c.logo)}" onerror="this.style.opacity=.2; this.removeAttribute('src')" />
        <div>
          <div class="ch-name">${escapeHtml(c.name)}</div>
          <div class="ch-meta">${escapeHtml(c.group)}</div>
        </div>
      </div>
    `).join('');
  }

  function playChannel(ch) {
    if (!ch) return;
    activeChannelId = ch.id;
    renderChannels();

    els.nowPlaying.textContent = `${ch.name}  •  ${ch.group}`;
    const url = ch.url;
    const lower = url.toLowerCase();

    if (hls) { hls.destroy(); hls = null; }

    const media = { title: ch.name };
    if (lower.includes(".m3u8") || lower.includes("output=hls") || lower.includes("m3u8")) media.m3u8 = url;
    else if (lower.includes(".mp4") || lower.includes(".m4v")) media.m4v = url;
    else if (lower.includes(".webm")) media.webmv = url;
    else if (lower.includes(".ogv") || lower.includes(".ogg")) media.ogv = url;
    else if (lower.includes(".mp3")) media.mp3 = url;
    else media.m3u8 = url; // assume HLS proxy

    $("#jquery_jplayer").jPlayer("setMedia", media).jPlayer("play");

    renderEpgForChannel(ch);

    setTimeout(() => {
      const jp = $("#jquery_jplayer").data("jPlayer");
      const videoEl = jp && jp.htmlElement && jp.htmlElement.video;
      if (!videoEl) return;

      if ((media.m3u8 || lower.includes(".m3u8") || lower.includes("output=hls")) &&
          window.Hls && Hls.isSupported() &&
          !videoEl.canPlayType("application/vnd.apple.mpegurl")) {
        hls = new Hls({ lowLatencyMode: true });
        hls.loadSource(url);
        hls.attachMedia(videoEl);
        videoEl.play && videoEl.play().catch(()=>{});
      }
    }, 0);
  }

  function escapeHtml(s=''){return s.replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;' }[c]));}
  function escapeAttr(s=''){return escapeHtml(s);}

  async function loadPlaylist() {
    const u = els.username.value.trim();
    const p = els.password.value;    const out = els.outputMode.value;

    if (!u || !p) { setStatus("Missing username or password","err"); return; }

    saveCreds();
    setStatus("Loading playlist...");

    if (out !== "hls") {
      setStatus("TS output won't play in browser. Switching to HLS for you.","err");
      els.outputMode.value = "hls";
    }

    const url = await getPhpUrl(u,p);

    try {
      const res = await fetch(url, { cache:"no-store" });
      const text = await res.text();

      if (!res.ok) { setStatus(text || `HTTP ${res.status}`,"err"); return; }

      channels = parseM3U(text);
      loadEpg(u, p);

      if (!channels.length) {
        setStatus("Playlist loaded but no channels were parsed. get.php may be returning HTML or an error.","err");
        els.channelList.innerHTML = "";
        els.counts.textContent = "0 channels";
        return;
      }

      setStatus(`Loaded ${channels.length} channels`,"ok");
      activeGroup = "";
      buildGroups();
      renderChannels();
    } catch (e) {
      setStatus("Network error loading playlist.\n"+e.message,"err");
    }
  }

  els.loadBtn.addEventListener("click", loadPlaylist);
  els.clearBtn.addEventListener("click", () => {
    clearCreds();

    // Stop playback (jPlayer) and any HLS instance
    try { $("#jquery_jplayer").jPlayer("clearMedia"); } catch(e){}
    try { $("#jquery_jplayer").jPlayer("stop"); } catch(e){}
    if (hls) { hls.destroy(); hls = null; }

    // Wipe channels/groups + UI
    channels = [];
    groups = [];
    activeGroup = "";
    activeChannelId = null;
    els.channelList.innerHTML = "";
    els.groupChips.innerHTML = "";
    els.groupSelect.innerHTML = `<option value="">All Groups</option>`;
    els.counts && (els.counts.textContent = "0 channels");
    els.nowPlaying.textContent = "Nothing playing";

    // Wipe EPG UI
    epgXmlText = null; epgMap = null; epgAlias = null;
    const epgNow = document.getElementById("epgNow");
    const epgNext = document.getElementById("epgNext");
    if (epgNow) epgNow.innerHTML = "";
    if (epgNext) epgNext.innerHTML = "";
    epgSetStatus("EPG not loaded yet.");

    setStatus("Cleared. Player stopped.", "ok");
  });

  els.search.addEventListener("input", renderChannels);
  els.groupSelect.addEventListener("change", () => {
    activeGroup = els.groupSelect.value;
    buildGroups(); renderChannels();
  });
  els.groupChips.addEventListener("click",(e)=>{
    const chip=e.target.closest(".group-chip");
    if(!chip)return;
    activeGroup=chip.dataset.group||"";
    els.groupSelect.value=activeGroup;
    buildGroups(); renderChannels();
  });
  els.channelList.addEventListener("click",(e)=>{
    const row=e.target.closest(".channel");
    if(!row)return;
    const id=Number(row.dataset.id);
    const ch=channels.find(x=>x.id===id);
    playChannel(ch);
  });

  // ---------- PHP session autologin ----------
  const AUTO_USER = <?= json_encode($autoUser) ?>;
  const AUTO_PASS = <?= json_encode($autoPass) ?>;

  if (AUTO_USER && AUTO_PASS) {
    els.username.value = AUTO_USER;
    els.password.value = AUTO_PASS;
    saveCreds();
    loadPlaylist();
  } else if (els.username.value && els.password.value) {
    loadPlaylist();
  }
})();
</script>
</body>
</html>
