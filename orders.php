<?php
// orders.php — admin orders list (DB-backed)
include_once __DIR__ . '/db.php';
$pdo = getPDO();

// Fetch recent orders with aggregated items and simple status counts
// prefer an explicit order-level status when available (o.status), alias as db_status
$sql = "SELECT o.id, o.total, o.created_at, o.session_id, o.status AS db_status, COALESCE(u.name, CONCAT('Guest ', LEFT(o.session_id,8))) AS customer_name,
               GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ') AS items,
               COUNT(oi.id) AS items_count,
               SUM(CASE WHEN LOWER(oi.status) IN ('processing','pending') THEN 1 ELSE 0 END) AS pending_count,
               SUM(CASE WHEN LOWER(oi.status) IN ('accepted') THEN 1 ELSE 0 END) AS accepted_count,
               SUM(CASE WHEN LOWER(oi.status) IN ('completed','delivered') THEN 1 ELSE 0 END) AS completed_count,
               SUM(CASE WHEN LOWER(oi.status) = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 200";

$stmt = $pdo->query($sql);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure we display the authoritative order-level status from the orders table
// (this avoids cases where joins or cached/grouped results may be out-of-sync)
if(!empty($orders)){
  $ids = array_map(function($r){ return (int)$r['id']; }, $orders);
  $place = implode(',', $ids);
  try{
    $map = [];
    $s = $pdo->query("SELECT id, status FROM orders WHERE id IN ($place)");
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $rr) $map[(int)$rr['id']] = $rr['status'] ?? null;
    foreach($orders as &$o){
      $iid = (int)$o['id'];
      if(isset($map[$iid]) && $map[$iid] !== null){ $o['db_status'] = $map[$iid]; }
    }
    unset($o);
  }catch(Exception $e){ /* ignore and keep existing values */ }
}
// Show completed toggle (default: hide completed). Use ?show_completed=1 to include them.
$showCompleted = isset($_GET['show_completed']) && $_GET['show_completed'] === '1';

// If not showing completed, filter out orders that are already completed (by order-level status or by items)
if(!$showCompleted){
  $filtered = [];
  foreach($orders as $o){
    $rawDbStatus = trim((string)($o['db_status'] ?? ''));
    $isCompleted = false;
    if($rawDbStatus !== ''){
      $s = strtolower($rawDbStatus);
      if(in_array($s, ['completed','delivered','complete'])) $isCompleted = true;
    } else {
      $pending = (int)($o['pending_count'] ?? 0);
      $accepted = (int)($o['accepted_count'] ?? 0);
      $completed = (int)($o['completed_count'] ?? 0);
      $items_count = (int)($o['items_count'] ?? 0);
      if($items_count > 0){
        if($completed > 0 && $completed >= $items_count) $isCompleted = true;
        elseif($accepted > 0 && ($accepted + $completed) >= $items_count) $isCompleted = true;
      }
    }
    if($isCompleted) continue;
    $filtered[] = $o;
  }
  $orders = $filtered;
}

// Build list of available years from orders (all orders)
$years = [];
foreach($orders as $oy){
  if(!empty($oy['created_at'])){
    $y = (int)substr($oy['created_at'],0,4);
    if($y > 0) $years[$y] = $y;
  }
}
ksort($years);

// Fetch completed orders into a separate list so we can render them in their own table
try{
  $completedSql = "SELECT o.id, o.total, o.created_at, o.session_id, o.status AS db_status, COALESCE(u.name, CONCAT('Guest ', LEFT(o.session_id,8))) AS customer_name,
               GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ') AS items,
               COUNT(oi.id) AS items_count,
               SUM(CASE WHEN LOWER(oi.status) IN ('completed','delivered') THEN 1 ELSE 0 END) AS completed_count
        FROM orders o
        LEFT JOIN users u ON u.id = o.user_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        GROUP BY o.id
        HAVING (LOWER(COALESCE(o.status,'')) IN ('completed','delivered','complete'))
           OR (COUNT(oi.id) > 0 AND SUM(CASE WHEN LOWER(oi.status) IN ('completed','delivered') THEN 1 ELSE 0 END) >= COUNT(oi.id))
        ORDER BY o.created_at DESC
        LIMIT 200";
  $cstmt = $pdo->query($completedSql);
  $completedOrders = $cstmt->fetchAll(PDO::FETCH_ASSOC);
}catch(Exception $e){ $completedOrders = []; }
?>

<div class="card p-3">
  <h3>Orders</h3>
  <div class="small mb-2">Manage all customer orders. Click to view details.</div>

  <div style="display:flex;gap:12px;align-items:center;justify-content:space-between;margin-bottom:12px;">
    <div>
      <label style="margin-right:8px;"><input type="checkbox" id="showCompleted" <?php echo $showCompleted? 'checked' : ''; ?>> Show completed</label>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <label for="downloadYear">Download orders:</label>
      <select id="downloadYear">
        <option value="all">All years</option>
        <?php foreach($years as $y): ?>
          <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
        <?php endforeach; ?>
      </select>
      <button id="downloadBtn" class="btn btn-sm btn-outline-primary">Download CSV</button>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Order ID</th>
          <th>Customer</th>
          <th>Items</th>
          <th>Total</th>
          <th>Status</th>
          <th>Date</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($orders)): ?>
          <tr><td colspan="7">No orders found.</td></tr>
        <?php else: foreach($orders as $o):
            $pending = (int)($o['pending_count'] ?? 0);
            $accepted = (int)($o['accepted_count'] ?? 0);
            $completed = (int)($o['completed_count'] ?? 0);
            $cancelled = (int)($o['cancelled_count'] ?? 0);
            $items_count = (int)($o['items_count'] ?? 0);
            
            // Determine status - prefer explicit order.status column from database
            // Prefer the explicit order-level status stored in orders.status as the single source of truth
            $rawDbStatus = trim((string)($o['db_status'] ?? ''));
            if($rawDbStatus !== ''){
              $s = strtolower($rawDbStatus);
              if(in_array($s, ['processing','pending','approved'])){ $status = 'Pending'; }
              elseif($s === 'accepted'){ $status = 'Accepted'; }
              elseif(in_array($s, ['completed','delivered'])){ $status = 'Completed'; }
              elseif($s === 'cancelled'){ $status = 'Cancelled'; }
              else { $status = ucfirst($s); }
            } else {
              // Fallback only when orders.status is empty
              $status = 'Processing';
              if($pending > 0) $status = 'Pending';
              elseif($cancelled > 0 && $completed === 0 && $accepted === 0) $status = 'Cancelled';
              elseif($completed > 0 && $completed >= $items_count) $status = 'Completed';
              elseif($accepted > 0 && $accepted + $completed >= $items_count) $status = 'Completed';
              elseif($accepted > 0) $status = 'Accepted';
            }

            // If not showing completed, skip rows computed as Completed
            if(!$showCompleted && $status === 'Completed') continue;
        ?>
        <tr>
          <td>#<?php echo (int)$o['id']; ?></td>
          <td><?php echo htmlspecialchars($o['customer_name']); ?></td>
          <td style="max-width:320px"><?php echo htmlspecialchars($o['items'] ?? ''); ?></td>
          <td>$<?php echo number_format((float)$o['total'],2); ?></td>
          <td>
            <?php if($status === 'Pending'): ?>
              <span class="badge bg-warning text-dark"><?php echo $status; ?></span>
            <?php elseif($status === 'Accepted'): ?>
              <span class="badge bg-info"><?php echo $status; ?></span>
            <?php elseif($status === 'Completed'): ?>
              <span class="badge bg-success"><?php echo $status; ?></span>
            <?php elseif($status === 'Cancelled'): ?>
              <span class="badge bg-danger"><?php echo $status; ?></span>
            <?php else: ?>
              <span class="badge bg-secondary"><?php echo $status; ?></span>
            <?php endif; ?>
          </td>
          <td><?php echo htmlspecialchars($o['created_at']); ?></td>
          <td style="text-align:right; display:flex; gap:4px; justify-content:flex-end; flex-wrap:wrap;">
            <button class="btn btn-sm btn-primary btn-view" data-id="<?php echo (int)$o['id']; ?>">View</button>
            <?php if($status === 'Cancelled'): ?>
              <button class="btn btn-sm btn-outline-danger btn-remove" data-id="<?php echo (int)$o['id']; ?>">Remove</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php // Completed orders section ?>
<div class="card p-3 mt-4">
  <h3>Completed Orders</h3>
  <div class="small mb-2">Orders that are completed or fully delivered.</div>

  <div class="table-responsive">
    <table class="table table-striped table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Order ID</th>
          <th>Customer</th>
          <th>Items</th>
          <th>Total</th>
          <th>Date</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($completedOrders)): ?>
          <tr><td colspan="6">No completed orders found.</td></tr>
        <?php else: foreach($completedOrders as $co): ?>
          <tr>
            <td>#<?php echo (int)$co['id']; ?></td>
            <td><?php echo htmlspecialchars($co['customer_name']); ?></td>
            <td style="max-width:320px"><?php echo htmlspecialchars($co['items'] ?? ''); ?></td>
            <td>$<?php echo number_format((float)$co['total'],2); ?></td>
            <td><?php echo htmlspecialchars($co['created_at']); ?></td>
            <td style="text-align:right;">
              <button class="btn btn-sm btn-primary btn-view" data-id="<?php echo (int)$co['id']; ?>">View</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
  // Download and show-completed controls
  document.getElementById('showCompleted')?.addEventListener('change', function(){
    const checked = this.checked ? '1' : '0';
    const url = new URL(window.location.href);
    if(checked === '1') url.searchParams.set('show_completed','1'); else url.searchParams.delete('show_completed');
    window.location.href = url.toString();
  });

  document.getElementById('downloadBtn')?.addEventListener('click', function(){
    const y = document.getElementById('downloadYear').value || 'all';
    const url = 'download_orders.php?year=' + encodeURIComponent(y);
    window.location.href = url;
  });

// Confirmation modal
function showConfirmDialog(title, message, onConfirm, onCancel) {
  const dialog = document.createElement('div');
  dialog.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:10000;display:flex;align-items:center;justify-content:center;';
  dialog.innerHTML = `
    <div style="background:#fff;padding:20px;border-radius:8px;max-width:400px;width:90%;">
      <h5 style="margin-bottom:12px;">${escapeHtml(title)}</h5>
      <p style="margin-bottom:20px;color:#666;">${escapeHtml(message)}</p>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <button class="btn btn-sm btn-outline-secondary" id="cancelBtn">Cancel</button>
        <button class="btn btn-sm btn-danger" id="confirmBtn">Confirm</button>
      </div>
    </div>
  `;
  document.body.appendChild(dialog);
  
  dialog.querySelector('#confirmBtn').addEventListener('click', () => {
    dialog.remove();
    onConfirm();
  });
  
  dialog.querySelector('#cancelBtn').addEventListener('click', () => {
    dialog.remove();
    if(onCancel) onCancel();
  });
  
  dialog.addEventListener('click', (e) => {
    if(e.target === dialog) {
      dialog.remove();
      if(onCancel) onCancel();
    }
  });
}

// Initialize all event handlers using event delegation
function initializeEventHandlers(){
  // Use event delegation on the document for accept/cancel/remove buttons
  document.addEventListener('click', function(e){
    // Accept button
    if(e.target.classList.contains('btn-accept')){
      const btn = e.target;
      const id = btn.dataset.id;
      showConfirmDialog(
        'Accept Order',
        'Are you sure you want to accept this order?',
        () => {
          btn.disabled = true;
          fetch('update_order_status.php', { method: 'POST', body: new URLSearchParams({ order: id, action: 'accept' }) })
            .then(r=>r.json()).then(j=>{
              if(j.ok){
                // reload to reflect authoritative DB status
                location.reload();
              } else {
                alert(j.error || 'Failed'); btn.disabled = false;
              }
            }).catch(e=>{ alert('Network error'); btn.disabled = false; });
        }
      );
    }
    
    // Cancel button
    if(e.target.classList.contains('btn-cancel')){
      const btn = e.target;
      const id = btn.dataset.id;
      showConfirmDialog(
        'Cancel Order',
        'Are you sure you want to cancel this order? This will mark items as cancelled.',
        () => {
          btn.disabled = true;
          fetch('update_order_status.php', { method: 'POST', body: new URLSearchParams({ order: id, action: 'cancel' }) })
            .then(r=>r.json()).then(j=>{
              if(j.ok){
                // reload to reflect authoritative DB status
                location.reload();
              } else {
                alert(j.error || 'Failed'); btn.disabled = false;
              }
            }).catch(e=>{ alert('Network error'); btn.disabled = false; });
        }
      );
    }
    
    // Remove button
    if(e.target.classList.contains('btn-remove')){
      const btn = e.target;
      const id = btn.dataset.id;
      showConfirmDialog(
        'Remove Order',
        'Permanently remove this cancelled order? This action cannot be undone.',
        () => {
          btn.disabled = true;
          fetch('delete_order.php', { method: 'POST', body: new URLSearchParams({ order: id }) })
            .then(r=>r.json()).then(j=>{
              if(j.ok){
                  // reload so the list matches DB
                  location.reload();
              } else { alert(j.error || 'Failed'); btn.disabled = false; }
            }).catch(e=>{ alert('Network error'); btn.disabled = false; });
        }
      );
    }
    
    // View button
    if(e.target.classList.contains('btn-view')){
      const btn = e.target;
      const id = btn.dataset.id;
      modalTitle.textContent = 'Order #' + id;
      modalBody.innerHTML = '<div class="p-3">Loading…</div>';
      openModal();

      fetch('get_order_admin.php?order=' + encodeURIComponent(id))
        .then(r => r.json())
        .then(j => {
          if(!j.ok){ modalBody.innerHTML = '<div class="alert alert-danger">'+ (j.error || 'Failed to load') +'</div>'; return; }
          const o = j.order; const items = j.items || [];
          
          let html = '<div style="margin-bottom:16px;"><strong>Order Date:</strong> '+ (o.created_at || '') +'</div>';
          html += '<div style="margin-bottom:16px;"><strong>Total:</strong> <span style="font-size:18px;font-weight:bold;color:#EE6F57;">$'+ (parseFloat(o.total).toFixed(2) || '0.00') +'</span></div>';
          
          if(items.length === 0){
            html += '<div class="mt-2 text-muted">No products in this order.</div>';
          } else {
            html += '<table class="table table-sm mt-2" style="border:1px solid #ddd;"><thead style="background:#f5f5f5;"><tr><th>Product</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Price</th><th style="text-align:right;">Total</th><th style="text-align:center;">Status</th></tr></thead><tbody>';
            items.forEach(it => {
              const itemTotal = (parseFloat(it.price) * parseInt(it.quantity)).toFixed(2);
              const statusLower = String(it.status).toLowerCase();
              let statusColor = 'warning';
              if(statusLower === 'completed') statusColor = 'success';
              else if(statusLower === 'cancelled') statusColor = 'danger';
              else if(statusLower === 'accepted') statusColor = 'info';
              html += '<tr style="border-bottom:1px solid #eee;"><td style="padding:10px;font-weight:500;">'+ escapeHtml(it.product_name) +'</td><td style="text-align:right;padding:10px;">'+ (parseInt(it.quantity)||0) +'</td><td style="text-align:right;padding:10px;">$'+ (parseFloat(it.price).toFixed(2)||'0.00') +'</td><td style="text-align:right;padding:10px;font-weight:600;">$'+ itemTotal +'</td><td style="text-align:center;padding:10px;"><span class="badge bg-'+ statusColor +'">'+ escapeHtml(it.status) +'</span></td></tr>';
            });
            html += '</tbody></table>';
          }
          
          modalBody.innerHTML = html;

          // If delivery info was returned, show proof and confirm/reject buttons
            if(j.delivery){
            const d = j.delivery;
            const hasProof = !!d.proof_path;
            let proofHtml = '<div style="margin-top:12px;">';
            if(hasProof){
              proofHtml += '<div class="text-center"><img src="'+ escapeHtml(d.proof_path) +'" alt="Proof" style="max-width:100%;height:auto;border-radius:8px;" /></div>';
            } else {
              proofHtml += '<div class="alert alert-warning">No proof uploaded by rider.</div>';
            }
            proofHtml += '<div id="proofMessage" style="margin-top:10px;"></div>';

            // Only show confirm/reject when delivery waiting for confirmation
            const statusLower = (d.status||'').toLowerCase();
            if(statusLower === 'waiting' || statusLower === 'assigned'){
              const confirmDisabled = '';
              proofHtml += '<div style="text-align:center;margin-top:10px;"><button id="confirmProofBtn" class="btn btn-success" '+confirmDisabled+'>Confirm</button><button id="rejectProofBtn" class="btn btn-danger" style="margin-left:8px;">Reject</button></div>';
            } else {
              proofHtml += '<div style="text-align:center;margin-top:10px;"><span class="badge bg-secondary">'+ escapeHtml(d.status || '') +'</span></div>';
            }
            proofHtml += '</div>';
            modalBody.insertAdjacentHTML('beforeend', proofHtml);
            // Add direct handlers for confirm/reject buttons (attached once per modal open)
            const confirmBtn = document.getElementById('confirmProofBtn');
            const rejectBtn = document.getElementById('rejectProofBtn');
            if(confirmBtn){
              confirmBtn.addEventListener('click', function(){
                this.disabled = true;
                const btn = this;
                fetch('admin_confirm_proof.php', { method: 'POST', body: new URLSearchParams({ delivery_id: d.id }) })
                  .then(r=>r.json()).then(res=>{
                    const msgEl = document.getElementById('proofMessage');
                    if(res.ok){ if(msgEl) msgEl.innerHTML = '<div class="alert alert-success">✓ Delivery confirmed</div>'; try{ confirmBtn.remove(); rejectBtn?.remove(); }catch(e){}; const row = document.querySelector('button.btn-view[data-id="'+id+'"]')?.closest('tr'); if(row){ const b = row.querySelector('.badge'); if(b){ b.className='badge bg-success'; b.textContent='Completed'; } } setTimeout(()=>{ location.reload(); }, 900); }
                    else { if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger">Error: '+ (res.error||'Failed to confirm') +'</div>'; btn.disabled = false; }
                  }).catch(()=>{ const msgEl = document.getElementById('proofMessage'); if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger">Network error</div>'; btn.disabled = false; });
              });
            }
            if(rejectBtn){
              rejectBtn.addEventListener('click', function(){
                if(!confirm('Reject this delivery proof? Rider will need to resubmit.')) return;
                this.disabled = true;
                const btn = this;
                fetch('admin_confirm_proof.php', { method: 'POST', body: new URLSearchParams({ delivery_id: d.id, action: 'reject' }) })
                  .then(r=>r.json()).then(res=>{
                    const msgEl = document.getElementById('proofMessage');
                    if(res.ok){ if(msgEl) msgEl.innerHTML = '<div class="alert alert-warning">✓ Delivery rejected - Rider notified</div>'; try{ confirmBtn?.remove(); rejectBtn.remove(); }catch(e){}; const row = document.querySelector('button.btn-view[data-id="'+id+'"]')?.closest('tr'); if(row){ const b = row.querySelector('.badge'); if(b){ b.className='badge bg-danger'; b.textContent='rejected'; } } setTimeout(()=>{ location.reload(); }, 900); }
                    else { if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger">Error: '+ (res.error||'Failed to reject') +'</div>'; btn.disabled = false; }
                  }).catch(()=>{ const msgEl = document.getElementById('proofMessage'); if(msgEl) msgEl.innerHTML = '<div class="alert alert-danger">Network error</div>'; btn.disabled = false; });
              });
            }
          }

          // show action buttons based on explicit order-level status if available
          const orderStatusLower = (o.status || '').toLowerCase();
          const hasPending = ['processing','pending','approved'].includes(orderStatusLower);
          const hasAccepted = orderStatusLower === 'accepted';
          const allCompleted = ['completed','delivered'].includes(orderStatusLower);
          const allCancelled = orderStatusLower === 'cancelled';

          if(!allCompleted && !allCancelled && !hasAccepted){ modalComplete.style.display = 'inline-block'; }
          if(hasPending || hasAccepted){ modalCancel.style.display = 'inline-block'; }

          // Keep the orders list in sync: update the table row badge immediately
          (function syncTableBadge(){
            const tableRow = document.querySelector('button.btn-view[data-id="'+id+'"]')?.closest('tr');
            if(!tableRow) return;
            const b = tableRow.querySelector('.badge');
            if(allCompleted){ if(b){ b.className = 'badge bg-success'; b.textContent = 'Completed'; } }
            else if(allCancelled){ if(b){ b.className = 'badge bg-danger'; b.textContent = 'Cancelled'; } }
            else if(hasAccepted){ if(b){ b.className = 'badge bg-info'; b.textContent = 'Accepted'; } }
            else if(hasPending){ if(b){ b.className = 'badge bg-warning text-dark'; b.textContent = 'Pending'; } }
          })();

          // wire actions: Accept (mark accepted) instead of complete
          modalComplete.onclick = function(){
            showConfirmDialog(
              'Accept Order',
              'Are you sure you want to accept this order?',
              () => {
                modalComplete.disabled = true;
                fetch('update_order_status.php', { method:'POST', body: new URLSearchParams({ order: id, action: 'accept' }) })
                  .then(r=>r.json()).then(res=>{
                    if(res.ok){
                      modalBody.insertAdjacentHTML('afterbegin','<div class="alert alert-success">Order accepted</div>');
                      const row = document.querySelector('button.btn-view[data-id="'+id+'"]')?.closest('tr');
                      if(row){ const b = row.querySelector('.badge'); if(b){ b.className='badge bg-info'; b.textContent='Accepted'; } }
                      modalComplete.style.display = 'none';
                      modalCancel.style.display = 'inline-block';
                      setTimeout(() => { location.reload(); }, 1000);
                    } else { alert(res.error||'Failed'); modalComplete.disabled = false; }
                  }).catch(()=>{ alert('Network error'); modalComplete.disabled = false; });
              }
            );
          };

          modalCancel.onclick = function(){
            showConfirmDialog(
              'Cancel Order',
              'Are you sure you want to cancel this order? This will mark items as cancelled.',
              () => {
                modalCancel.disabled = true;
                fetch('update_order_status.php', { method:'POST', body: new URLSearchParams({ order: id, action: 'cancel' }) })
                  .then(r=>r.json()).then(res=>{
                    if(res.ok){
                      modalBody.insertAdjacentHTML('afterbegin','<div class="alert alert-success">Order cancelled</div>');
                      const row = document.querySelector('button.btn-view[data-id="'+id+'"]')?.closest('tr');
                      if(row){ const b = row.querySelector('.badge'); if(b){ b.className='badge bg-danger'; b.textContent='Cancelled'; } }
                      modalCancel.disabled = true; modalCancel.textContent = 'Cancelled'; modalCancel.className = 'btn btn-secondary';
                      modalComplete.style.display = 'none';
                      setTimeout(() => { location.reload(); }, 1000);
                    } else { alert(res.error||'Failed'); modalCancel.disabled = false; }
                  }).catch(()=>{ alert('Network error'); modalCancel.disabled = false; });
              }
            );
          };

        }).catch(() => { modalBody.innerHTML = '<div class="alert alert-danger">Network error</div>'; });
    }
  });
}

</script>

<!-- Modal for viewing order details -->
<div id="orderModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
  <div style="background:#fff; max-width:720px; width:92%; margin:0 auto; padding:16px; border-radius:8px;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h4 id="modalTitle">Order</h4>
      <button id="modalClose" class="btn btn-sm btn-outline-secondary">Close</button>
    </div>
    <div id="modalBody" style="margin-top:12px; max-height:60vh; overflow:auto;"></div>
    <div style="margin-top:12px; text-align:right;">
      <button id="modalComplete" class="btn btn-info" style="display:none;">Accept</button>
      <button id="modalCancel" class="btn btn-danger" style="display:none; margin-left:8px;">Cancel</button>
    </div>
  </div>
</div>

<script>
// Modal helpers
const orderModal = document.getElementById('orderModal');
const modalBody = document.getElementById('modalBody');
const modalTitle = document.getElementById('modalTitle');
const modalClose = document.getElementById('modalClose');
const modalComplete = document.getElementById('modalComplete');
const modalCancel = document.getElementById('modalCancel');

function openModal(){ orderModal.style.display = 'flex'; }
function closeModal(){ orderModal.style.display = 'none'; modalBody.innerHTML = ''; modalComplete.style.display = 'none'; modalCancel.style.display = 'none'; }
modalClose.addEventListener('click', closeModal);

function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]; }); }

// Initialize when DOM is ready
function initializeAll(){
  initializeEventHandlers();
}

if(document.readyState === 'loading'){
  document.addEventListener('DOMContentLoaded', initializeAll);
} else {
  initializeAll();
}
</script>
