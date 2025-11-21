<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Japan Food — Admin Panel</title>
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

.menu-item { padding:0.5rem 0.75rem; cursor:pointer; border-radius:6px; }
.menu-item.active, .menu-item:hover { background: #EE6F57; color:#fff; }
</style>
</head>
<body>
<button id="toggleSidebarBtn" style="left: 10px; right: auto; top: 20px; position: absolute;">
  <i class="bi bi-list fs-5"></i>
</button>

<div class="app">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand d-flex align-items-center gap-2 mb-3">
      <div class="logo">JF</div>
      <div>
        <h1 class="m-0 fs-6">Japan Food</h1>
        <p class="m-0 small">Admin Theme</p>
      </div>
    </div>

    

    <nav class="menu mt-3" id="leftMenu">
      <div class="menu-item active" data-section="dashboard">Dashboard</div>
      <div class="menu-item" data-section="orders">Orders</div>
      <div class="menu-item" data-section="menu">Menu</div>
      <div class="menu-item" data-section="delivery">Delivery</div>
      <div class="menu-item" data-section="users">Users</div>
      <div class="menu-item" data-section="rider-payouts">Rider Payouts</div>
      <div class="menu-item" data-section="bank-info">Bank Info</div>
      <div class="menu-item" data-section="sales-reports">Sales & Reports</div>
      <div class="menu-item" data-section="site-settings">Account Management</div>
    </nav>
  </aside>

  <!-- Main panel -->
  <main class="main" id="mainContent">
    <div id="sectionHeader" class="d-flex align-items-center justify-content-between mb-3">
      <h2 id="sectionTitle" class="h5 m-0">Dashboard</h2>
    </div>
    <div id="sectionBody">
      <?php include 'dashboard.php'; ?>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Chart.js + datalabels -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>

<script>
const toggleBtn = document.getElementById('toggleSidebarBtn');
const app = document.querySelector('.app');
toggleBtn.addEventListener('click', () => { app.classList.toggle('collapsed'); });

const menuItems = document.querySelectorAll('.menu-item');
const mainContent = document.getElementById('mainContent');

let salesChartInstance = null;

function initSalesChart(){
  const canvas = document.getElementById('salesChart');
  if(!canvas) return;
  if(salesChartInstance){ try{ salesChartInstance.destroy(); }catch(e){} }
  // Prefer server-provided chart data injected by the dashboard fragment
  const provided = window.dashboardChart || { labels: [], data: [] };
  const labels = (provided.labels && provided.labels.length) ? provided.labels : ['Toys','Furniture','Home Décor','Electronics'];
  const rawData = (provided.data && provided.data.length) ? provided.data : [14,43,15,28];

  const colors = ['#5DA5E8','#F07B3F','#9E9EA0','#FFCB2F','#8BC34A','#9C27B0'];

  salesChartInstance = new Chart(canvas, {
    type: 'pie',
    data: {
      labels: labels,
      datasets: [{ data: rawData, backgroundColor: colors.slice(0, labels.length), hoverOffset:6, borderWidth:0 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 1,
      plugins: {
        legend: { position: 'right', labels: { boxWidth:12, padding:12 } },
        datalabels: {
          color: '#fff',
          formatter: function(value, ctx){
            const sum = ctx.chart.data.datasets[0].data.reduce((a,b)=>a+ (parseFloat(b)||0),0);
            if(!sum) return '0%';
            return Math.round((value / sum) * 100) + '%';
          },
          backgroundColor: 'rgba(0,0,0,0.55)',
          borderRadius: 4,
          padding: 6,
          anchor: 'center',
          align: 'center',
          font: { weight: '700', size: 12 }
        }
      }
    },
    plugins: [ChartDataLabels]
  });
}

// Attach submit handlers to forms inside the loaded fragment so they post via fetch
function attachFormSubmitHandler(){
  const container = document.getElementById('sectionBody') || mainContent;
  const forms = container.querySelectorAll('form');
  forms.forEach(form => {
    // avoid attaching multiple listeners
    if(form.dataset.ajaxAttached) return;
    form.dataset.ajaxAttached = '1';

    form.addEventListener('submit', function(e){
      e.preventDefault();
      const submitBtn = form.querySelector('[type=submit]');
      if(submitBtn) submitBtn.disabled = true;

      const sectionBody = document.getElementById('sectionBody') || mainContent;
      const action = form.getAttribute('action') || sectionBody.dataset.current || window.location.pathname;
      const method = (form.getAttribute('method') || 'GET').toUpperCase();
      const formData = new FormData(form);

      // handle GET by building a query string
      if(method === 'GET'){
        const params = new URLSearchParams();
        for(const pair of formData.entries()) params.append(pair[0], pair[1]);
        const fetchUrl = action + (String(action).includes('?') ? '&' : '?') + params.toString();
        fetch(fetchUrl, { method: 'GET' })
          .then(res => res.text())
          .then(html => {
            sectionBody.innerHTML = html;
            runFragmentScripts(sectionBody);
            initSalesChart();
            attachFormSubmitHandler();
          })
          .catch(err => { mainContent.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">Request failed</div>`); })
          .finally(() => { if(submitBtn) submitBtn.disabled = false; });
        return;
      }

      // POST
      fetch(action, { method: 'POST', body: formData })
        .then(res => res.text())
        .then(html => {
          sectionBody.innerHTML = html;
          runFragmentScripts(sectionBody);
          initSalesChart();
          attachFormSubmitHandler();
        })
        .catch(err => { mainContent.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">Request failed</div>`); })
        .finally(() => { if(submitBtn) submitBtn.disabled = false; });
    });
  });

  // attach reset filter handlers inside the fragment (e.g. Sales Reports Reset link)
  const resets = container.querySelectorAll('.reset-filters');
  resets.forEach(r => {
    if(r.dataset.resetAttached) return;
    r.dataset.resetAttached = '1';
    r.addEventListener('click', function(e){
      e.preventDefault();
      const sectionBody = document.getElementById('sectionBody') || mainContent;
      const cur = sectionBody.dataset.current || '';
      const base = cur.split('?')[0] || cur || 'sales-reports.php';
      fetch(base).then(r => r.text()).then(html => {
        sectionBody.innerHTML = html;
        // update sectionBody.dataset.current to base
        try{ sectionBody.dataset.current = base; }catch(e){}
        runFragmentScripts(sectionBody);
        initSalesChart();
        attachFormSubmitHandler();
      }).catch(()=> mainContent.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">Failed to reset</div>`));
    });
  });
}

// Load sections via AJAX and re-init chart if dashboard loaded; attach form handlers
menuItems.forEach(item => {
  item.addEventListener('click', function() {
    menuItems.forEach(m => m.classList.remove('active'));
    this.classList.add('active');

    let section = this.dataset.section;
    let url = section + '.php';
    // Update header label to show current section (pretty name)
    const titleEl = document.getElementById('sectionTitle');
    if(titleEl){
      // create a human-readable label (replace - with space, capitalize words)
      const pretty = section.replace(/-/g,' ').replace(/\b\w/g, c => c.toUpperCase());
      titleEl.textContent = pretty;
    }

    // special-case some virtual sections that map to non-fragment endpoints
    if(section === 'rider-payouts'){
      url = 'get_payouts.php';
    }

    fetch(url)
      .then(res => res.text())
      .then(html => {
        // insert into section body so the header/title stays intact
        const sectionBody = document.getElementById('sectionBody') || mainContent;
        sectionBody.innerHTML = html;
        // record current fragment URL for form actions/defaults
        try{ sectionBody.dataset.current = url; }catch(e){}
        // execute any scripts included in the fragment (inline or external)
        runFragmentScripts(sectionBody);
        // if dashboard fragment was loaded, initialize chart
        initSalesChart();
        // attach ajax form handlers for fragments like users.php
        attachFormSubmitHandler();
      })
      .catch(err => mainContent.innerHTML = `<div class="alert alert-danger">Failed to load ${section}</div>`);
  });
});

// Helper: execute scripts found inside a container element after inserting HTML via innerHTML
function runFragmentScripts(container){
  const scripts = Array.from(container.querySelectorAll('script'));
  scripts.forEach(old => {
    const s = document.createElement('script');
    // copy attributes
    for(let i=0;i<old.attributes.length;i++){ const a = old.attributes[i]; s.setAttribute(a.name, a.value); }
    if(old.src){
      // external script: set src and append to body to execute
      s.src = old.src;
      document.body.appendChild(s);
    } else {
      // inline script: copy code
      s.text = old.textContent;
      document.body.appendChild(s);
    }
    // remove original script tag to avoid duplication
    old.parentNode && old.parentNode.removeChild(old);
  });
}

// init on initial load (dashboard included server-side)
document.addEventListener('DOMContentLoaded', function(){
  initSalesChart();
  attachFormSubmitHandler();
});

// Delegated fallback: handle clicks on view-bank buttons in case fragment script handlers didn't attach
document.addEventListener('click', function(e){
  const btn = e.target.closest('.btn-view-bank');
  if(!btn) return;
  // if the fragment attached its own handler, skip delegated fallback
  if(btn.dataset && btn.dataset.viewAttached === '1') return;
  e.preventDefault();
  const id = btn.dataset.id;
  if(!id) return;
  fetch('get_bank.php?id=' + encodeURIComponent(id))
    .then(r => r.json())
    .then(j => {
      if(!j.ok) return alert(j.error || 'Failed to load bank');
      const b = j.bank;
      // create a fallback modal with a unique id so we don't conflict with fragment modal
      let modalEl = document.getElementById('bankModalFallback');
      if(!modalEl){
        const tpl = `
          <div id="bankModalFallback" class="modal fade" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Bank Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body" id="bankModalFallbackBody"></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
              </div>
            </div>
          </div>`;
        document.body.insertAdjacentHTML('beforeend', tpl);
        modalEl = document.getElementById('bankModalFallback');
      }
      const body = modalEl.querySelector('#bankModalFallbackBody');
      if(body){
        let html = '<div><strong>Bank:</strong> ' + (b.bank_name ? b.bank_name : '') + '</div>';
        html += '<div><strong>Account name:</strong> ' + (b.account_name ? b.account_name : '') + '</div>';
        html += '<div><strong>Account number:</strong> ' + (b.account_number ? b.account_number : '') + '</div>';
        html += '<div><strong>Status:</strong> ' + (b.status ? b.status : '') + '</div>';
        body.innerHTML = html;
      }
      const modal = new bootstrap.Modal(modalEl);
      // remove fallback modal element when hidden to clean up backdrops correctly
      modalEl.addEventListener('hidden.bs.modal', function(){ try{ modalEl.remove(); }catch(e){} });
      modal.show();
    }).catch(()=> alert('Network error'));
});
</script>
</body>
</html>
