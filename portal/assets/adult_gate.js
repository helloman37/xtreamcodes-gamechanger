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
  function isVerified(){
    const v = getCookie('gc_adult_verified') || getCookie('adult_verified');
    const age = parseInt(getCookie('gc_adult_age') || getCookie('adult_age') || '0', 10) || 0;
    return (v === '1' && age >= 18);
  }

  const style = document.createElement('style');
  style.textContent = `
  .gc-agegate-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;z-index:99999}
  .gc-agegate{width:min(420px,92vw);background:#121826;border:1px solid rgba(255,255,255,.12);border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.55);padding:18px;color:#fff}
  .gc-agegate h3{margin:0 0 8px 0;font-size:18px}
  .gc-agegate p{margin:0 0 14px 0;opacity:.9;line-height:1.35}
  .gc-agegate .row{display:flex;gap:10px;flex-wrap:wrap}
  .gc-agegate button{cursor:pointer;border:0;border-radius:10px;padding:10px 12px;font-weight:700}
  .gc-agegate .yes{background:#24c26a;color:#062012}
  .gc-agegate .no{background:#ff4d4d;color:#2a0707}
  .gc-agegate .agewrap{margin-top:12px;display:none;gap:10px;align-items:center}
  .gc-agegate input{flex:1;background:#0b1220;border:1px solid rgba(255,255,255,.16);color:#fff;border-radius:10px;padding:10px 12px;outline:none}
  .gc-agegate .msg{margin-top:10px;font-size:13px;opacity:.9}
  `;
  document.head.appendChild(style);

  function showGate(onPass, onFail){
    const back = document.createElement('div');
    back.className = 'gc-agegate-backdrop';
    back.innerHTML = `
      <div class="gc-agegate" role="dialog" aria-modal="true">
        <h3>Adults only (18+)</h3>
        <p>This category contains adult content. Confirm you are 18+ to continue.</p>
        <div class="row">
          <button type="button" class="yes">I'm 18+</button>
          <button type="button" class="no">No I'm not</button>
        </div>
        <div class="agewrap">
          <input type="number" min="0" max="120" inputmode="numeric" placeholder="Enter your age" />
          <button type="button" class="yes submit">Confirm</button>
        </div>
        <div class="msg"></div>
      </div>
    `;
    document.body.appendChild(back);

    const btnYes = back.querySelector('button.yes');
    const btnNo = back.querySelector('button.no');
    const agewrap = back.querySelector('.agewrap');
    const ageInput = back.querySelector('input');
    const msg = back.querySelector('.msg');
    const btnSubmit = back.querySelector('button.submit');

    function close(){ try { back.remove(); } catch(e) {} }

    btnNo.addEventListener('click', () => {
      setCookie('gc_adult_verified','0',30);
      setCookie('gc_adult_age','0',30);
      setCookie('adult_verified','0',30);
      setCookie('adult_age','0',30);
      close();
      if (onFail) onFail();
    });

    btnYes.addEventListener('click', () => {
      agewrap.style.display = 'flex';
      ageInput.focus();
    });

    function submit(){
      const age = parseInt(ageInput.value || '0', 10) || 0;
      if (age < 18){ msg.textContent = 'You must be 18 or older.'; return; }
      setCookie('gc_adult_verified','1',30);
      setCookie('gc_adult_age',String(age),30);
      setCookie('adult_verified','1',30);
      setCookie('adult_age',String(age),30);
      close();
      if (onPass) onPass(age);
    }
    btnSubmit.addEventListener('click', submit);
    ageInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });

    back.addEventListener('click', (e) => { if (e.target === back) btnNo.click(); });
  }

  function hookCategorySelect(){
    const sel = document.getElementById('cat');
    if (!sel) return;
    let prev = sel.value || 'all';

    sel.addEventListener('change', () => {
      const opt = sel.options[sel.selectedIndex];
      const isAdult = opt && (opt.getAttribute('data-adult') === '1');
      if (!isAdult) { prev = sel.value || 'all'; return; }
      if (isVerified() || (window.__allowAdult === true)) { prev = sel.value || 'all'; return; }
      const restore = () => { sel.value = prev; sel.dispatchEvent(new Event('change', {bubbles:true})); };
      showGate(
        () => { location.reload(); },
        () => { restore(); }
      );
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', hookCategorySelect);
  else hookCategorySelect();
})();
