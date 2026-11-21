(() => {
 const key = 'paymentDetailsOrigin';
 const stateKey = 'paymentListState:' + location.pathname;
 const isList = () => !new URLSearchParams(location.search).has('id');
 function saveListState() {
  if (!isList()) return;
  const historyTab = document.getElementById('history-tab');
  const state = {
   tab: historyTab && getComputedStyle(historyTab).display !== 'none' ? 'history' : 'pending',
   expanded: [...document.querySelectorAll('.payment-group-toggle[aria-expanded="true"]')].map(button => button.getAttribute('aria-controls'))
  };
  sessionStorage.setItem(stateKey, JSON.stringify(state));
 }
 function restoreListState() {
  if (!isList()) return;
  let state;
  try { state = JSON.parse(sessionStorage.getItem(stateKey) || 'null'); } catch (_) { return; }
  if (!state) return;
  const button = [...document.querySelectorAll('.payments-center .tab-btn')].find(el => (el.getAttribute('onclick') || '').includes("'" + state.tab + "'"));
  if (button && typeof switchTab === 'function') switchTab(state.tab, button);
  document.querySelectorAll('.payment-group-toggle').forEach(button => {
   const id = button.getAttribute('aria-controls');
   const open = (state.expanded || []).includes(id);
   button.setAttribute('aria-expanded', String(open));
   const panel = document.getElementById(id);
   if (panel) panel.style.display = open ? 'block' : 'none';
  });
 }
 document.addEventListener('click', event => {
  if (event.target.closest('.payment-group-toggle,.payments-center .tab-btn')) saveListState();
 });
 window.addEventListener('pagehide', saveListState);
 const read = () => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) { return null; } };
 document.addEventListener('click', event => {
  const link = event.target.closest('a.payments-action-link');
  if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;
  const target = new URL(link.href);
  const tab = document.getElementById('history-tab')?.style.display !== 'none' ? 'history' : 'pending';
  const areas = [...document.querySelectorAll('[id]')].filter(el => el.scrollHeight > el.clientHeight && el.clientHeight > 0).map(el => ({ id:el.id, top:el.scrollTop, left:el.scrollLeft }));
  sessionStorage.setItem(key, JSON.stringify({ url:location.href, booking:target.searchParams.get('id'), tab, top:scrollY, left:scrollX, areas,
   expanded:[...document.querySelectorAll('.payment-group-toggle[aria-expanded="true"]')].map(el => el.getAttribute('aria-controls')) }));
 });
 function restore() {
  const saved=read(); if (!saved) return;
  const origin=new URL(saved.url,location.href);
  if(origin.origin!==location.origin || !origin.pathname.endsWith('/customer/payments.php') || origin.searchParams.has('id')) return;
  if(new URLSearchParams(location.search).has('id')) {
   const back=document.querySelector('.payment-details-heading > a');
   if(back && new URLSearchParams(location.search).get('id')===saved.booking) {
    back.href=origin.href;
    back.addEventListener('click', () => {
     saved.restoreOnReturn=true;
     sessionStorage.setItem(key,JSON.stringify(saved));
    }, {once:true});
   }
   return;
  }
  if(location.href!==origin.href) return;
  const historyReturn=performance.getEntriesByType('navigation')[0]?.type==='back_forward';
  if(!saved.restoreOnReturn && !historyReturn) {
   sessionStorage.removeItem(key);

   return;
  }
  sessionStorage.removeItem(key);
  const tabButton=[...document.querySelectorAll('.payments-center .tab-btn')].find(el => (el.getAttribute('onclick') || '').includes("'"+saved.tab+"'"));
  if(tabButton && typeof switchTab==='function') switchTab(saved.tab,tabButton);
  saved.expanded.forEach(id => {
   const panel=document.getElementById(id); if(panel) panel.style.display='block';
   document.querySelectorAll('.payment-group-toggle').forEach(button => { if(button.getAttribute('aria-controls')===id) button.setAttribute('aria-expanded','true'); });
  });
  requestAnimationFrame(() => requestAnimationFrame(() => {
   saved.areas.forEach(area => { const el=document.getElementById(area.id); if(el) { el.scrollTop=area.top; el.scrollLeft=area.left; } });
   window.scrollTo(saved.left,saved.top);
   const links=[...document.querySelectorAll('a.payments-action-link')];
   const link=links.find(el => new URL(el.href).searchParams.get('id')===saved.booking && el.getClientRects().length);
   const row=link?.closest('tr,.payment-mobile-card');
   if(row) {
    const bounds=row.getBoundingClientRect();
    if(bounds.top<0 || bounds.bottom>innerHeight) row.scrollIntoView({block:'center'});
   }
  }));
 }
 window.addEventListener('pageshow', () => { restoreListState(); restore(); });
})();
