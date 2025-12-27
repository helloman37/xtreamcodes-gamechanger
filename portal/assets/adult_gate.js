(function(){
  function getCookie(name){
    const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()\[\]\\\/\+^]/g,'\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function setCookie(name, value, days){
    const d = new Date();
    d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = name + '=' + encodeURIComponent(String(value)) + '; path=/; expires=' + d.toUTCString() + '; SameSite=Lax';
  }

  function delCookie(name){
    // expire in the past
    setCookie(name, '', -1);
  }

  function pad2(n){
    return String(n).padStart(2, '0');
  }

  function parseDob(str){
    str = (str || '').trim();
    const m = str.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (!m) return null;
    const y = parseInt(m[1], 10);
    const mo = parseInt(m[2], 10);
    const d = parseInt(m[3], 10);
    if (!y || !mo || !d) return null;
    if (mo < 1 || mo > 12) return null;
    if (d < 1 || d > 31) return null;

    const dt = new Date(y, mo - 1, d);
    if (dt.getFullYear() !== y || (dt.getMonth() + 1) !== mo || dt.getDate() !== d) return null;
    return { y, mo, d };
  }

  function computeAge(y, mo, d){
    const today = new Date();
    let age = today.getFullYear() - y;
    const tm = today.getMonth() + 1;
    const td = today.getDate();
    if (tm < mo || (tm === mo && td < d)) age -= 1;
    return age;
  }

  function isVerified(){
    const v = getCookie('gc_adult_verified') || getCookie('adult_verified');
    const dobStr = getCookie('gc_adult_dob') || getCookie('adult_dob');
    let age = 0;
    const dob = parseDob(dobStr);
    if (dob) age = computeAge(dob.y, dob.mo, dob.d);
    else age = parseInt(getCookie('gc_adult_age') || getCookie('adult_age') || '0', 10) || 0;
    return (v === '1' && age >= 18);
  }

  const style = document.createElement('style');
  style.textContent = `
  .gc-agegate-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;z-index:99999}
  .gc-agegate{width:min(460px,92vw);background:#121826;border:1px solid rgba(255,255,255,.12);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.55);padding:18px;color:#fff}
  .gc-agegate h3{margin:0 0 8px 0;font-size:18px}
  .gc-agegate p{margin:0 0 14px 0;opacity:.9;line-height:1.35}
  .gc-agegate .row{display:flex;gap:10px;flex-wrap:wrap}
  .gc-agegate button{cursor:pointer;border:0;border-radius:10px;padding:10px 12px;font-weight:700}
  .gc-agegate .yes{background:#24c26a;color:#062012}
  .gc-agegate .no{background:#ff4d4d;color:#2a0707}
  .gc-agegate .dobwrap{margin-top:12px;display:none;gap:10px;flex-direction:column}
  .gc-agegate .dobrow{display:flex;gap:10px;flex-wrap:wrap}
  .gc-agegate select{flex:1;min-width:120px;background:#0b1220;border:1px solid rgba(255,255,255,.16);color:#fff;border-radius:10px;padding:10px 12px;outline:none}
  .gc-agegate .msg{margin-top:10px;font-size:13px;opacity:.9}
  `;
  document.head.appendChild(style);

  function showGate(onPass, onFail){
    const back = document.createElement('div');
    back.className = 'gc-agegate-backdrop';
    back.innerHTML = `
      <div class="gc-agegate" role="dialog" aria-modal="true">
        <h3>Adults only (18+)</h3>
        <p>This category contains adult content. Enter your birth date to continue.</p>
        <div class="row">
          <button type="button" class="yes">Continue</button>
          <button type="button" class="no">Back</button>
        </div>
        <div class="dobwrap">
          <div class="dobrow">
            <select class="mm" aria-label="Month"></select>
            <select class="dd" aria-label="Day"></select>
            <select class="yy" aria-label="Year"></select>
          </div>
          <button type="button" class="yes submit">Confirm</button>
        </div>
        <div class="msg"></div>
      </div>
    `;
    document.body.appendChild(back);

    const btnYes = back.querySelector('button.yes');
    const btnNo = back.querySelector('button.no');
    const dobwrap = back.querySelector('.dobwrap');
    const mm = back.querySelector('select.mm');
    const dd = back.querySelector('select.dd');
    const yy = back.querySelector('select.yy');
    const msg = back.querySelector('.msg');
    const btnSubmit = back.querySelector('button.submit');

    function close(){ try { back.remove(); } catch(e) {} }

    function resetCookies(){
      // clear both legacy and new cookies
      ['gc_adult_verified','gc_adult_age','gc_adult_dob','adult_verified','adult_age','adult_dob'].forEach(delCookie);
      setCookie('gc_adult_verified','0',30);
      setCookie('gc_adult_age','0',30);
      setCookie('adult_verified','0',30);
      setCookie('adult_age','0',30);
    }

    function fillSelects(){
      const monthNames = ['Month','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      mm.innerHTML = '';
      for (let i=0; i<monthNames.length; i++){
        const opt = document.createElement('option');
        opt.value = i === 0 ? '' : String(i);
        opt.textContent = monthNames[i];
        mm.appendChild(opt);
      }

      dd.innerHTML = '';
      const optD0 = document.createElement('option');
      optD0.value = '';
      optD0.textContent = 'Day';
      dd.appendChild(optD0);
      for (let i=1; i<=31; i++){
        const opt = document.createElement('option');
        opt.value = String(i);
        opt.textContent = String(i);
        dd.appendChild(opt);
      }

      yy.innerHTML = '';
      const optY0 = document.createElement('option');
      optY0.value = '';
      optY0.textContent = 'Year';
      yy.appendChild(optY0);
      const cur = new Date().getFullYear();
      for (let y=cur; y>=cur-120; y--){
        const opt = document.createElement('option');
        opt.value = String(y);
        opt.textContent = String(y);
        yy.appendChild(opt);
      }

      // prefill from cookie if present
      const dobStr = getCookie('gc_adult_dob') || getCookie('adult_dob');
      const dob = parseDob(dobStr);
      if (dob){
        mm.value = String(dob.mo);
        dd.value = String(dob.d);
        yy.value = String(dob.y);
      }
    }

    btnNo.addEventListener('click', () => {
      resetCookies();
      close();
      if (onFail) onFail();
    });

    btnYes.addEventListener('click', () => {
      dobwrap.style.display = 'flex';
      fillSelects();
      mm.focus();
    });

    function submit(){
      msg.textContent = '';
      const mo = parseInt(mm.value || '0', 10) || 0;
      const da = parseInt(dd.value || '0', 10) || 0;
      const yr = parseInt(yy.value || '0', 10) || 0;
      if (!mo || !da || !yr){ msg.textContent = 'Please select your full birth date.'; return; }

      const dt = new Date(yr, mo - 1, da);
      if (dt.getFullYear() !== yr || (dt.getMonth() + 1) !== mo || dt.getDate() !== da){
        msg.textContent = 'That birth date is not valid.';
        return;
      }

      const age = computeAge(yr, mo, da);
      if (age < 18){ msg.textContent = 'You must be 18 or older.'; return; }

      const dobStr = String(yr) + '-' + pad2(mo) + '-' + pad2(da);
      setCookie('gc_adult_verified','1',30);
      setCookie('gc_adult_dob',dobStr,30);
      setCookie('gc_adult_age',String(age),30); // legacy support
      setCookie('adult_verified','1',30);
      setCookie('adult_dob',dobStr,30);
      setCookie('adult_age',String(age),30); // legacy support

      close();
      if (onPass) onPass(age, dobStr);
    }

    btnSubmit.addEventListener('click', submit);
    [mm,dd,yy].forEach(el => el.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); }));

    back.addEventListener('click', (e) => { if (e.target === back) btnNo.click(); });
  }

  function hookCategorySelect(){
    const sel = document.getElementById('cat');
    if (!sel) return;
    let prev = sel.value || 'all';

    // Use CAPTURE so we run before portal.js (which redirects on Live TV category change)
    sel.addEventListener('change', (e) => {
      const opt = sel.options[sel.selectedIndex];
      const isAdult = opt && (opt.getAttribute('data-adult') === '1');
      if (!isAdult) { prev = sel.value || 'all'; return; }
      if (isVerified() || (window.__allowAdult === true)) { prev = sel.value || 'all'; return; }

      // Block other handlers (ex: portal.js redirect) until age gate passes.
      try { e && e.preventDefault(); } catch(_) {}
      try { e && e.stopImmediatePropagation(); } catch(_) {}
      try { e && e.stopPropagation(); } catch(_) {}

      const desired = sel.value || 'all';
      const restore = () => { sel.value = prev; };
      showGate(
        () => {
          // Live TV does server-side category pages.
          if (typeof window.PAGE !== 'undefined' && window.PAGE === 'live') {
            const base = '/portal/live.php';
            if (!desired || desired === 'all') window.location.href = base;
            else window.location.href = base + '?cat=' + encodeURIComponent(desired);
            return;
          }
          // Other pages: reload so portal/_init.php picks up the adult cookie and server returns adult items.
          location.reload();
        },
        () => { restore(); }
      );
    }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hookCategorySelect);
  else hookCategorySelect();
})();
