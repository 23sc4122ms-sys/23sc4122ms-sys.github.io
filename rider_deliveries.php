<?php
// Rider deliveries list - loaded into rider_panel.php
?>
<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">My Deliveries <small id="pendingCount" class="text-primary ms-2"></small></h2>
    <small class="text-muted">Track and update delivery statuses</small>
  </div>

  <div id="deliveriesCard" class="card shadow-sm">
    <div class="card-body">
      <h5 class="mb-3">Active Deliveries</h5>
      <div class="table-responsive">
        <table class="table table-striped table-hover m-0">
          <thead class="table-light">
            <tr>
              <th>Order ID</th>
              <th>Customer</th>
              <th>Address</th>
              <th>Assigned</th>
              <th>Status</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody id="deliveriesTbody">
            <tr><td colspan="6" class="text-center py-4">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="failedCard" class="card shadow-sm d-none mt-4">
    <div class="card-body">
      <h5 class="mb-3">Not Delivered</h5>
      <div class="table-responsive">
        <table class="table table-striped table-hover m-0">
          <thead class="table-light">
            <tr>
              <th>Order ID</th>
              <th>Customer</th>
              <th>Address</th>
              <th>Assigned</th>
              <th>Status</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody id="failedTbody">
            <tr><td colspan="6" class="text-center py-4">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

      <div id="completedCard" class="card shadow-sm d-none mt-4">
        <div class="card-body">
          <h5 class="mb-3">Completed Deliveries</h5>
          <div class="table-responsive">
            <table class="table table-striped table-hover m-0">
              <thead class="table-light">
                <tr>
                  <th>Order ID</th>
                  <th>Customer</th>
                  <th>Address</th>
                  <th>Assigned</th>
                  <th>Status</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody id="completedTbody">
                <tr><td colspan="6" class="text-center py-4">Loading...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

  <div id="noDeliveriesMsg" class="card shadow-sm d-none">
    <div class="card-body text-center py-4">
      <h5 class="mb-2">No Available Delivery Yet</h5>
      <div class="text-muted">You have no deliveries assigned right now.</div>
    </div>
  </div>
</div>

<script>
function badgeFor(status){
  switch(status){
    case 'assigned': return '<span class="badge bg-primary">Assigned</span>';
    case 'picked_up': return '<span class="badge bg-warning">Picked up</span>';
    case 'delivered':
    case 'complete':
    case 'completed':
    case 'confirmed':
      return '<span class="badge bg-success">Delivered</span>';
    case 'failed': return '<span class="badge bg-danger">Failed</span>';
    default: return `<span class="badge bg-secondary">${status}</span>`;
  }
}

function actionsFor(status){
  // For assigned or picked_up deliveries show Delivered / Not delivered buttons
  if(status === 'assigned' || status === 'picked_up'){
    return `
      <button class="btn btn-sm btn-outline-success btn-deliver">Delivered</button>
      <button class="btn btn-sm btn-outline-danger btn-notdeliver">Not delivered</button>
    `;
  }
  // waiting: view-only (waiting for admin confirmation)
  if(status === 'waiting'){
    return `<span class="text-muted small">Pending Confirmation</span>`;
  }
  // delivered/complete/failed: no actions
  if(status === 'delivered' || status === 'complete' || status === 'completed' || status === 'confirmed'){
    return `<span class="text-muted small">Delivered</span>`;
  }
  if(status === 'failed'){
    return `<span class="text-muted small">Not delivered</span>`;
  }
  return `<span class="text-muted small">No actions</span>`;
}

function failedActionsFor(status){
  // For failed deliveries, show only Delivered button for re-attempt
  if(status === 'failed'){
    return `<button class="btn btn-sm btn-outline-success btn-redeliver">Delivered</button>`;
  }
  return `<span class="text-muted small">No actions</span>`;
}

function tryFetchJson(paths){
  // Attempt multiple candidate paths until one succeeds with JSON
  return new Promise((resolve, reject) => {
    let i = 0;
    function next(){
      if(i >= paths.length) return reject(new Error('all_failed'));
      const candidate = paths[i++];
      let p, opts;
      if(typeof candidate === 'string'){
        p = candidate; opts = undefined;
      } else if(candidate && typeof candidate === 'object'){
        p = candidate.path || candidate.url || candidate;
        opts = {};
        if(candidate.method) opts.method = String(candidate.method).toUpperCase();
        if(candidate.headers) opts.headers = candidate.headers;
        if(candidate.body) opts.body = candidate.body;
      } else {
        // unexpected type, skip
        return next();
      }
      console.log('Trying', p, opts || 'GET');
      fetch(p, opts).then(res => {
        if(!res.ok) throw new Error('bad_status:'+res.status);
        return res.json();
      }).then(j => resolve(j)).catch(err => {
        console.warn('fetch failed for', p, err);
        next();
      });
    }
    next();
  });
}

function apiCandidates(){
  // candidate locations: same folder, parent folder, repo root folder
  const cand = [];
  cand.push('get_deliveries.php');
  cand.push('../get_deliveries.php');
  cand.push('/JapanFoodOrder/get_deliveries.php');
  return cand;
}

function loadDeliveries(){
  tryFetchJson(apiCandidates())
    .then(rows => {
      const deliveriesCard = document.getElementById('deliveriesCard');
      const failedCard = document.getElementById('failedCard');
      const completedCard = document.getElementById('completedCard');
      const noDeliveriesMsg = document.getElementById('noDeliveriesMsg');
      const tbody = document.getElementById('deliveriesTbody');
      const failedTbody = document.getElementById('failedTbody');
      const completedTbody = document.getElementById('completedTbody');
      
      if(!rows || rows.length === 0){
        document.getElementById('pendingCount').textContent = '';
        deliveriesCard.classList.add('d-none');
        failedCard.classList.add('d-none');
        noDeliveriesMsg.classList.remove('d-none');
        return;
      }
      // classify rows into active, failed and completed groups
      const activeStatuses = ['assigned','picked_up','waiting'];
      const completedStatuses = ['delivered','confirmed','complete','completed'];
      const active = rows.filter(r => r && r.status && activeStatuses.includes(r.status));
      const failed = rows.filter(r => r && r.status && r.status === 'failed');
      const completed = rows.filter(r => r && r.status && completedStatuses.includes(r.status));
      
      document.getElementById('pendingCount').textContent = active.length ? `(${active.length} pending)` : '';
      
      // render active deliveries
      if(active.length === 0){
        deliveriesCard.classList.add('d-none');
      } else {
        deliveriesCard.classList.remove('d-none');
        tbody.innerHTML = active.map(d => `
          <tr data-id="${d.id}">
            <td>#${d.order_id}</td>
            <td>${escapeHtml(d.customer_name || '')}</td>
            <td>${escapeHtml(d.dropoff_address || '')}</td>
            <td>${escapeHtml(d.assigned_at || '')}</td>
            <td>${badgeFor(d.status)}</td>
            <td class="text-end">${actionsFor(d.status)}</td>
          </tr>
        `).join('');
      }
      
      // render failed deliveries
      if(failed.length === 0){
        failedCard.classList.add('d-none');
      } else {
        failedCard.classList.remove('d-none');
        failedTbody.innerHTML = failed.map(d => `
          <tr data-id="${d.id}">
            <td>#${d.order_id}</td>
            <td>${escapeHtml(d.customer_name || '')}</td>
            <td>${escapeHtml(d.dropoff_address || '')}</td>
            <td>${escapeHtml(d.assigned_at || '')}</td>
            <td>${badgeFor(d.status)}</td>
            <td class="text-end">${failedActionsFor(d.status)}</td>
          </tr>
        `).join('');
      }
      
      // render completed deliveries
      if(completed.length === 0){
        completedCard.classList.add('d-none');
      } else {
        completedCard.classList.remove('d-none');
        completedTbody.innerHTML = completed.map(d => `
          <tr data-id="${d.id}">
            <td>#${d.order_id}</td>
            <td>${escapeHtml(d.customer_name || '')}</td>
            <td>${escapeHtml(d.dropoff_address || '')}</td>
            <td>${escapeHtml(d.assigned_at || '')}</td>
            <td>${badgeFor(d.status)}</td>
            <td class="text-end"><span class="text-muted small">${d.status}</span></td>
          </tr>
        `).join('');
      }
      
      // show no deliveries message only if all tables are empty
      if(active.length === 0 && failed.length === 0 && completed.length === 0){
        noDeliveriesMsg.classList.remove('d-none');
      } else {
        noDeliveriesMsg.classList.add('d-none');
      }
      
      attachButtons();
    }).catch((e)=>{
      console.error('All fetch attempts failed', e);
      const tbody = document.getElementById('deliveriesTbody');
      document.getElementById('pendingCount').textContent = '';
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-4">Failed to load deliveries</td></tr>';
    });
}

function attachButtons(){
  // Active delivery buttons: delivered and not delivered
  document.querySelectorAll('.btn-deliver').forEach(btn => {
    btn.onclick = () => openProofUpload(btn);
  });
  document.querySelectorAll('.btn-notdeliver').forEach(btn => {
    btn.onclick = () => {
      if(!confirm('Mark this delivery as NOT delivered?')) return;
      updateStatus(btn, 'failed');
    };
  });
  
  // Failed delivery buttons: re-deliver with proof
  document.querySelectorAll('.btn-redeliver').forEach(btn => {
    btn.onclick = () => openProofUpload(btn);
  });
}

function openProofUpload(button){
  const tr = button.closest('tr');
  const id = tr.dataset.id;
  // build a small upload modal
  const tpl = `
    <div class="modal fade" id="proofModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Upload Proof of Delivery</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div class="mb-2">Please take or attach a photo showing delivery to the customer.</div>
            <input type="file" accept="image/*" id="proofFile" class="form-control" />
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" id="uploadProofBtn" class="btn btn-primary">Upload & Submit</button>
          </div>
        </div>
      </div>
    </div>`;
  // remove existing modal
  const existing = document.getElementById('proofModal'); if(existing) existing.remove();
  document.body.insertAdjacentHTML('beforeend', tpl);
  const mEl = document.getElementById('proofModal');
  const m = new bootstrap.Modal(mEl);
  m.show();

  document.getElementById('uploadProofBtn').addEventListener('click', async ()=>{
    const f = document.getElementById('proofFile').files[0];
    if(!f) return alert('Please choose a photo');
    const fd = new FormData(); fd.append('delivery_id', id); fd.append('proof', f);
    try{
      const res = await fetch('rider_upload_proof.php', { method: 'POST', body: fd });
      const j = await res.json();
      if(!j.ok){ alert(j.error || 'Upload failed'); return; }
      m.hide(); mEl.remove(); loadDeliveries();
      alert('Proof uploaded. Waiting for admin confirmation.');
    }catch(err){ alert('Network error'); }
  });
}

function updateStatus(button, status){
  const tr = button.closest('tr');
  const id = tr.dataset.id;
  button.disabled = true;
  const fd = new FormData();
  fd.append('delivery_id', id);
  fd.append('status', status);

  // try candidate paths for update endpoint too
  const updateCandidates = ['update_delivery_status.php','../update_delivery_status.php','/JapanFoodOrder/update_delivery_status.php'];
  tryFetchJson(updateCandidates.map(p => ({method:'post', path:p}))).then(()=>{}).catch(()=>{});

  // simple post attempt using same resolution logic
  (function doUpdate(paths){
    let i=0;
    function next(){
      if(i>=paths.length){
        alert('Update failed (all endpoints)');
        button.disabled = false;
        return;
      }
      const p = paths[i++];
      fetch(p, { method: 'POST', body: fd }).then(r=>{
        if(!r.ok) throw new Error('bad_status');
        return r.json();
      }).then(resp=>{
        if(resp && resp.ok){ loadDeliveries(); }
        else { alert('Update failed'); }
      }).catch(()=> next()).finally(()=> button.disabled = false);
    }
    next();
  })(['update_delivery_status.php','../update_delivery_status.php','/JapanFoodOrder/update_delivery_status.php']);
}

function escapeHtml(s){
  return String(s||'').replace(/[&<>"']/g, function(m){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[m];
  });
}

loadDeliveries();
setInterval(loadDeliveries, 15000);
</script>
