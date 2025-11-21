<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Japan Food — Rider Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
:root {
  --accent: #EE6F57;
  --accent-2: #CB3737;
}

body, html { margin:0; padding:0; height:100%; }
.app { display:flex; min-height:100vh; transition: all 0.3s; position: relative; }

.sidebar {
  width: 250px;
  transition: all 0.3s;
  background: #fff;
  padding: 1rem;
  position: relative;
  z-index: 1;
}

.main {
  flex: 1;
  padding: 1rem;
  transition: all 0.3s;
}

.app.collapsed .sidebar { width:0; padding:0; overflow:hidden; }
.app.collapsed .main { flex:1; width:100%; }

#toggleSidebarBtn {
  position: absolute;
  top: 20px;
  right: -23px;
  width: 46px;
  height: 46px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--accent), var(--accent-2));
  color: #fff;
  border: none;
  box-shadow: 0 4px 10px rgba(0,0,0,0.15);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 99;
  transition: all 0.3s;
}

#toggleSidebarBtn:hover { transform: scale(1.05); }

/* Keep toggle button visible when sidebar collapsed */
.app.collapsed #toggleSidebarBtn { right: 10px; }

@media (max-width: 900px) {
  #toggleSidebarBtn {
    position: fixed;
    top: 20px;
    left: 20px;
    right: auto;
  }
}

/* Mobile-specific adjustments */
@media (max-width: 900px) {
  .sidebar {
    position: fixed;
    left: 0;
    top: 0;
    bottom: 0;
    width: 260px;
    transform: translateX(-110%);
    z-index: 1050;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    background: #fff;
    padding-top: 72px;
    transition: transform 0.25s ease;
  }
  .app.mobile-open .sidebar { transform: translateX(0); }
  .app.mobile-open .main { filter: blur(0.5px); }
  .menu-item { padding: 0.9rem 1rem; font-size: 15px; }
  .panel-title { font-size: 14px; margin-bottom: 0; }
  .panel-sub { font-size: 12px; color:#6b7280 }
  /* backdrop when mobile menu open */
  #mobileBackdrop { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.36); z-index:1040; }
  .app.mobile-open #mobileBackdrop { display:block; }
  /* make main content comfortable on mobile */
  .main { padding: 1rem; }
}

.menu-item { padding:0.5rem 0.75rem; cursor:pointer; border-radius:6px; }
.menu-item.active, .menu-item:hover { background: var(--accent); color:#fff; }
</style>
</head>
<body>
<button id="toggleSidebarBtn" class="toggle-btn">
  <i class="bi bi-list fs-5"></i>
</button>

<div class="app">
  <div id="mobileBackdrop" onclick="closeMobileSidebar()" aria-hidden="true"></div>
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">
      <div class="logo">RP</div>
      <div>
        <p class="panel-title">Rider Panel</p>
        <p class="panel-sub">Manage deliveries & earnings</p>
      </div>
    </div>

    <nav class="menu" id="leftMenu">
      <div class="menu-item active" data-section="rider_dashboard"><i class="bi bi-bar-chart"></i><span class="label">Dashboard</span></div>
      <div class="menu-item" data-section="rider_deliveries"><i class="bi bi-geo-alt"></i><span class="label">Active Delivery</span></div>
      <div class="menu-item" data-section="rider_history"><i class="bi bi-clock"></i><span class="label">Order History</span></div>
      <div class="menu-item" data-section="rider_earnings"><i class="bi bi-cash-stack"></i><span class="label">Earnings</span></div>
      
      <div class="menu-item" data-section="rider_settings"><i class="bi bi-gear"></i><span class="label">Account Management</span></div>
      <div class="menu-item" data-section="rider_help"><i class="bi bi-question-circle"></i><span class="label">Help & Support</span></div>
    </nav>
  </aside>

  <!-- Main panel -->
  <main class="main" id="mainContent">
    <?php include 'rider_dashboard.php'; ?>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js (needed for earnings chart) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
const toggleBtn = document.getElementById('toggleSidebarBtn');
const app = document.querySelector('.app');

function openMobileSidebar(){
  app.classList.add('mobile-open');
  const sb = document.querySelector('.sidebar'); if(sb) sb.classList.add('open');
  const bd = document.getElementById('mobileBackdrop'); if(bd) bd.classList.add('show');
}

function closeMobileSidebar(){
  app.classList.remove('mobile-open');
  const sb = document.querySelector('.sidebar'); if(sb) sb.classList.remove('open');
  const bd = document.getElementById('mobileBackdrop'); if(bd) bd.classList.remove('show');
}

toggleBtn.addEventListener('click', () => {
  // On small screens open the sliding mobile sidebar; otherwise toggle collapse
  if(window.innerWidth <= 900){
    if(app.classList.contains('mobile-open')) closeMobileSidebar(); else openMobileSidebar();
    return;
  }
  app.classList.toggle('collapsed');
});

// ensure backdrop click also closes
const mobileBackdrop = document.getElementById('mobileBackdrop');
if(mobileBackdrop){ mobileBackdrop.addEventListener('click', closeMobileSidebar); }

// AJAX Section switching
const menuItems = document.querySelectorAll('.menu-item');
const mainContent = document.getElementById('mainContent');

menuItems.forEach(item => {
  item.addEventListener('click', function() {
    // detect previous active section so we can refresh earnings when leaving it
    const prevActive = document.querySelector('.menu-item.active');
    const section = this.dataset.section;

    // If we are leaving the earnings section, refresh it in background and show spinner
    try{
      if(prevActive && prevActive.dataset && prevActive.dataset.section === 'rider_earnings' && section !== 'rider_earnings'){
        const earningsMenu = document.querySelector('.menu-item[data-section="rider_earnings"]');
        if(earningsMenu && typeof window.riderEarningsRefresh === 'function'){
          // append a small spinner to the Earnings menu item
          const spinner = document.createElement('span');
          spinner.className = 'menu-spinner spinner-border spinner-border-sm ms-2';
          spinner.setAttribute('role','status'); spinner.setAttribute('aria-hidden','true');
          earningsMenu.appendChild(spinner);
          try{ window.riderEarningsRefresh().finally(()=>{ try{ spinner.remove(); }catch(e){} }); }catch(e){ try{ spinner.remove(); }catch(err){} }
        }
      }
    }catch(e){}

    menuItems.forEach(m => m.classList.remove('active'));
    this.classList.add('active');   
    let url = section + '.php';
      // If the earnings fragment is already present, refresh its AJAX content
      if(section === 'rider_earnings'){
        const existing = document.getElementById('riderEarningsFragment');
        if(existing && typeof window.riderEarningsRefresh === 'function'){
          try{ window.riderEarningsRefresh(); }catch(e){ console.warn('riderEarningsRefresh failed', e); }
          // avoid re-fetching the fragment HTML if we already have it
          return;
        }
      }

    // Reset chart state when switching sections
    if(earningsChartInstance){ 
      try{ earningsChartInstance.destroy(); }catch(e){}
      earningsChartInstance = null;
    }
    earningsChartInitialized = false;

    fetch(url)
      .then(res => res.text())
      .then(html => {
        // insert HTML and execute any inline scripts so fragment JS runs
        insertFragment(html);
        // init charts after fragment injection ONLY for earnings section
        if(section === 'rider_earnings' && typeof initEarningsChart === 'function') initEarningsChart();
        if(typeof attachFormSubmitHandler === 'function') attachFormSubmitHandler();
        // Always refresh the earnings fragment's AJAX content when the menu is clicked
        if(section === 'rider_earnings' && typeof window.riderEarningsRefresh === 'function'){
          try{ window.riderEarningsRefresh(); }catch(e){ console.warn('riderEarningsRefresh failed', e); }
        }
      })
      .catch(err => mainContent.innerHTML = `<div class="alert alert-danger">Failed to load ${section}</div>`);
  });
});

// Earnings chart initializer (safe to call even if canvas is not present)
let earningsChartInstance = null;
let earningsChartInitialized = false;
function initEarningsChart(){
  const canvas = document.getElementById('earningsChart');
  if(!canvas || typeof Chart === 'undefined') return;
  // Prevent multiple initializations of the same chart
  if(earningsChartInitialized && earningsChartInstance) return;
  if(earningsChartInstance){ try{ earningsChartInstance.destroy(); }catch(e){} }
  earningsChartInitialized = false;

  try{
    // fetch data from backend endpoint
    fetch('get_earnings.php')
      .then(r => r.json())
      .then(payload => {
        const labels = payload.labels || ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        const baseData = payload.base || [];
        const totalData = payload.total || [];

        // update summary cards if present
        if(payload.summary){
          const s = payload.summary;
          const fmt = v => typeof v === 'number' ? ('$' + Number(v).toFixed(2)) : v;
          const weekEl = document.getElementById('weekTotal');
          const dailyEl = document.getElementById('dailyAvg');
          const perEl = document.getElementById('perOrder');
          const totalOrdersEl = document.getElementById('totalOrders');
          if(weekEl) weekEl.textContent = fmt(s.week_total);
          if(dailyEl) dailyEl.textContent = fmt(s.daily_avg);
          if(perEl) perEl.textContent = fmt(s.per_order);
          if(totalOrdersEl) totalOrdersEl.textContent = s.total_orders;
        }

        earningsChartInstance = new Chart(canvas, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              { label: 'Base Pay', data: baseData, borderColor:'#2ecc71', backgroundColor:'rgba(46,204,113,0.05)', tension:0.3, pointRadius:3 },
              { label: 'Total', data: totalData, borderColor:'#3498db', backgroundColor:'rgba(52,152,219,0.05)', tension:0.3, pointRadius:3 }
            ]
          },
          options: {
            responsive:true,
            maintainAspectRatio:false,
            plugins:{ legend:{ position:'bottom' }, datalabels:{ display:false } },
            scales:{ y:{ beginAtZero:true } }
          }
        });
        earningsChartInitialized = true;
      })
      .catch(err => { console.warn('Failed to load earnings data', err); });
  }catch(e){ console.warn('Failed to init earnings chart', e); }
}

// Also call on initial load in case the included fragment contains the canvas
document.addEventListener('DOMContentLoaded', function(){
  if(typeof initEarningsChart === 'function') initEarningsChart();
  if(typeof attachFormSubmitHandler === 'function') attachFormSubmitHandler();
});

// Listen for fragment requests to refresh the whole rider panel (refresh active section)
document.addEventListener('rider:refresh-panel', function(e){
  try{
    console.log('rider:refresh-panel received', e && e.detail);
    const active = document.querySelector('.menu-item.active');
    if(active){
      // trigger the click handler for the active menu item to re-fetch its fragment
      active.click();
      return;
    }
    // Fallback: reload the whole page
    try{ window.location.reload(); }catch(e){ console.warn('reload failed', e); }
  }catch(err){ console.warn('rider:refresh-panel handler error', err); }
});

// Attach submit handlers to forms inside the loaded fragment so they post via fetch
function attachFormSubmitHandler(){
  const forms = mainContent.querySelectorAll('form');
  forms.forEach(form => {
    // avoid attaching multiple listeners
    if(form.dataset.ajaxAttached) return;
    form.dataset.ajaxAttached = '1';

    form.addEventListener('submit', function(e){
      e.preventDefault();
      const submitBtn = form.querySelector('[type=submit]');
      if(submitBtn) submitBtn.disabled = true;

      const action = form.getAttribute('action') || window.location.pathname;
      const method = (form.getAttribute('method') || 'POST').toUpperCase();
      const formData = new FormData(form);

      const isJsonAjax = String(form.dataset.ajax || '').toLowerCase() === 'true';
      const fetchOpts = { method, body: formData };
      if(isJsonAjax){
        fetchOpts.headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
      }

      fetch(action, fetchOpts)
        .then(res => {
          const ct = res.headers.get('content-type') || '';
          if(ct.indexOf('application/json') !== -1 || isJsonAjax){
            return res.json().then(data => ({json: true, data}));
          }
          return res.text().then(html => ({json:false, html}));
        })
        .then(result => {
          if(result.json){
            const data = result.data;
            // remove any existing alerts for this form
            let old = form.querySelector('.form-alerts');
            if(old) old.remove();

            const container = document.createElement('div');
            container.className = 'form-alerts mb-3';

            if(data.errors && data.errors.length){
              const ul = document.createElement('ul');
              ul.className = 'mb-0';
              data.errors.forEach(err => {
                const li = document.createElement('li'); li.textContent = err; ul.appendChild(li);
              });
              const wrap = document.createElement('div'); wrap.className = 'alert alert-danger'; wrap.appendChild(ul);
              container.appendChild(wrap);
            }

            if(data.success){
              const s = document.createElement('div'); s.className = 'alert alert-success'; s.textContent = data.success; container.appendChild(s);
            }

            // insert alerts above the form
            form.parentNode.insertBefore(container, form);

            // update small parts of the fragment (profile photo etc.) if present
            if(data.user){
              // update profile image (first <img> inside form area)
              const img = form.querySelector('img');
              if(img && data.user.profile_photo){
                img.src = data.user.profile_photo + '?v=' + Date.now();
              }
              // update form fields if server returned fresh values
              if(typeof data.user.name === 'string'){
                const parts = (data.user.name || '').split(/\s+/, 2);
                const firstEl = form.querySelector('[name=first_name]'); if(firstEl) firstEl.value = parts[0] || '';
                const lastEl = form.querySelector('[name=last_name]'); if(lastEl) lastEl.value = parts[1] || '';
              }
              if(typeof data.user.phone === 'string'){
                const el = form.querySelector('[name=phone]'); if(el) el.value = data.user.phone;
              }
              if(typeof data.user.vehicle_info === 'string'){
                const el = form.querySelector('[name=vehicle_info]'); if(el) el.value = data.user.vehicle_info;
              }
            }

            // keep fragment loaded (do not replace)
          }else{
            // full HTML fallback: replace fragment and execute scripts
            insertFragment(result.html);
            if(typeof initEarningsChart === 'function') initEarningsChart();
            attachFormSubmitHandler();
          }
        })
        .catch(err => {
          mainContent.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">Request failed</div>`);
          console.error('Form submit failed', err);
        })
        .finally(() => { if(submitBtn) submitBtn.disabled = false; });
    });
  });
}

// Helper: insert an HTML fragment into mainContent and execute inline scripts
function insertFragment(html){
  // create a container to parse
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  // If the current fragment defines a cleanup hook, call it before removing DOM
  try{
    const existingRoot = document.getElementById('riderEarningsFragment');
    if(existingRoot && typeof window.riderEarningsCleanup === 'function'){
      try{ window.riderEarningsCleanup(); }catch(e){ console.warn('riderEarningsCleanup failed', e); }
    }
  }catch(e){}

  // move non-script nodes into mainContent
  mainContent.innerHTML = '';
  Array.from(tmp.childNodes).forEach(node => {
    if(node.tagName && node.tagName.toLowerCase() === 'script') return; // skip scripts for now
    mainContent.appendChild(node.cloneNode(true));
  });

  // execute scripts in order
  const scripts = tmp.querySelectorAll('script');
  scripts.forEach(s => {
    const ns = document.createElement('script');
    if(s.src){ ns.src = s.src; ns.async = false; }
    if(s.type) ns.type = s.type;
    // inline script text
    if(!s.src) ns.textContent = s.textContent;
    document.body.appendChild(ns);
    document.body.removeChild(ns);
  });
}
</script>
</body>
</html>
