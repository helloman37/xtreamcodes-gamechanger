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
      --player-h: clamp(320px, 72vh, 760px);
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
      grid-column:1/-1; padding:12px 16px; display:flex; gap:12px; align-items:center; justify-content:space-between;
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

    main{display:flex;flex-direction:column;min-height:0}

    .player-wrap{
      background:#000;border-bottom:1px solid var(--border);padding:0;position:relative;
      /* Lock player height so it NEVER jumps and never overlaps the EPG panel */
      height:var(--player-h);
      min-height:var(--player-h);
      max-height:var(--player-h);
      flex:0 0 var(--player-h);
      overflow:hidden;
      display:flex;align-items:stretch;justify-content:stretch;
    }
    #jp_container{width:100%;height:100%;position:relative}
    #jquery_jplayer, #jquery_jplayer video{
      width:100% !important; height:100% !important; background:#000;
    }
    /* --- Lock jPlayer size classes so the player never resizes on load/change --- */
    #jp_container.jp-video,
    #jp_container.jp-video-270p,
    #jp_container.jp-video-360p,
    #jp_container.jp-video-full,
    #jp_container.jp-video-screen{
      width:100% !important;
      height:100% !important;
      max-height:100% !important;
    }
    #jp_container .jp-type-single{height:100% !important;}

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

    .info{padding:12px;color:var(--muted);font-size:13px;overflow:auto;flex:1 1 auto;min-height:0}

    @media (max-width:900px){
      :root{--player-h: clamp(240px, 38vh, 460px);}
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

 
    /* --- Header tabs --- */
    .tabs{display:flex;gap:8px;align-items:center}
    .tab{
      padding:8px 10px;border-radius:999px;border:1px solid var(--border);
      background:rgba(15,19,32,.6);color:var(--text);cursor:pointer;
      font-weight:700;font-size:12px;letter-spacing:.3px;
    }
    .tab.active{
      border-color:rgba(77,210,255,.6);
      box-shadow:0 0 0 1px rgba(77,210,255,.15) inset;
      color:#bfefff;
    }

    /* --- Fullscreen EPG modal --- */
    body.epg-open{overflow:hidden}
    .epg-modal{
      position:fixed;inset:0;z-index:9999;display:none;
      background:rgba(0,0,0,.72);
      backdrop-filter:blur(10px);
    }
    .epg-modal .inner{
      position:absolute;inset:12px;
      border:1px solid var(--border);
      border-radius:16px;
      background:linear-gradient(180deg,#0f1320,#0b0d12);
      display:flex;flex-direction:column;overflow:hidden;
    }
    .epg-topbar{
      display:flex;gap:10px;align-items:center;
      padding:10px;border-bottom:1px solid var(--border);
      flex-wrap:wrap;
    }
    .epg-topbar .title{font-weight:800;letter-spacing:.4px}
    .epg-topbar .mini{font-size:12px;color:var(--muted);white-space:nowrap}
    .epg-topbar .spacer{flex:1}
    .epg-topbar input,.epg-topbar select{
      padding:9px 10px;border-radius:10px;border:1px solid var(--border);
      background:var(--panel2);color:var(--text);outline:none
    }
    .epg-topbar button{
      padding:9px 10px;border-radius:10px;border:1px solid var(--border);
      background:linear-gradient(180deg,#1b2240,#10162b);
      color:var(--text);cursor:pointer;font-weight:800
    }
    .epg-topbar button.ghost{background:transparent}
    .epg-wrap{flex:1;overflow:auto;background:rgba(0,0,0,.15)}
    .epg-table{min-width:900px}
    .epg-head{
      position:sticky;top:0;z-index:30;display:flex;
      border-bottom:1px solid var(--border);
      background:rgba(11,13,18,.95);
      backdrop-filter:blur(8px);
    }
    .epg-head .left{
      width:280px;min-width:280px;
      position:sticky;left:0;z-index:40;
      border-right:1px solid var(--border);
      padding:10px;font-weight:800;
    }
    .epg-timebar{flex:1;display:flex}
    .epg-time-slot{
      height:44px;display:flex;align-items:flex-end;
      justify-content:flex-start;padding:6px 8px;
      border-right:1px solid rgba(255,255,255,.06);
      font-size:12px;color:var(--muted);white-space:nowrap
    }
    .epg-row{display:flex;min-height:58px;border-bottom:1px solid rgba(255,255,255,.06)}
    .epg-chcell{
      width:280px;min-width:280px;
      position:sticky;left:0;z-index:20;
      border-right:1px solid var(--border);
      background:rgba(15,19,32,.95);
      padding:8px 10px;cursor:pointer;
    }
    .epg-chcell:hover{background:rgba(15,19,32,1)}
    .epg-chname{font-weight:800;font-size:13px;line-height:1.15}
    .epg-chmeta{font-size:11px;color:var(--muted)}
    .epg-line{position:relative;flex:1}
    .epg-prog{
      position:absolute;top:7px;bottom:7px;
      border-radius:12px;border:1px solid rgba(77,210,255,.25);
      background:linear-gradient(180deg,rgba(77,210,255,.18),rgba(16,22,43,.8));
      padding:8px;overflow:hidden;cursor:pointer;
    }
    .epg-prog:hover{transform:translateY(-1px)}
    .epg-prog .t{
      font-weight:800;font-size:12px;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .epg-prog .tm{font-size:11px;color:var(--muted);margin-top:2px}
    .epg-prog.now{
      border-color:rgba(124,255,178,.45);
      background:linear-gradient(180deg,rgba(124,255,178,.18),rgba(16,22,43,.8));
    }
    .epg-empty{position:absolute;left:8px;top:18px;font-size:12px;color:var(--muted)}
    .epg-details{border-top:1px solid var(--border);padding:10px;display:none}
    .epg-details .dtitle{font-weight:900}
    .epg-details .dmeta{font-size:12px;color:var(--muted);margin-top:4px}
    .epg-details .ddesc{
      font-size:12px;color:#c7cbd6;margin-top:6px;
      max-height:90px;overflow:auto;
    }
    .epg-details .actions{margin-top:8px;display:flex;gap:8px;flex-wrap:wrap}
    .epg-details .actions button{
      padding:9px 10px;border-radius:10px;
      border:1px solid rgba(124,255,178,.35);
      background:linear-gradient(180deg,#122b1c,#0b0d12);
      color:var(--text);cursor:pointer;font-weight:900
    }
    .epg-details .actions button.secondary{
      border-color:var(--border);background:transparent;font-weight:700
    }

/* --- Auth (Login) modal --- */
body.auth-open{overflow:hidden}
.auth-modal{
  position:fixed;inset:0;z-index:10000;display:none;
  background:rgba(0,0,0,.72);
  backdrop-filter:blur(10px);
}
.auth-modal .inner{
  width:min(520px, calc(100% - 24px));
  margin:10vh auto 0;
  border:1px solid var(--border);
  border-radius:16px;
  background:linear-gradient(180deg,#0f1320,#0b0d12);
  padding:14px;
  box-shadow:0 20px 80px rgba(0,0,0,.55);
  overflow:hidden;
}
.auth-title{font-weight:900;letter-spacing:.4px;font-size:16px}
.auth-sub{font-size:12px;color:var(--muted);margin-top:4px;margin-bottom:10px}
.auth-actions{display:flex;gap:6px;margin-top:10px}
.auth-actions button{flex:1}

  </style>
</head>
<body>
  <header>
    <div style="display:flex;align-items:center;gap:12px;min-width:0">
      <h1>IPTV Web Player</h1>
      <div id="counts" class="pill" style="display:none"></div>
    </div>
    <div class="tabs" role="tablist" aria-label="Views">
      <button id="tabPlayer" class="tab active" role="tab" aria-selected="true" type="button">Player</button>
      <button id="tabEpg" class="tab" role="tab" aria-selected="false" type="button">Guide</button>
    </div>
  </header>

  <aside class="ui">
    <div class="section" id="accountSection" style="display:none">
  <h2>Account</h2>
  <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
    <div style="font-size:12px;color:var(--muted)" id="accountLabel">Signed in</div>
    <button class="secondary" id="logoutBtn" type="button">Logout</button>
  </div>
  <div class="status" id="status" style="margin-top:8px"></div>
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
      <div id="epgNextList"></div>
    </div>
  </main>

  <!-- Fullscreen EPG (OTT-style grid) -->
  <div class="epg-modal" id="epgModal" aria-hidden="true">
    <div class="inner">
      <div class="epg-topbar">
        <div class="title">TV Guide</div>
        <div class="mini" id="epgRange"></div>
        <div class="spacer"></div>
        <input id="epgSearch" placeholder="search channels or shows...">
        <select id="epgCategory">
          <option value="">All Categories</option>
        </select>
        <button class="ghost" id="epgPrev" title="Back 30 min" type="button">◀</button>
        <button class="ghost" id="epgNowBtn" type="button">Now</button>
        <button class="ghost" id="epgNext" title="Forward 30 min" type="button">▶</button>
        <button id="epgClose" title="Close" type="button">✕</button>
      </div>

      <div class="epg-wrap" id="epgWrap">
        <div class="epg-table" id="epgTable">
          <div class="epg-head">
            <div class="left">Channels</div>
            <div class="epg-timebar" id="epgTimeHeader"></div>
          </div>
          <div id="epgBody"></div>
        </div>
      </div>

      <div class="epg-details" id="epgDetails"></div>
    </div>
  </div>

<!-- Login modal (blocks page until validated) -->
<div class="auth-modal" id="authModal" aria-hidden="true">
  <div class="inner ui">
    <div class="auth-title">Sign in</div>
    <div class="auth-sub">Enter your account to load channels + guide.</div>

    <form id="authForm" autocomplete="on">
      <div class="login-grid">
        <div>
          <label>Username</label>
          <input id="username" placeholder="your username" autocomplete="username">
        </div>
        <div>
          <label>Password</label>
          <input id="password" placeholder="your password" type="password" autocomplete="current-password">
        </div>
        <div style="grid-column:1/-1">
          <label>Output (browser needs HLS)</label>
          <select id="outputMode">
            <option value="hls" selected>hls (web compatible)</option>
            <option value="ts">ts (VLC / apps)</option>
          </select>
        </div>
        <div class="auth-actions" style="grid-column:1/-1">
          <button id="loadBtn" type="submit">Login</button>
          <button class="secondary" id="clearBtn" type="button">Clear</button>
        </div>
      </div>
      <div class="status" id="authStatus"></div>
    </form>
  </div>
</div>

<script>
(() => {
  const els = {
    username: document.getElementById('username'),
    password: document.getElementById('password'),    outputMode: document.getElementById('outputMode'),
    loadBtn: document.getElementById('loadBtn'),
    clearBtn: document.getElementById('clearBtn'),
    status: document.getElementById('status'),
    authModal: document.getElementById('authModal'),
    authForm: document.getElementById('authForm'),
    authStatus: document.getElementById('authStatus'),
    accountSection: document.getElementById('accountSection'),
    logoutBtn: document.getElementById('logoutBtn'),
    accountLabel: document.getElementById('accountLabel'),
    search: document.getElementById('search'),
    groupSelect: document.getElementById('groupSelect'),
    groupChips: document.getElementById('groupChips'),
    channelList: document.getElementById('channelList'),
    nowPlaying: document.getElementById('nowPlaying'),
    counts: document.getElementById('counts'),
    tabPlayer: document.getElementById('tabPlayer'),
    tabEpg: document.getElementById('tabEpg'),
    epgModal: document.getElementById('epgModal'),
    epgSearch: document.getElementById('epgSearch'),
    epgCategory: document.getElementById('epgCategory'),
    epgPrev: document.getElementById('epgPrev'),
    epgNext: document.getElementById('epgNext'),
    epgNowBtn: document.getElementById('epgNowBtn'),
    epgClose: document.getElementById('epgClose'),
    epgRange: document.getElementById('epgRange'),
    epgTimeHeader: document.getElementById('epgTimeHeader'),
    epgBody: document.getElementById('epgBody'),
    epgDetails: document.getElementById('epgDetails'),
  };

  let channels = [];
  let groups = [];
  let activeGroup = '';
  let activeChannelId = null;
  let favorites = new Set(JSON.parse(localStorage.getItem('iptv_favs') || '[]'));
  let hls = null;
  let isAuthed = false;

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
      if (document.body.classList.contains("epg-open")) { renderEpgGrid(); }
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
    const nextBox = document.getElementById("epgNextList");
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
  // ---------------- Fullscreen OTT-style EPG ----------------
  const EPG_SLOT_MIN = 30;      // step
  const EPG_WINDOW_MIN = 240;   // 4 hours visible
  const EPG_PPM = 4;            // pixels per minute (controls density)
  const EPG_MAX_ROWS = 220;

  let epgViewStart = null;

  function setActiveTab(which){
    if (!els.tabPlayer || !els.tabEpg) return;
    const isPlayer = which === 'player';
    els.tabPlayer.classList.toggle('active', isPlayer);
    els.tabEpg.classList.toggle('active', !isPlayer);
    els.tabPlayer.setAttribute('aria-selected', isPlayer ? 'true' : 'false');
    els.tabEpg.setAttribute('aria-selected', !isPlayer ? 'true' : 'false');
  }

  function floorToStep(d, stepMin){
    const ms = d.getTime();
    const step = stepMin * 60_000;
    return new Date(Math.floor(ms / step) * step);
  }
  function addMinutes(d, mins){ return new Date(d.getTime() + mins*60_000); }
  function minsBetween(a,b){ return (a.getTime() - b.getTime()) / 60_000; }

  function renderTimeHeader(start){
    if (!els.epgTimeHeader) return;
    const slotW = EPG_SLOT_MIN * EPG_PPM;
    let html = "";
    for (let m = 0; m < EPG_WINDOW_MIN; m += EPG_SLOT_MIN){
      const t = addMinutes(start, m);
      const label = (t.getMinutes() === 0)
        ? t.toLocaleTimeString([], {hour:'numeric'})
        : "";
      html += `<div class="epg-time-slot" style="width:${slotW}px">${escapeHtml(label)}</div>`;
    }
    els.epgTimeHeader.innerHTML = html;
  }

  function filterEpgChannels(start, end){
    const q = (els.epgSearch?.value || "").toLowerCase().trim();
    const g = (els.epgCategory?.value || "");
    let list = channels;

    if (g) list = list.filter(c => c.group === g);
    if (!q) return list;

    return list.filter(c => {
      if ((c.name||"").toLowerCase().includes(q)) return true;

      if (!epgMap) return false;
      const id = findBestEpgIdForChannel(c);
      if (!id) return false;

      const arr = epgMap.get(id) || [];
      for (const it of arr){
        if (!it.start || !it.stop) continue;
        if (it.stop < start) continue;
        if (it.start > end) break; // list is sorted
        if ((it.title||"").toLowerCase().includes(q)) return true;
      }
      return false;
    });
  }

  function renderEpgGrid(){
    if (!els.epgBody) return;

    if (!epgViewStart) epgViewStart = floorToStep(new Date(), EPG_SLOT_MIN);
    const start = epgViewStart;
    const end = addMinutes(start, EPG_WINDOW_MIN);
    const now = new Date();

    if (els.epgRange){
      const day = start.toLocaleDateString([], {weekday:'short', month:'short', day:'numeric'});
      const a = start.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
      const b = end.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
      els.epgRange.textContent = `${day} • ${a} - ${b}`;
    }

    renderTimeHeader(start);

    if (!epgMap){
      els.epgBody.innerHTML = `<div style="padding:14px;color:var(--muted)">EPG not loaded yet. Load the playlist first (or wait a second).</div>`;
      return;
    }

    const visible = filterEpgChannels(start, end);
    const list = visible.slice(0, EPG_MAX_ROWS);
    const trimmed = visible.length > list.length;

    const lineW = EPG_WINDOW_MIN * EPG_PPM;

    els.epgBody.innerHTML = list.map(c => {
      const epgId = findBestEpgIdForChannel(c);
      const arr = epgId ? (epgMap.get(epgId) || []) : [];
      const blocks = [];

      if (epgId && arr.length){
        for (const it of arr){
          if (!it.start || !it.stop) continue;
          if (it.stop <= start) continue;
          if (it.start >= end) break;

          const s = it.start < start ? start : it.start;
          const e = it.stop  > end   ? end   : it.stop;

          const left = Math.max(0, minsBetween(s, start) * EPG_PPM);
          const width = Math.max(12, minsBetween(e, s) * EPG_PPM);

          const isNow = it.start <= now && it.stop >= now;
          const timeStr = (it.start && it.stop) ? fmtTimeRange(it.start, it.stop) : "";

          blocks.push(`
            <div class="epg-prog ${isNow?'now':''}"
                 style="left:${left}px;width:${width}px"
                 data-ch="${c.id}"
                 data-epgid="${escapeAttr(epgId)}"
                 data-s="${it.start.getTime()}"
                 data-e="${it.stop.getTime()}">
              <div class="t">${escapeHtml(it.title || 'Untitled')}</div>
              <div class="tm">${escapeHtml(timeStr)}</div>
            </div>
          `);
        }
      }

      const empty = (!blocks.length)
        ? `<div class="epg-empty">${epgId ? "No data in this window" : "No EPG match"}</div>`
        : "";

      return `
        <div class="epg-row" data-id="${c.id}">
          <div class="epg-chcell" title="Click to play">
            <div class="epg-chname">${escapeHtml(c.name)}</div>
            <div class="epg-chmeta">${escapeHtml(c.group || '')}</div>
          </div>
          <div class="epg-line" style="width:${lineW}px">
            ${empty}
            ${blocks.join("")}
          </div>
        </div>
      `;
    }).join('') + (trimmed ? `<div style="padding:10px;color:var(--muted);font-size:12px">Showing first ${EPG_MAX_ROWS} channels (use search/category to narrow).</div>` : "");
  }

  function showEpgDetails(channelObj, programme){
    if (!els.epgDetails) return;

    if (!channelObj){
      els.epgDetails.style.display = "none";
      return;
    }

    const title = programme?.title ? programme.title : "No programme info";
    const meta = (programme?.start && programme?.stop) ? fmtTimeRange(programme.start, programme.stop) : "";
    const desc = programme?.desc ? programme.desc : "";

    els.epgDetails.innerHTML = `
      <div class="dtitle">${escapeHtml(title)}</div>
      <div class="dmeta">${escapeHtml(channelObj.name)} • ${escapeHtml(meta)}</div>
      ${desc ? `<div class="ddesc">${escapeHtml(desc)}</div>` : `<div class="ddesc" style="color:var(--muted)">No description.</div>`}
      <div class="actions">
        <button type="button" id="epgPlayBtn">Play Channel</button>
        <button type="button" class="secondary" id="epgHideBtn">Hide</button>
      </div>
    `;
    els.epgDetails.style.display = "block";

    const playBtn = document.getElementById("epgPlayBtn");
    const hideBtn = document.getElementById("epgHideBtn");
    playBtn && playBtn.addEventListener("click", () => {
      playChannel(channelObj);
      closeEpg();
    });
    hideBtn && hideBtn.addEventListener("click", () => { els.epgDetails.style.display = "none"; });
  }

  function openEpg(){
    if (!els.epgModal) return;
    if (!isAuthed){ showAuth("Sign in to continue."); return; }

    document.body.classList.add("epg-open");
    els.epgModal.style.display = "block";
    els.epgModal.setAttribute("aria-hidden", "false");
    setActiveTab("epg");

    if (!epgViewStart) epgViewStart = floorToStep(new Date(), EPG_SLOT_MIN);
    renderEpgGrid();
  }

  function closeEpg(){
    if (!els.epgModal) return;

    document.body.classList.remove("epg-open");
    els.epgModal.style.display = "none";
    els.epgModal.setAttribute("aria-hidden", "true");
    setActiveTab("player");

    if (els.epgDetails) els.epgDetails.style.display = "none";
  }
  // -------------- /Fullscreen OTT-style EPG --------------


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
  if (els.status){
    els.status.className = 'status ' + type;
    els.status.textContent = msg || '';
  }
  if (els.authStatus){
    els.authStatus.className = 'status ' + type;
    els.authStatus.textContent = msg || '';
  }
}

function showAuth(msg=''){
  if (!els.authModal) return;
  document.body.classList.add('auth-open');
  els.authModal.style.display = 'block';
  els.authModal.setAttribute('aria-hidden','false');
  if (msg) setStatus(msg);
  setTimeout(() => {
    if (els.username && !els.username.value) els.username.focus();
    else if (els.password) els.password.focus();
  }, 0);
}

function hideAuth(){
  if (!els.authModal) return;
  document.body.classList.remove('auth-open');
  els.authModal.style.display = 'none';
  els.authModal.setAttribute('aria-hidden','true');
  if (els.authStatus) els.authStatus.textContent = '';
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

    // Fill categories in fullscreen Guide
    if (els.epgCategory){
      els.epgCategory.innerHTML = `<option value="">All Categories</option>` +
        groups.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
    }
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

    if (!u || !p) { setStatus("Missing username or password","err"); return false; }
    setStatus("Validating account...");

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
      if (!channels.length) {
        setStatus("Invalid login or empty playlist (no channels parsed).", "err");
        return false;
      }

      // validated ✅
      isAuthed = true;
      saveCreds();
      if (els.accountSection) els.accountSection.style.display = "block";
      if (els.accountLabel) els.accountLabel.textContent = `Signed in as: ${u}`;
      if (els.counts) els.counts.style.display = "inline-flex";

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
      hideAuth();
      return true;
    } catch (e) {
      setStatus("Network error loading playlist.\n"+e.message,"err");
      return false;
    }
  }

  const doLogin = async (e) => {
    e && e.preventDefault();
    await loadPlaylist();
  };
  els.authForm && els.authForm.addEventListener("submit", doLogin);
  els.loadBtn && els.loadBtn.addEventListener("click", doLogin);
  els.clearBtn.addEventListener("click", () => {
    closeEpg();
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
    const epgNext = document.getElementById("epgNextList");
    if (epgNow) epgNow.innerHTML = "";
    if (epgNext) epgNext.innerHTML = "";
    epgSetStatus("EPG not loaded yet.");

    setStatus("Signed out.", "ok");
    isAuthed = false;
    if (els.accountSection) els.accountSection.style.display = "none";
    showAuth("Sign in to continue.");
  });


  // Logout button (visible after login)
  els.logoutBtn && els.logoutBtn.addEventListener("click", () => {
    // reuse the same clear routine
    els.clearBtn && els.clearBtn.click();
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


  // ---------- Fullscreen EPG events ----------
  els.tabPlayer && els.tabPlayer.addEventListener("click", closeEpg);
  els.tabEpg && els.tabEpg.addEventListener("click", openEpg);
  els.epgClose && els.epgClose.addEventListener("click", closeEpg);

  els.epgPrev && els.epgPrev.addEventListener("click", () => {
    epgViewStart = addMinutes(epgViewStart || floorToStep(new Date(), EPG_SLOT_MIN), -EPG_SLOT_MIN);
    renderEpgGrid();
  });
  els.epgNext && els.epgNext.addEventListener("click", () => {
    epgViewStart = addMinutes(epgViewStart || floorToStep(new Date(), EPG_SLOT_MIN), +EPG_SLOT_MIN);
    renderEpgGrid();
  });
  els.epgNowBtn && els.epgNowBtn.addEventListener("click", () => {
    epgViewStart = floorToStep(new Date(), EPG_SLOT_MIN);
    renderEpgGrid();
  });

  els.epgSearch && els.epgSearch.addEventListener("input", () => {
    renderEpgGrid();
  });
  els.epgCategory && els.epgCategory.addEventListener("change", () => {
    renderEpgGrid();
  });

  if (els.epgBody){
    els.epgBody.addEventListener("click", (e) => {
      const prog = e.target.closest(".epg-prog");
      if (prog){
        const chId = Number(prog.dataset.ch);
        const epgId = prog.dataset.epgid || "";
        const s = Number(prog.dataset.s || 0);
        const ee = Number(prog.dataset.e || 0);

        const ch = channels.find(x => x.id === chId) || null;
        const arr = (epgMap && epgId) ? (epgMap.get(epgId) || []) : [];
        const item = arr.find(it => it.start && it.stop && it.start.getTime() === s && it.stop.getTime() === ee) || null;

        showEpgDetails(ch, item);
        return;
      }

      const cell = e.target.closest(".epg-chcell");
      if (cell){
        const row = cell.closest(".epg-row");
        const id = row ? Number(row.dataset.id) : null;
        const ch = channels.find(x => x.id === id) || null;
        if (ch){ playChannel(ch); closeEpg(); }
      }
    });
  }

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && document.body.classList.contains("epg-open")) closeEpg();
  });

// ---------- PHP session autologin ----------
const AUTO_USER = <?= json_encode($autoUser) ?>;
const AUTO_PASS = <?= json_encode($autoPass) ?>;

// Always start blocked by the login modal, then auto-validate if creds exist
showAuth("Sign in to continue.");

(async () => {
  if (AUTO_USER && AUTO_PASS) {
    els.username.value = AUTO_USER;
    els.password.value = AUTO_PASS;
  }

  // LocalStorage fallback
  if (!els.username.value) els.username.value = localStorage.getItem('iptv_user') || '';
  if (!els.password.value) els.password.value = localStorage.getItem('iptv_pass') || '';
  els.outputMode.value = localStorage.getItem('iptv_out') || 'hls';
  if (els.outputMode.value !== 'hls') els.outputMode.value = 'hls';

  // Auto-login if we have creds
  if (els.username.value && els.password.value) {
    setStatus("Validating account...");
    await loadPlaylist();
  }
})();
})();
</script>
</body>
</html>
