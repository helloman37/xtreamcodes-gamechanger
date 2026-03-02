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
  <title>Anomaly Center</title>
  <link rel="stylesheet" href="assets/xui/css/xui.min.css">
  <link rel="stylesheet" href="panel.css?v=<?php echo @filemtime(__DIR__ . '/panel.css') ?: 1; ?>">
  <style>
    .ac-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .ac-kv{display:flex;align-items:center;gap:8px}
    .ac-kv label{margin:0;font-weight:800;opacity:.85}
    .ac-kv select{width:auto;min-width:140px}
    .ac-pills{display:flex;gap:10px;flex-wrap:wrap}
    .ac-pill{display:flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:999px;background:rgba(0,0,0,.06);text-align:center;line-height:1;white-space:nowrap}
    .ac-muted{opacity:.75}
    .ac-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:rgba(0,0,0,.06);font-weight:800;font-size:12px;margin-right:6px;white-space:nowrap}
    .ac-badge.red{background:rgba(239,68,68,.14)}
    .ac-badge.amber{background:rgba(245,158,11,.14)}
    .ac-badge.blue{background:rgba(59,130,246,.14)}
    .ac-badge.purple{background:rgba(168,85,247,.14)}
    .ac-table td{vertical-align:top}
    .ac-reasons{max-width:520px;word-break:break-word}
    .ac-user{font-weight:900}
    .ac-small{font-size:12px;opacity:.8}
    .ac-actions a{font-weight:800}
  </style>
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
<?= $topbar ?>

<div class="card mb-3">
  <div class="card-body ac-controls">
    <div class="ac-kv">
      <label>Window</label>
      <select id="acWindow" class="form-select form-select-sm">
        <option value="30">30 min</option>
        <option value="60">1 hour</option>
        <option value="180" selected>3 hours</option>
        <option value="360">6 hours</option>
        <option value="720">12 hours</option>
        <option value="1440">24 hours</option>
        <option value="4320">3 days</option>
        <option value="10080">7 days</option>
      </select>
    </div>

    <div class="ac-kv">
      <label>Refresh</label>
      <select id="acRefresh" class="form-select form-select-sm">
        <option value="0">Off</option>
        <option value="5">5s</option>
        <option value="10" selected>10s</option>
        <option value="30">30s</option>
      </select>
    </div>

    <div class="ac-pills">
      <span class="ac-pill">Flagged users: <b id="acFlagged">—</b></span>
      <span class="ac-pill">Total hits: <b id="acHits">—</b></span>
      <span class="ac-pill">Bad hits: <b id="acBad">—</b></span>
      <span class="ac-pill ac-muted" id="acStatus">Loading…</span>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body" style="overflow:auto;">
    <table class="ac-table table table-striped" style="min-width:1100px;">
      <thead>
        <tr>
          <th>Last seen</th>
          <th>User</th>
          <th>Reseller</th>
          <th>Hits</th>
          <th>IPs</th>
          <th>Device FPs</th>
          <th>Errors</th>
          <th>Streams</th>
          <th>Reasons</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="acBody">
        <tr><td colspan="10" class="ac-muted">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const $ = (id)=>document.getElementById(id);
  const elWin = $('acWindow');
  const elRef = $('acRefresh');
  const elBody = $('acBody');
  const elStatus = $('acStatus');

  let tmr = null;

  function esc(s){
    return String(s ?? '').replace(/[&<>"']/g, (c)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'
    }[c]||c));
  }

  function badge(text, cls){
    return `<span class="ac-badge ${cls||''}">${esc(text)}</span>`;
  }

  async function load(){
    const minutes = parseInt(elWin.value||'180', 10) || 180;
    elStatus.textContent = 'Loading…';

    try{
      const url = `ajax/anomaly_center.php?minutes=${encodeURIComponent(minutes)}&_=${Date.now()}`;
      const res = await fetch(url, {credentials:'same-origin'});
      const j = await res.json();
      if(!j || !j.ok){
        throw new Error(j && j.error ? j.error : 'Failed');
      }

      $('acFlagged').textContent = String(j.totals.flagged ?? 0);
      $('acHits').textContent = String(j.totals.hits ?? 0);
      $('acBad').textContent = String(j.totals.bad_hits ?? 0);

      const rows = Array.isArray(j.rows) ? j.rows : [];
      if(!rows.length){
        elBody.innerHTML = `<tr><td colspan="10" class="ac-muted">No anomalies in this window.</td></tr>`;
      } else {
        elBody.innerHTML = rows.map(r=>{
          const reasons = (r.reasons || []).map(x=>{
            let cls = 'blue';
            const low = String(x).toLowerCase();
            if(low.includes('error')) cls = 'red';
            else if(low.includes('ip')) cls = 'amber';
            else if(low.includes('fingerprint') || low.includes('device')) cls = 'purple';
            else if(low.includes('stream')) cls = 'blue';
            return badge(x, cls);
          }).join('');

          const userLabel = r.username ? `<div class="ac-user">${esc(r.username)}</div>` : `<div class="ac-user">User #${esc(r.user_id)}</div>`;
          const sub = `<div class="ac-small ac-muted">${esc(r.first_seen)} → ${esc(r.last_seen)}</div>`;

          const reseller = r.reseller_name ? esc(r.reseller_name) : (r.reseller_id ? ('#'+esc(r.reseller_id)) : '—');
          const action = `<a href="user_accounts.php?edit=${encodeURIComponent(r.user_id)}">View</a>`;

          return `
            <tr>
              <td>${esc(r.last_seen)}</td>
              <td>${userLabel}${sub}</td>
              <td>${reseller}</td>
              <td>${esc(r.hits)}</td>
              <td>${esc(r.unique_ips)}</td>
              <td>${esc(r.unique_fps)}</td>
              <td>${esc(r.error_hits)}</td>
              <td>${esc(r.stream_starts)}</td>
              <td class="ac-reasons">${reasons || '—'}</td>
              <td class="ac-actions">${action}</td>
            </tr>
          `;
        }).join('');
      }

      elStatus.textContent = `OK ${j.ts || ''}`.trim();
    } catch(e){
      elBody.innerHTML = `<tr><td colspan="10" style="color:#b91c1c;">${esc(e && e.message ? e.message : 'Error')}</td></tr>`;
      elStatus.textContent = 'Error';
    }
  }

  function resetTimer(){
    if(tmr){ clearInterval(tmr); tmr=null; }
    const sec = parseInt(elRef.value||'0', 10) || 0;
    if(sec > 0){
      tmr = setInterval(load, sec*1000);
    }
  }

  elWin.addEventListener('change', function(){ load(); });
  elRef.addEventListener('change', function(){ resetTimer(); });

  resetTimer();
  load();
})();
</script>

</body>
</html>
