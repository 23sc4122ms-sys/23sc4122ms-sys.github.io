<?php
// delivery.php — DB-backed deliveries UI
session_start();
include_once __DIR__ . '/db.php';
$pdo = getPDO();

// Ensure deliveries table exists (simple migration on demand)
$pdo->exec("CREATE TABLE IF NOT EXISTS deliveries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL UNIQUE,
  rider_id INT DEFAULT NULL,
  status VARCHAR(50) DEFAULT 'assigned',
  assigned_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Ensure optional columns exist so later SELECTs can reference them safely
try{
  $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'proof_path'")->fetch();
  if(!$col){ $pdo->exec("ALTER TABLE deliveries ADD COLUMN proof_path VARCHAR(255) DEFAULT NULL"); }
}catch(Exception $e){ /* ignore */ }
try{
  $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'proof_uploaded_at'")->fetch();
  if(!$col){ $pdo->exec("ALTER TABLE deliveries ADD COLUMN proof_uploaded_at DATETIME DEFAULT NULL"); }
}catch(Exception $e){ /* ignore */ }
try{
  $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'confirmed_at'")->fetch();
  if(!$col){ $pdo->exec("ALTER TABLE deliveries ADD COLUMN confirmed_at DATETIME DEFAULT NULL"); }
}catch(Exception $e){ /* ignore */ }
try{
  $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'amount'")->fetch();
  if(!$col){ $pdo->exec("ALTER TABLE deliveries ADD COLUMN amount DECIMAL(10,2) DEFAULT 0.00"); }
}catch(Exception $e){ /* ignore */ }
try{
  $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'base_pay'")->fetch();
  if(!$col){ $pdo->exec("ALTER TABLE deliveries ADD COLUMN base_pay DECIMAL(10,2) DEFAULT 0.00"); }
}catch(Exception $e){ /* ignore */ }
try{
  $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'delivery_bonus'")->fetch();
  if(!$col){ $pdo->exec("ALTER TABLE deliveries ADD COLUMN delivery_bonus DECIMAL(10,2) DEFAULT 0.00"); }
}catch(Exception $e){ /* ignore */ }
try{
  $col = $pdo->query("SHOW COLUMNS FROM deliveries LIKE 'delivery_minutes'")->fetch();
  if(!$col){ $pdo->exec("ALTER TABLE deliveries ADD COLUMN delivery_minutes INT DEFAULT NULL"); }
}catch(Exception $e){ /* ignore */ }

// Ensure rider_ratings table exists (optional)
$pdo->exec("CREATE TABLE IF NOT EXISTS rider_ratings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rider_id INT NOT NULL,
  user_id INT DEFAULT NULL,
  rating TINYINT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Get list of riders
$rstmt = $pdo->query("SELECT id, name, email FROM users WHERE role = 'rider'");
$riders = $rstmt->fetchAll(PDO::FETCH_ASSOC);

// Build rider map for select options
$riderOptions = [];
foreach($riders as $r){ $riderOptions[$r['id']] = $r; }

// Fetch orders that have at least one accepted item or already in deliveries table
$sql = "SELECT o.id AS order_id, o.session_id, COALESCE(u.name, CONCAT('Guest ', LEFT(o.session_id,8))) AS customer_name,
         COALESCE(d.id, NULL) AS delivery_id, COALESCE(d.rider_id, NULL) AS rider_id, COALESCE(d.status, '') AS delivery_status, d.assigned_at, d.proof_path, d.proof_uploaded_at
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN deliveries d ON d.order_id = o.id
          WHERE (LOWER(o.status) = 'accepted')
            OR d.id IS NOT NULL
        ORDER BY o.created_at DESC
        LIMIT 200";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card p-3">
  <h3>Delivery Orders</h3>
  <div class="small mb-2">Manage deliveries. Assign riders when orders are accepted, and view rider names & ratings.</div>

  <div class="table-responsive">
    <table class="table table-striped table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Order ID</th>
          <th>Customer</th>
          <th>Driver</th>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($rows)): ?>
          <tr><td colspan="5">No deliveries found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr data-order="<?php echo (int)$r['order_id']; ?>">
            <td>#<?php echo (int)$r['order_id']; ?></td>
            <td><?php echo htmlspecialchars($r['customer_name']); ?></td>
            <td>
              <?php if(!empty($r['rider_id']) && isset($riderOptions[$r['rider_id']])): 
                $rv = $riderOptions[$r['rider_id']];
                // compute avg rating for rider
                $avg = $pdo->prepare('SELECT AVG(rating) FROM rider_ratings WHERE rider_id = :rid');
                $avg->execute([':rid'=>$r['rider_id']]);
                $avgv = $avg->fetchColumn();
                $avgFmt = $avgv ? round($avgv,2) : '—';
              ?>
                <div><strong><?php echo htmlspecialchars($rv['name'] ?: $rv['email']); ?></strong></div>
                <div class="small text-muted">Rating: <?php echo $avgFmt; ?></div>
              <?php else: ?>
                <select class="form-select form-select-sm rider-select" data-order="<?php echo (int)$r['order_id']; ?>">
                  <option value="">Choose rider...</option>
                  <?php foreach($riders as $ri): ?>
                    <option value="<?php echo (int)$ri['id']; ?>"><?php echo htmlspecialchars($ri['name'] ?: $ri['email']); ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </td>
            <td>
              <?php if(!empty($r['delivery_status'])): ?>
                <span class="badge bg-info"><?php echo htmlspecialchars($r['delivery_status']); ?></span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Pending</span>
              <?php endif; ?>
            </td>
                <td style="text-align:right">
              <?php if(empty($r['rider_id'])): ?>
                <button class="btn btn-sm btn-primary btn-assign" data-order="<?php echo (int)$r['order_id']; ?>">Assign</button>
              <?php else: ?>
                <button class="btn btn-sm btn-secondary btn-view-rider" data-rider="<?php echo (int)$r['rider_id']; ?>">View Rider</button>
                <?php
                  // If admin: show declare-complete when appropriate (no Details button)
                  $role = isset($_SESSION['user_role']) ? strtolower($_SESSION['user_role']) : '';
                  if(in_array($role, ['admin','owner'])):
                    if(!empty($r['delivery_status']) && strtolower($r['delivery_status']) === 'delivered'):
                ?>
                      <button class="btn btn-sm btn-success btn-declare-complete" data-order="<?php echo (int)$r['order_id']; ?>">Declare Completed</button>
                <?php
                    endif;
                  endif;
                ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// expose current user role to JS so UI can hide rating controls for admins/owners
const CURRENT_USER_ROLE = '<?php echo strtolower($_SESSION['user_role'] ?? ""); ?>';

// assign handler: POST to assign_delivery.php
document.querySelectorAll('.btn-assign').forEach(btn => {
  btn.addEventListener('click', function(){
    const orderId = this.dataset.order;
    const select = document.querySelector('.rider-select[data-order="'+orderId+'"]');
    if(!select) return alert('No rider selected');
    const riderId = select.value;
    if(!riderId) return alert('Please choose a rider');
    const self = this;
    self.disabled = true;
    fetch('assign_delivery.php', { method:'POST', body: new URLSearchParams({ order: orderId, rider: riderId }) })
      .then(r=>r.json()).then(j=>{
        if(j.ok){
          // update row in-place so user stays on the page (no navigation)
          const row = document.querySelector('tr[data-order="'+orderId+'"]');
          if(row){
            // fetch rider info to show name and rating
            fetch('get_rider_info.php?rider=' + encodeURIComponent(riderId)).then(r=>r.json()).then(data=>{
              const driverTd = row.querySelector('td:nth-child(3)');
              if(driverTd){
                driverTd.innerHTML = '';
                const nameDiv = document.createElement('div');
                nameDiv.innerHTML = '<strong>' + (data && data.name ? (data.name) : (data && data.email ? data.email : ('Rider '+riderId))) + '</strong>';
                const ratingDiv = document.createElement('div'); ratingDiv.className = 'small text-muted'; ratingDiv.textContent = 'Rating: ' + ((data && data.avg) ? data.avg : '—');
                driverTd.appendChild(nameDiv); driverTd.appendChild(ratingDiv);
              }
              // update status badge
              const badge = row.querySelector('td:nth-child(4) .badge');
              if(badge){ badge.className = 'badge bg-info'; badge.textContent = 'assigned'; }
              // replace assign button with view rider button (keep actions cell stable)
              const actionsTd = row.querySelector('td:nth-child(5)');
              if(actionsTd){
                const assignBtn = actionsTd.querySelector('.btn-assign'); if(assignBtn) assignBtn.remove();
                // add a small View Rider button
                const vr = document.createElement('button');
                vr.className = 'btn btn-sm btn-secondary btn-view-rider ms-1';
                vr.dataset.rider = riderId;
                vr.textContent = 'View Rider';
                actionsTd.insertBefore(vr, actionsTd.firstChild);
                // attach click handler to new button
                vr.addEventListener('click', function(){
                  const rid = this.dataset.rider;
                  fetch('get_rider_info.php?rider=' + encodeURIComponent(rid))
                    .then(r=>r.json()).then(j=>{
                      if(j.ok){ alert('Rider: '+ j.name + '\nRating: ' + (j.avg || '—')); }
                      else { alert(j.error || 'Not found'); }
                    }).catch(()=> alert('Network error'));
                });
              }
            }).catch(()=>{
              // on failure fetching rider info, still update UI minimally
              const driverTd = row.querySelector('td:nth-child(3)'); if(driverTd) driverTd.textContent = 'Assigned (Rider #' + riderId + ')';
              const badge = row.querySelector('td:nth-child(4) .badge'); if(badge){ badge.className = 'badge bg-info'; badge.textContent = 'assigned'; }
              const actionsTd = row.querySelector('td:nth-child(5)'); if(actionsTd){ const assignBtn = actionsTd.querySelector('.btn-assign'); if(assignBtn) assignBtn.remove(); }
            });
          }
        } else { alert(j.error||'Failed'); self.disabled = false; }
      }).catch(()=>{ alert('Network error'); self.disabled = false; });
  });
});

// view rider button: open a small alert with name and rating (could be modal)
// view rider button: show modal with details and rating control
function showRiderModal(rider){
  // remove existing
  const existing = document.getElementById('riderInfoModal'); if(existing) existing.remove();
  const tpl = `
    <div class="modal fade" id="riderInfoModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header"><h5 class="modal-title">Rider Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <div id="riderInfoBody">
              ${rider.profile_photo ? `<div class="text-center mb-3"><img src="${rider.profile_photo}" alt="Photo" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ddd;" /></div>` : ''}
              <div><strong>Name:</strong> ${rider.name || ''}</div>
              <div><strong>Email:</strong> ${rider.email || ''}</div>
              <div class="mt-2"><strong>Average Rating:</strong> <span id="riderAvg">${rider.avg || '—'}</span> <small id="riderCount">(${rider.count||0})</small></div>
              ${ (CURRENT_USER_ROLE === 'admin' || CURRENT_USER_ROLE === 'owner') ?
                `<div class="small text-muted mt-2">Administrators and owners cannot rate riders.</div>` :
                `
                  <div id="yourRating" class="small text-muted mt-2">${rider.your_rating ? 'Your rating: ' + rider.your_rating + '★' : ''}</div>
                  <hr />
                  <div><strong>Rate this rider</strong></div>
                  <div class="mt-2" id="riderRateControls">
                    ${[1,2,3,4,5].map(i=>`<button type="button" class="btn btn-sm btn-outline-primary me-1 rate-btn" data-rating="${i}">${i}★</button>`).join('')}
                  </div>
                  <div class="mt-2" id="riderRateMsg"></div>
                ` }
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', tpl);
  const mEl = document.getElementById('riderInfoModal');
  const modal = new bootstrap.Modal(mEl);
  modal.show();

  // highlight existing rating for this customer (if any)
  const existingRating = rider.your_rating ? parseInt(rider.your_rating) : null;
  mEl.querySelectorAll('.rate-btn').forEach(b => {
    const val = parseInt(b.dataset.rating, 10);
    if(existingRating && val === existingRating){
      b.className = 'btn btn-sm btn-primary me-1 rate-btn';
      b.textContent = val + '★ (Your rating)';
    }
    b.addEventListener('click', function(){
      const rating = this.dataset.rating;
      // optimistic UI: disable all buttons while submitting
      mEl.querySelectorAll('.rate-btn').forEach(x=>x.disabled = true);
      const msgEl = mEl.querySelector('#riderRateMsg'); if(msgEl) msgEl.textContent = 'Submitting...';
      fetch('rate_rider.php', { method: 'POST', body: new URLSearchParams({ rider_id: rider.id, rating: rating }) })
        .then(r=>r.json()).then(j=>{
          if(j.ok){
            const avgEl = mEl.querySelector('#riderAvg'); const cntEl = mEl.querySelector('#riderCount');
            if(avgEl) avgEl.textContent = j.avg;
            if(cntEl) cntEl.textContent = '('+ (j.count||0) +')';
            if(msgEl) msgEl.innerHTML = '<div class="text-success small">Thanks for rating.</div>';
            // update your rating display and button highlights
            const yourEl = mEl.querySelector('#yourRating'); if(yourEl) yourEl.textContent = 'Your rating: ' + rating + '★';
            mEl.querySelectorAll('.rate-btn').forEach(x=>{
              const v = parseInt(x.dataset.rating,10);
              if(v === parseInt(rating,10)){
                x.className = 'btn btn-sm btn-primary me-1 rate-btn';
                x.textContent = v + '★ (Your rating)';
              } else {
                x.className = 'btn btn-sm btn-outline-primary me-1 rate-btn';
                x.textContent = v + '★';
              }
              x.disabled = false;
            });
          } else {
            if(msgEl) msgEl.innerHTML = '<div class="text-danger small">' + (j.error || 'Failed to submit') + '</div>';
            mEl.querySelectorAll('.rate-btn').forEach(x=>x.disabled = false);
          }
        }).catch(()=>{ if(msgEl) msgEl.innerHTML = '<div class="text-danger small">Network error</div>'; mEl.querySelectorAll('.rate-btn').forEach(x=>x.disabled = false); });
    });
  });

  // cleanup modal element when hidden to avoid backdrop issues
  mEl.addEventListener('hidden.bs.modal', function(){ try{ mEl.remove(); }catch(e){} });
}

// attach to existing buttons (if any)
document.querySelectorAll('.btn-view-rider').forEach(btn => {
  btn.addEventListener('click', function(){
    const rid = this.dataset.rider;
    fetch('get_rider_info.php?rider=' + encodeURIComponent(rid))
      .then(r=>r.json()).then(j=>{
        if(j.ok){ showRiderModal(j); }
        else { alert(j.error || 'Not found'); }
      }).catch(()=> alert('Network error'));
  });
});

// Use event delegation to handle view/accept/complete/reject clicks reliably
document.addEventListener('click', function(e){
  const t = e.target;
  const row = t.closest && t.closest('tr[data-order]');
  if(!row) return;
  const orderId = row.dataset.order;

  // view
  if(t.classList.contains('btn-view')){
    fetch('get_order_admin.php?order=' + encodeURIComponent(orderId))
      .then(r=>r.json()).then(j=>{
        if(!j.ok) return alert(j.error||'Failed to load');
        const o = j.order; const items = j.items || [];
        let html = 'Order #'+orderId+'\nDate: '+(o.created_at||'')+'\nTotal: $'+(parseFloat(o.total).toFixed(2)||'0.00')+'\n\nItems:\n';
        items.forEach(it=> html += '- '+it.product_name+' x'+it.quantity+' ($'+parseFloat(it.price).toFixed(2)+') ['+it.status+']\n');
        alert(html);
      }).catch(()=> alert('Network error'));
    return;
  }

  // details (show proof modal + confirm/reject)
  if(t.classList.contains('btn-details')){
    const proof = t.dataset.proof || '';
    const deliveryId = t.dataset.delivery || null;
    const status = (t.dataset.status || '').toLowerCase();
    const canConfirm = status === 'waiting';
    const orderId = row.dataset.order;
    fetch('get_order_admin.php?order=' + encodeURIComponent(orderId))
      .then(r=>r.json()).then(j=>{
        if(!j.ok){
          showProofModal(proof, deliveryId, false, row, false, orderId);
          return;
        }
        const paid = !!(j.order && (j.order.paid == 1 || j.order.paid === '1'));
        showProofModal(proof, deliveryId, canConfirm, row, paid, orderId);
      }).catch(()=>{
        showProofModal(proof, deliveryId, false, row, false, orderId);
      });
    return;
  }

  // accept delivery
  if(t.classList.contains('btn-accept-delivery')){
    if(!confirm('Accept this delivery?')) return;
    t.disabled = true;
    fetch('delivery_action.php', { method:'POST', body: new URLSearchParams({ order: orderId, action: 'accept' }) })
      .then(r=>r.json()).then(j=>{
        if(j.ok){ location.reload(); } else { alert(j.error||'Failed'); t.disabled = false; }
      }).catch(()=>{ alert('Network error'); t.disabled = false; });
    return;
  }

  // declare complete (admin)
  if(t.classList.contains('btn-declare-complete')){
    if(!confirm('Declare this order completed? This will mark items as completed.')) return;
    t.disabled = true;
    fetch('admin_complete_order.php', { method:'POST', body: new URLSearchParams({ order: orderId }) })
      .then(r=>r.json()).then(j=>{
        if(j.ok){ location.reload(); } else { alert(j.error||'Failed'); t.disabled = false; }
      }).catch(()=>{ alert('Network error'); t.disabled = false; });
    return;
  }

  // reject delivery
  if(t.classList.contains('btn-reject-delivery')){
    if(!confirm('Reject this delivery?')) return;
    t.disabled = true;
    fetch('delivery_action.php', { method:'POST', body: new URLSearchParams({ order: orderId, action: 'reject' }) })
      .then(r=>r.json()).then(j=>{
        if(j.ok){ location.reload(); } else { alert(j.error||'Failed'); t.disabled = false; }
      }).catch(()=>{ alert('Network error'); t.disabled = false; });
    return;
  }
});
  // Proof modal helper - shows image and optional confirm
  function showProofModal(proofUrl, deliveryId, canConfirm, row, orderPaid, orderId){
    // remove existing modal
    const existing = document.getElementById('adminProofModal'); if(existing) existing.remove();
    const hasImage = !!proofUrl;
    const imgHtml = hasImage ? `<img src="${proofUrl}" alt="Proof" style="max-width:100%;height:auto;border-radius:8px;" />` : '<div class="alert alert-warning">No proof uploaded by rider.</div>';
    // show confirm/reject controls when delivery is waiting; disable confirm button until order is paid
    const confirmDisabledAttr = (!orderPaid) ? 'disabled' : '';
    const tpl = `
      <div class="modal fade" id="adminProofModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Proof of Delivery</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body text-center">
              ${imgHtml}
              <div class="mt-3" id="proofMeta"></div>
              <div id="proofMessage" style="margin-top:15px;"></div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              ${canConfirm ? `<button type="button" id="rejectProofBtn" class="btn btn-danger" ${confirmDisabledAttr}>Reject</button>` : ''}
              ${canConfirm ? `<button type="button" id="confirmProofBtn" class="btn btn-success" ${confirmDisabledAttr}>Confirm</button>` : ''}
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', tpl);
    const mEl = document.getElementById('adminProofModal');
    const modal = new bootstrap.Modal(mEl);
    modal.show();
    
    if(canConfirm){
      // Confirm button handler
      document.getElementById('confirmProofBtn').addEventListener('click', function(){
        this.disabled = true;
        fetch('admin_confirm_proof.php', { method: 'POST', body: new URLSearchParams({ delivery_id: deliveryId }) })
          .then(r=>r.json()).then(j=>{
            if(j.ok){
              // show success message
              const msgEl = document.getElementById('proofMessage');
              if(msgEl) msgEl.innerHTML = '<div class="alert alert-success"><strong>✓ Delivery confirmed!</strong></div>';
              // update UI row badge
              const badge = row.querySelector('td:nth-child(4) .badge');
              if(badge){ badge.textContent = 'confirmed'; badge.className = 'badge bg-success'; }
              // close modal after 1.5 seconds
              setTimeout(() => { try { modal.hide(); mEl.remove(); } catch(e){} }, 1500);
            } else { 
              const msgEl = document.getElementById('proofMessage');
              if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger"><strong>Error:</strong> ' + (j.error || 'Failed to confirm') + '</div>';
              this.disabled = false;
            }
          }).catch(()=>{ 
            const msgEl = document.getElementById('proofMessage');
            if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger"><strong>Error:</strong> Network error</div>';
            this.disabled = false;
          });
      });
      
      // Reject button handler
      document.getElementById('rejectProofBtn').addEventListener('click', function(){
        if(!confirm('Reject this delivery proof? Rider will need to resubmit.')) return;
        this.disabled = true;
        fetch('admin_confirm_proof.php', { method: 'POST', body: new URLSearchParams({ delivery_id: deliveryId, action: 'reject' }) })
          .then(r=>r.json()).then(j=>{
            if(j.ok){
              // show success message
              const msgEl = document.getElementById('proofMessage');
              if(msgEl) msgEl.innerHTML = '<div class="alert alert-warning"><strong>✓ Delivery rejected</strong> - Rider notified</div>';
              // update UI row badge
              const badge = row.querySelector('td:nth-child(4) .badge');
              if(badge){ badge.textContent = 'rejected'; badge.className = 'badge bg-danger'; }
              // close modal after 1.5 seconds
              setTimeout(() => { try { modal.hide(); mEl.remove(); } catch(e){} }, 1500);
            } else { 
              const msgEl = document.getElementById('proofMessage');
              if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger"><strong>Error:</strong> ' + (j.error || 'Failed to reject') + '</div>';
              this.disabled = false;
            }
          }).catch(()=>{ 
            const msgEl = document.getElementById('proofMessage');
            if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger"><strong>Error:</strong> Network error</div>';
            this.disabled = false;
          });
      });
    }
  }

  
</script>
