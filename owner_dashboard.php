<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Japan Food — Owner Panel</title>
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
        <p class="m-0 small">Owner Theme</p>
      </div>
    </div>

    

    <nav class="menu mt-3" id="leftMenu">
      <div class="menu-item active" data-section="dashboard">Dashboard</div>
      <div class="menu-item" data-section="orders">Orders</div>
      <div class="menu-item" data-section="menu">Menu</div>
      <div class="menu-item" data-section="delivery">Delivery</div>
      <div class="menu-item" data-section="users">Users</div>
      <div class="menu-item" data-section="bank-info">Bank Info</div>
      <div class="menu-item" data-section="sales-reports">Sales & Reports</div>
      <!-- Note: Settings intentionally omitted for Owner -->
    </nav>
  </aside>

  <!-- Main panel -->
  <main class="main" id="mainContent">
    <?php include 'dashboard.php'; ?>
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

  const data = {
    labels: ['Toys','Furniture','Home Décor','Electronics'],
    datasets: [{ data: [14,43,15,28], backgroundColor: ['#5DA5E8','#F07B3F','#9E9EA0','#FFCB2F'], hoverOffset:6, borderWidth:0 }]
  };

  salesChartInstance = new Chart(canvas, {
    type: 'pie',
    data: data,
    options: {
      responsive: true,
      maintainAspectRatio: true,
      aspectRatio: 1,
      plugins: {
        legend: { position: 'right', labels: { boxWidth:12, padding:12 } },
        datalabels: {
          color: '#fff',
          formatter: function(value){ return value + '%'; },
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

      fetch(action, { method, body: formData })
        .then(res => res.text())
        .then(html => {
          // replace the fragment in-place so the admin panel remains
          mainContent.innerHTML = html;
          // re-run fragment initializers
          initSalesChart();
          attachFormSubmitHandler();
        })
        .catch(err => {
          mainContent.insertAdjacentHTML('afterbegin', `<div class="alert alert-danger">Request failed</div>`);
        })
        .finally(() => { if(submitBtn) submitBtn.disabled = false; });
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

    fetch(url)
      .then(res => res.text())
      .then(html => {
        mainContent.innerHTML = html;
        // if dashboard fragment was loaded, initialize chart
        initSalesChart();
        // attach ajax form handlers for fragments like users.php
        attachFormSubmitHandler();
      })
      .catch(err => mainContent.innerHTML = `<div class="alert alert-danger">Failed to load ${section}</div>`);
  });
});

// init on initial load (dashboard included server-side)
document.addEventListener('DOMContentLoaded', function(){
  initSalesChart();
  attachFormSubmitHandler();
});
</script>
</body>
</html>
