<?php
require_once __DIR__ . '/../api_common.php';
require_once __DIR__ . '/../auth.php';
require_admin();

$topbar = file_get_contents(__DIR__ . '/topbar.html');
$topbar = str_replace('{{USERNAME}}', e($_SESSION['admin_username'] ?? 'Admin'), $topbar);
?>
<!doctype html>
<html>
<head>
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

  <meta charset="utf-8">
  <title>Live Map</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">

  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
  <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>


  <style>
    .lm-top{ display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
    .lm-pill{ display:inline-flex; align-items:center; gap:10px; padding:10px 14px; border-radius:12px; background:rgba(0,0,0,.06); }
    .lm-pill b{ font-weight:800; }
    .lm-kv{ display:flex; gap:10px; align-items:center; }
    .lm-kv label{ margin:0; font-weight:700; opacity:.85; }
    .lm-kv input, .lm-kv select{ width:auto; min-width:110px; }
    #lmMapWrap{ border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,.15); }
    #lmMap{ height:72vh; width:100%; }
    /* neon dot */
    .lm-dot{ width:10px; height:10px; border-radius:50%; background:#22d3ee; box-shadow:0 0 10px rgba(34,211,238,.9), 0 0 25px rgba(34,211,238,.55); border:1px solid rgba(255,255,255,.35); }
    /* apply neon tint ONLY to tiles */
    .lm-neon-tiles .leaflet-tile-pane{ filter: hue-rotate(190deg) saturate(1.85) brightness(1.15) contrast(1.12); }
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card">
  <div class="lm-top">
    <h2 style="margin:0;">Live Map</h2>
    <div class="lm-pill" id="lmCounts">
      <span><b>Pins</b> <span id="lmPins">0</span></span>
      <span><b>IPs</b> <span id="lmIps">0</span></span>
      <span><b>Sessions</b> <span id="lmSessions">0</span></span>
      <span><b>Lookups</b> <span id="lmLookups">0</span></span>
    </div>
    <div class="lm-kv">
      <label>Window</label>
      <input type="number" id="lmWindow" value="300" min="10" max="3600" step="10"> <span class="opacity-75">sec</span>
    </div>
    <div class="lm-kv">
      <label>Theme</label>
      <select id="lmTheme">
                <option value="dark" selected>Dark</option>
        <option value="satellite">Satellite</option>

      </select>
    </div>
    <div class="lm-kv">
      <label>Refresh</label>
      <select id="lmRefresh">
        <option value="2">2s</option>
        <option value="5" selected>5s</option>
        <option value="10">10s</option>
        <option value="30">30s</option>
      </select>
    </div>
    <div class="opacity-75" id="lmStatus">—</div>
  </div>
</div>

<div id="lmMapWrap">
  <div id="lmMap"></div>
</div>

<script>
(function(){
  var map = L.map('lmMap', { zoomControl:true, worldCopyJump:true }).setView([20, 0], 2);

  var osm = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  });
  var cartoDark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    subdomains: 'abcd',
    maxZoom: 20,
    attribution: '&copy; OpenStreetMap &copy; CARTO'
  });
  var cartoLight = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
    subdomains: 'abcd',
    maxZoom: 20,
    attribution: '&copy; OpenStreetMap &copy; CARTO'
  });

  var esriSat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 19,
    attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community'
  });

  var layers = { dark: cartoDark, satellite: esriSat };
  layers.dark.addTo(map);

  var markers = L.markerClusterGroup({spiderfyOnMaxZoom:true, showCoverageOnHover:false, zoomToBoundsOnClick:true});
map.addLayer(markers);
function setTheme(t){
    // swap base tiles
    Object.keys(layers).forEach(function(k){
      try{ map.removeLayer(layers[k]); }catch(e){}
    });
    (layers[t] || layers.dark).addTo(map);

    // neon filter only when requested
    var wrap = document.getElementById('lmMapWrap');
    if(!wrap) return;
    if(t === 'neon') wrap.classList.add('lm-neon-tiles');
    else wrap.classList.remove('lm-neon-tiles');
  }

  function esc(s){
    return String(s || '').replace(/[&<>"]+/g, function(ch){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[ch] || ch;
    });
  }

  function setCounts(st){
    document.getElementById('lmPins').textContent = st.pins || 0;
    document.getElementById('lmIps').textContent = st.ips || 0;
    document.getElementById('lmSessions').textContent = st.sessions || 0;
    document.getElementById('lmLookups').textContent = st.lookups || 0;
  }

  function renderPins(pins){
    markers.clearLayers();
    if(!pins || !pins.length) return;

    pins.forEach(function(p){
      var icon = L.divIcon({ className:'', html:'<div class="lm-dot"></div>', iconSize:[12,12], iconAnchor:[6,6] });
      var m = L.marker([p.lat, p.lon], { icon: icon });
      var loc = [p.city, p.region, p.country].filter(Boolean).join(', ');
      var types = '';
      if(p.types){
        var parts=[];
        Object.keys(p.types).forEach(function(k){ parts.push(k+': '+p.types[k]); });
        if(parts.length) types = '<div class="opacity-75" style="margin-top:4px;">'+esc(parts.join(' | '))+'</div>';
      }
      var html = '<div style="min-width:220px;">'
        + '<div style="font-weight:800;">'+esc(p.ip)+'</div>'
        + (loc ? '<div>'+esc(loc)+'</div>' : '')
        + (p.isp ? '<div class="opacity-75" style="margin-top:4px;">'+esc(p.isp)+'</div>' : '')
        + '<div style="margin-top:6px;"><b>Sessions</b> '+esc(p.sessions || 0)+'</div>'
        + types
        + '</div>';
      m.bindPopup(html);
      markers.addLayer(m);
    });
  }

  var timer = null;
  function stop(){ if(timer){ clearInterval(timer); timer=null; } }

  function tick(){
    var w = parseInt(document.getElementById('lmWindow').value || '300', 10) || 300;
    if(w < 10) w = 10;
    if(w > 3600) w = 3600;
    var url = 'ajax/live_map_live.php?window=' + encodeURIComponent(w) + '&_=' + Date.now();
    fetch(url, { credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if(!j || !j.ok) {
          document.getElementById('lmStatus').textContent = 'ERR';
          setCounts({pins:0,ips:0,sessions:0,lookups:0});
          renderPins([]);
          return;
        }
        setCounts(j.stats || {});
        renderPins(j.pins || []);
        document.getElementById('lmStatus').textContent = 'OK ' + (j.ts || '');
      })
      .catch(function(){
        document.getElementById('lmStatus').textContent = 'ERR';
      });
  }

  function start(){
    stop();
    var s = parseInt(document.getElementById('lmRefresh').value || '5', 10) || 5;
    if(s < 1) s = 1;
    tick();
    timer = setInterval(tick, s * 1000);
  }

  // wire UI
  var themeSel = document.getElementById('lmTheme');
  var savedTheme = localStorage.getItem('lmTheme');
  if(savedTheme && themeSel){ themeSel.value = savedTheme; }
  setTheme((themeSel && themeSel.value) || 'neon');

  if(themeSel){
    themeSel.addEventListener('change', function(){
      localStorage.setItem('lmTheme', themeSel.value);
      setTheme(themeSel.value);
    });
  }

  document.getElementById('lmRefresh').addEventListener('change', start);
  document.getElementById('lmWindow').addEventListener('change', start);

  start();
})();
</script>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
