
// optional niceties; installer works without JS.
(function(){
  const els = document.querySelectorAll('[data-copy]');
  els.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const sel = btn.getAttribute('data-copy');
      const t = document.querySelector(sel);
      if(!t) return;
      navigator.clipboard.writeText(t.innerText || t.value || '').then(()=>{
        btn.innerText = 'Copied';
        setTimeout(()=>btn.innerText='Copy', 900);
      });
    });
  });
})();
