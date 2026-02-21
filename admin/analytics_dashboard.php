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
  <meta charset="utf-8">
  <title>Analytics Dashboard</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
  <style>
    .gcad-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .gcad-kv{display:flex;align-items:center;gap:8px}
    .gcad-kv label{margin:0;font-weight:700;opacity:.85}
    .gcad-kv select{width:auto;min-width:120px}
    .gcad-pills{display:flex;gap:10px;flex-wrap:wrap}
    .gcad-pill{display:flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;background:rgba(0,0,0,.06);text-align:center;line-height:1;white-space:nowrap}
    .gcad-card{border-radius:16px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,.12)}
    .gcad-canvas{width:100%;height:260px;display:block}
    .gcad-title{font-weight:900;margin:0 0 4px 0}
    .gcad-sub{opacity:.75;margin:0 0 10px 0}
    .gcad-mini{font-weight:800}
    .gcad-muted{opacity:.7}
    .gcad-footer{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:6px}
    .gcad-legend{display:flex;gap:12px;align-items:center}
    .gcad-legend span{display:flex;align-items:center;gap:6px}
    .gcad-dot{width:10px;height:10px;border-radius:2px;display:inline-block;background:#999}

    .gcad-pill{justify-content:center;text-align:center}
    .gcad-grid{display:flex;flex-wrap:wrap;gap:16px}
    .gcad-col{flex:1 1 calc(50% - 16px);min-width:320px}
    @media (max-width: 900px){.gcad-col{flex:1 1 100%}}
    
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card mb-3">
  <div class="card-body gcad-controls">
    <div class="gcad-kv">
      <label>Window</label>
      <select id="adHours" class="form-select form-select-sm">
        <option value="1">1h</option>
        <option value="6">6h</option>
        <option value="12">12h</option>
        <option value="24" selected>24h</option>
        <option value="48">48h</option>
        <option value="168">7d</option>
      </select>
    </div>

    <div class="gcad-kv">
      <label>Refresh</label>
      <select id="adRefresh" class="form-select form-select-sm">
        <option value="2">2s</option>
        <option value="5">5s</option>
        <option value="10" selected>10s</option>
        <option value="30">30s</option>
      </select>
    </div>

    <div class="gcad-pills">
      <span class="gcad-pill">LIVE (5m): <b id="adActiveNow">—</b></span>
      <span class="gcad-pill">Requests (24h): <b id="adReqTotal">—</b></span>
      <span class="gcad-pill">Bad req (24h): <b id="adReqBad">—</b></span>
      <span class="gcad-pill">Top device: <b id="adTopDev">—</b></span>
      <span class="gcad-pill gcad-muted" id="adStatus">Loading…</span>
    </div>
  </div>
</div>

<div class="gcad-grid">
<div class="gcad-col">
    <div class="card gcad-card">
      <div class="card-body">
        <h3 class="gcad-title">Active Sessions</h3>
        <p class="gcad-sub">Bucketed over time</p>
        <canvas id="cSessions" class="gcad-canvas"></canvas>
        <div class="gcad-footer"><span class="gcad-muted">Points: <span id="pSessions">0</span></span></div>
      </div>
    </div>
  </div>

  <div class="gcad-col">
    <div class="card gcad-card">
      <div class="card-body">
        <h3 class="gcad-title">Requests: Total vs Bad</h3>
        <p class="gcad-sub">Total requests and failures</p>
        <canvas id="cRequests" class="gcad-canvas"></canvas>
        <div class="gcad-footer">
          <div class="gcad-legend">
            <span><i class="gcad-dot" style="background:#22c55e"></i> Total</span>
            <span><i class="gcad-dot" style="background:#ef4444"></i> Bad</span>
          </div>
          <span class="gcad-muted">Points: <span id="pRequests">0</span></span>
        </div>
      </div>
    </div>
  </div>

  <div class="gcad-col">
    <div class="card gcad-card">
      <div class="card-body">
        <h3 class="gcad-title">Devices</h3>
        <p class="gcad-sub">Top user agents grouped</p>
        <canvas id="cDevices" class="gcad-canvas"></canvas>
      </div>
    </div>
  </div>

  <div class="gcad-col">
    <div class="card gcad-card">
      <div class="card-body">
        <h3 class="gcad-title">Top Reasons</h3>
        <p class="gcad-sub">Why requests fail / outcomes</p>
        <canvas id="cReasons" class="gcad-canvas"></canvas>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const $ = (id)=>document.getElementById(id);

  function fitCanvas(cv){
    const rect = cv.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    const w = Math.max(300, Math.floor(rect.width));
    const h = Math.max(200, Math.floor(rect.height));
    cv.width = Math.floor(w * dpr);
    cv.height = Math.floor(h * dpr);
    const ctx = cv.getContext('2d');
    ctx.setTransform(dpr,0,0,dpr,0,0);
    return {ctx, w, h};
  }

  function axes(ctx,w,h,pad){
    ctx.globalAlpha = 1;
    ctx.lineWidth = 1;
    ctx.strokeStyle = 'rgba(0,0,0,.12)';
    ctx.beginPath();
    ctx.moveTo(pad, pad);
    ctx.lineTo(pad, h-pad);
    ctx.lineTo(w-pad, h-pad);
    ctx.stroke();
    ctx.strokeStyle = 'rgba(0,0,0,.06)';
    for(let i=1;i<=3;i++){
      const y = pad + (h-2*pad)*(i/4);
      ctx.beginPath(); ctx.moveTo(pad,y); ctx.lineTo(w-pad,y); ctx.stroke();
    }
  }

  function lineChart(cv, labels, series){
    const {ctx,w,h} = fitCanvas(cv);
    const pad = 34;
    ctx.clearRect(0,0,w,h);
    axes(ctx,w,h,pad);

    const all = [].concat(...series.map(s=>s.data));
    const maxV = Math.max(1, ...all);
    const minV = Math.min(0, ...all);

    const n = labels.length;
    if(n < 2){ return; }

    function xy(i,v){
      const x = pad + (w-2*pad) * (i/(n-1));
      const t = (v-minV)/(maxV-minV || 1);
      const y = (h-pad) - (h-2*pad)*t;
      return [x,y];
    }

    ctx.fillStyle = 'rgba(0,0,0,.55)';
    ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    ctx.fillText(String(maxV), 6, pad+4);
    ctx.fillText(String(minV), 6, h-pad+4);

    ctx.fillStyle = 'rgba(0,0,0,.45)';
    if(labels[0]) ctx.fillText(labels[0], pad, h-10);

    series.forEach(s=>{
      ctx.lineWidth = 2;
      ctx.strokeStyle = s.color;
      ctx.beginPath();
      for(let i=0;i<n;i++){
        const [x,y] = xy(i, s.data[i] ?? 0);
        if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
      }
      ctx.stroke();
    });
  }

  function barChart(cv, labels, values, color){
    const {ctx,w,h} = fitCanvas(cv);
    const pad = 34;
    ctx.clearRect(0,0,w,h);
    axes(ctx,w,h,pad);

    const maxV = Math.max(1, ...values);
    const n = labels.length;
    if(n === 0) return;

    const barW = (w-2*pad) / n;
    for(let i=0;i<n;i++){
      const v = values[i] || 0;
      const bh = (h-2*pad) * (v/maxV);
      const x = pad + i*barW + 6;
      const y = (h-pad) - bh;
      ctx.fillStyle = color || 'rgba(99,102,241,.75)';
      ctx.fillRect(x, y, Math.max(10, barW-12), bh);
    }

    ctx.fillStyle = 'rgba(0,0,0,.55)';
    ctx.font = '12px system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
    const maxLabels = Math.min(n, 4);
    for(let i=0;i<maxLabels;i++){
      ctx.fillText(labels[i], pad + i*barW + 6, pad-8);
    }
  }

  function sum(arr){ return (arr||[]).reduce((a,b)=>a+(+b||0),0); }

  async function loadAndDraw(){
    const hours = +$('adHours').value || 24;
    const refresh = +$('adRefresh').value || 10;
    $('adStatus').textContent = 'Loading…';

    const url = `ajax/analytics_dashboard.php?hours=${encodeURIComponent(hours)}&bucket=5&_=${Date.now()}`;
    const res = await fetch(url, {credentials:'same-origin'});
    const data = await res.json();

    if(!data || !data.ok){
      $('adStatus').textContent = 'No data';
      return;
    }

    const labels = data.labels || [];
    const sActive = (data.series && data.series.active_sessions) ? data.series.active_sessions : [];
    const sTot = (data.series && data.series.requests_total) ? data.series.requests_total : [];
    const sBad = (data.series && data.series.requests_bad) ? data.series.requests_bad : [];

    $('pSessions').textContent = String(labels.length);
    $('pRequests').textContent = String(labels.length);

    const reqTotal = sum(sTot);
    const reqBad = sum(sBad);

    $('adReqTotal').textContent = String(reqTotal);
    $('adReqBad').textContent = String(reqBad);

    // LIVE (5m): fetch a short window so it matches "right now" instead of the 24h history
    try{
      const liveRes = await fetch(`ajax/analytics_dashboard.php?hours=0.2&bucket=1&_=${Date.now()}`, {credentials:'same-origin'});
      const live = await liveRes.json();
      const la = (live && live.series && Array.isArray(live.series.active_sessions)) ? live.series.active_sessions : [];
      const liveNow = (la.length) ? (la[la.length-1] || 0) : 0;
      $('adActiveNow').textContent = String(liveNow);
    }catch(e){
          }

    
    // top device
    let topDev = '—';
    if(data.devices && Array.isArray(data.devices.labels) && Array.isArray(data.devices.values) && data.devices.labels.length){
      let mi = 0;
      for(let i=1;i<data.devices.values.length;i++){
        if((data.devices.values[i]||0) > (data.devices.values[mi]||0)) mi = i;
      }
      topDev = `${data.devices.labels[mi]} (${data.devices.values[mi]||0})`;
    }
    $('adTopDev').textContent = topDev;

    // charts
    lineChart($('cSessions'), labels, [{color:'#3b82f6', data: sActive}]);
    lineChart($('cRequests'), labels, [
      {color:'#22c55e', data: sTot},
      {color:'#ef4444', data: sBad}
    ]);

    const dLabels = (data.devices && data.devices.labels) ? data.devices.labels : [];
    const dVals = (data.devices && data.devices.values) ? data.devices.values : [];
    barChart($('cDevices'), dLabels, dVals, 'rgba(99,102,241,.75)');

    const rLabels = (data.reasons && data.reasons.labels) ? data.reasons.labels : [];
    const rVals = (data.reasons && data.reasons.values) ? data.reasons.values : [];
    barChart($('cReasons'), rLabels, rVals, 'rgba(99,102,241,.75)');

    $('adStatus').textContent = `Updated • ${new Date().toLocaleTimeString()}`;

    clearTimeout(window.__adTimer);
    window.__adTimer = setTimeout(loadAndDraw, refresh*1000);
  }

  $('adHours').addEventListener('change', ()=>loadAndDraw());
  $('adRefresh').addEventListener('change', ()=>loadAndDraw());
  window.addEventListener('resize', ()=>loadAndDraw());

  loadAndDraw();
})();
</script>

</div><!-- container -->
</main>
</div><!-- app -->
</body>
</html>
