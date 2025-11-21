<?php
// bank-info.php — list bank accounts from DB; view (AJAX) and Accept action (no edit)
include_once __DIR__ . '/db.php';
$pdo = getPDO();

// create simple bank_accounts table if missing
$pdo->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bank_name VARCHAR(255) NOT NULL,
  account_name VARCHAR(255) NOT NULL,
  account_number VARCHAR(128) NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// fetch accounts
$stmt = $pdo->query('SELECT * FROM bank_accounts ORDER BY created_at DESC');
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="card p-3">
  <h3>Bank Information</h3>
  <div class="small mb-2">View bank accounts in the database. You can accept (activate) accounts here.</div>

  <div class="table-responsive">
    <table class="table table-striped table-bordered align-middle">
      <thead class="table-light">
        <tr>
          <th>Bank ID</th>
          <th>Bank Name</th>
          <th>Account Name</th>
          <th>Account Number</th>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($accounts)): ?>
          <tr><td colspan="6">No bank accounts in the database.</td></tr>
        <?php else: foreach($accounts as $a): ?>
          <tr data-id="<?php echo (int)$a['id']; ?>">
            <td>#B<?php echo str_pad($a['id'],3,'0',STR_PAD_LEFT); ?></td>
            <td><?php echo htmlspecialchars($a['bank_name']); ?></td>
            <td><?php echo htmlspecialchars($a['account_name']); ?></td>
            <td><?php echo htmlspecialchars($a['account_number']); ?></td>
            <td>
              <?php if(strtolower($a['status']) === 'accepted' || strtolower($a['status']) === 'active'): ?>
                <span class="badge bg-success"><?php echo htmlspecialchars($a['status']); ?></span>
              <?php elseif(strtolower($a['status']) === 'pending'): ?>
                <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($a['status']); ?></span>
              <?php else: ?>
                <span class="badge bg-secondary"><?php echo htmlspecialchars($a['status']); ?></span>
              <?php endif; ?>
            </td>
            <td style="text-align:right">
              <button class="btn btn-sm btn-primary btn-view-bank" data-id="<?php echo (int)$a['id']; ?>">View</button>
              <?php if(strtolower($a['status']) !== 'accepted' && strtolower($a['status']) !== 'active'): ?>
                <button class="btn btn-sm btn-success btn-accept-bank" data-id="<?php echo (int)$a['id']; ?>">Accept</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div id="bankModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
  <div style="background:#fff; max-width:600px; width:92%; margin:0 auto; padding:16px; border-radius:8px;">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h4 id="bankModalTitle">Bank</h4>
      <button id="bankModalClose" class="btn btn-sm btn-outline-secondary">Close</button>
    </div>
    <div id="bankModalBody" style="margin-top:12px; max-height:60vh; overflow:auto;"></div>
  </div>
</div>

<script>
const bankModal = document.getElementById('bankModal');
const bankModalBody = document.getElementById('bankModalBody');
const bankModalClose = document.getElementById('bankModalClose');
function openBankModal(){ bankModal.style.display = 'flex'; }
function closeBankModal(){ bankModal.style.display = 'none'; bankModalBody.innerHTML = ''; }
bankModalClose.addEventListener('click', closeBankModal);

// View bank (AJAX)
document.querySelectorAll('.btn-view-bank').forEach(btn => {
  // mark that this button will have its own handler so global delegation can skip it
  try{ btn.dataset.viewAttached = '1'; }catch(e){}
  btn.addEventListener('click', function(){
    const id = this.dataset.id;
    bankModalBody.innerHTML = '<div class="p-3">Loading…</div>'; openBankModal();
    fetch('get_bank.php?id=' + encodeURIComponent(id))
      .then(r=>r.json()).then(j=>{
        if(!j.ok){ bankModalBody.innerHTML = '<div class="alert alert-danger">'+(j.error||'Failed')+'</div>'; return; }
        const b = j.bank;
        let html = '<div><strong>Bank:</strong> '+escapeHtml(b.bank_name)+'</div>';
        html += '<div><strong>Account name:</strong> '+escapeHtml(b.account_name)+'</div>';
        html += '<div><strong>Account number:</strong> '+escapeHtml(b.account_number)+'</div>';
        html += '<div><strong>Status:</strong> '+escapeHtml(b.status)+'</div>';
        bankModalBody.innerHTML = html;
      }).catch(()=> bankModalBody.innerHTML = '<div class="alert alert-danger">Network error</div>');
  });
});

// Accept bank
document.querySelectorAll('.btn-accept-bank').forEach(btn => {
  btn.addEventListener('click', function(){
    if(!confirm('Accept this bank account?')) return;
    const id = this.dataset.id; this.disabled = true;
    fetch('accept_bank.php', { method:'POST', body: new URLSearchParams({ id: id }) })
      .then(r=>r.json()).then(j=>{
            if(j.ok){
          const row = document.querySelector('tr[data-id="'+id+'"]');
          if(row) {
            const badge = row.querySelector('td:nth-child(5) .badge');
            if(badge){ badge.className = 'badge bg-success'; badge.textContent = j.status || 'accepted'; }
            // update accept button to a disabled Accepted state instead of removing it
            const ab = row.querySelector('.btn-accept-bank'); if(ab){ ab.disabled = true; ab.textContent = 'Accepted'; ab.className = 'btn btn-sm btn-success'; }
          }
        } else { alert(j.error || 'Failed'); this.disabled = false; }
      }).catch(()=>{ alert('Network error'); this.disabled = false; });
  });
});

function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]; }); }
</script>
