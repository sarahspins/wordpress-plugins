
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.ifp-countdown[data-opening]').forEach(timer=>{
    const target = new Date(timer.dataset.opening).getTime();
    const tick=()=>{
      let diff=Math.max(0,target-Date.now());
      const d=Math.floor(diff/86400000); diff%=86400000;
      const h=Math.floor(diff/3600000); diff%=3600000;
      const m=Math.floor(diff/60000);
      const s=Math.floor((diff%60000)/1000);
      timer.querySelector('[data-days]').textContent=String(d).padStart(2,'0');
      timer.querySelector('[data-hours]').textContent=String(h).padStart(2,'0');
      timer.querySelector('[data-minutes]').textContent=String(m).padStart(2,'0');
      timer.querySelector('[data-seconds]').textContent=String(s).padStart(2,'0');
    };
    tick(); setInterval(tick,1000);
  });
});
