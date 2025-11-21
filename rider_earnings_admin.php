<?php
// rider_earnings_admin.php
// Admin dashboard for viewing rider earnings across all sources and managing payouts

session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Auth: must be admin or owner
$role = strtolower($_SESSION['user_role'] ?? '');
if(!in_array($role, ['admin', 'owner'])) {
  header('HTTP/1.1 403 Forbidden');
  echo 'Unauthorized';
  exit;
}

// Get filter params
$filter_rider = isset($_GET['rider']) ? (int)$_GET['rider'] : 0;
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_period = isset($_GET['period']) ? trim($_GET['period']) : 'all';

// Build payouts query
$payoutsSql = 'SELECT 
  p.id,
  p.rider_id,
  u.name,
  u.email,
  p.payout_period_start,
  p.payout_period_end,
  p.delivery_earnings,
  p.racing_earnings,
  p.content_earnings,
  p.endorsement_earnings,
  p.total_earnings,
  p.deductions,
  p.net_payout,
  p.payment_status,
  p.payment_method,
  p.created_at
FROM payouts p
LEFT JOIN users u ON u.id = p.rider_id
WHERE 1=1';

$params = [];
if($filter_rider > 0) {
  $payoutsSql .= ' AND p.rider_id = :rid';
  $params[':rid'] = $filter_rider;
}
if(!empty($filter_status)) {
  $payoutsSql .= ' AND p.payment_status = :status';
  $params[':status'] = $filter_status;
}
if($filter_period === 'current_month') {
  $payoutsSql .= ' AND MONTH(p.payout_period_start) = MONTH(NOW()) AND YEAR(p.payout_period_start) = YEAR(NOW())';
}
$payoutsSql .= ' ORDER BY p.created_at DESC LIMIT 100';

$payoutStmt = $pdo->prepare($payoutsSql);
$payoutStmt->execute($params);
$payouts = $payoutStmt->fetchAll(PDO::FETCH_ASSOC);

// Get all riders for dropdown
$ridersStmt = $pdo->query('SELECT id, name, email FROM users WHERE role = "rider" ORDER BY name');
$riders = $ridersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary stats
$summaryStmt = $pdo->query('
SELECT 
  COUNT(*) as total_payouts,
  SUM(CASE WHEN payment_status = "pending" THEN 1 ELSE 0 END) as pending_count,
  SUM(CASE WHEN payment_status = "completed" THEN 1 ELSE 0 END) as completed_count,
  SUM(CASE WHEN payment_status = "processing" THEN 1 ELSE 0 END) as processing_count,
  SUM(CASE WHEN payment_status = "pending" THEN net_payout ELSE 0 END) as pending_total,
  SUM(CASE WHEN payment_status = "completed" THEN net_payout ELSE 0 END) as completed_total
FROM payouts
');
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rider Earnings & Payouts</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <style>
    body { background-color: #f5f5f5; }
    .card { box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; }
    .stat-card.pending { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .stat-card.completed { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .badge-delivery { background-color: #0dcaf0; }
    .badge-racing { background-color: #fd7e14; }
    .badge-content { background-color: #198754; }
    .badge-endorsement { background-color: #6f42c1; }
  </style>
</head>
<body>
<div class="container-fluid py-4">
  <h1 class="mb-4">Rider Earnings & Payouts Management</h1>

  <!-- Summary Cards -->
  <div class="row mb-4">
    <div class="col-md-3">
      <div class="stat-card">
        <div class="small">Total Payouts</div>
        <div style="font-size:28px;font-weight:bold;"><?php echo (float)($summary['total_payouts'] ?? 0); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card pending">
        <div class="small">Pending Payouts</div>
        <div style="font-size:28px;font-weight:bold;"><?php echo (float)($summary['pending_count'] ?? 0); ?></div>
        <div class="small">$<?php echo number_format((float)($summary['pending_total'] ?? 0), 2); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card completed">
        <div class="small">Completed Payouts</div>
        <div style="font-size:28px;font-weight:bold;"><?php echo (int)($summary['completed_count'] ?? 0); ?></div>
        <div class="small">$<?php echo number_format((float)($summary['completed_total'] ?? 0), 2); ?></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="stat-card">
        <div class="small">Processing</div>
        <div style="font-size:28px;font-weight:bold;"><?php echo (int)($summary['processing_count'] ?? 0); ?></div>
      </div>
    </div>
  </div>

  <!-- Filters & Actions -->
  <div class="card mb-4 p-3">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Filter by Rider</label>
        <select class="form-select" id="riderFilter" onchange="applyFilters()">
          <option value="">All Riders</option>
          <?php foreach($riders as $r): ?>
            <option value="<?php echo (int)$r['id']; ?>" <?php echo $filter_rider === (int)$r['id'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($r['name'] . ' (' . $r['email'] . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Filter by Status</label>
        <select class="form-select" id="statusFilter" onchange="applyFilters()">
          <option value="">All Statuses</option>
          <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
          <option value="processing" <?php echo $filter_status === 'processing' ? 'selected' : ''; ?>>Processing</option>
          <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
          <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Period</label>
        <select class="form-select" id="periodFilter" onchange="applyFilters()">
          <option value="all" <?php echo $filter_period === 'all' ? 'selected' : ''; ?>>All Time</option>
          <option value="current_month" <?php echo $filter_period === 'current_month' ? 'selected' : ''; ?>>Current Month</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">&nbsp;</label>
        <button class="btn btn-primary w-100" onclick="generatePayouts()">Generate Payouts</button>
      </div>
    </div>
  </div>

  <!-- Payouts Table -->
  <div class="card">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead style="background:#f8f9fa;">
          <tr>
            <th>Payout ID</th>
            <th>Rider</th>
            <th>Period</th>
            <th>Delivery</th>
            <th>Racing</th>
            <th>Content</th>
            <th>Endorsement</th>
            <th>Total</th>
            <th>Net Payout</th>
            <th>Status</th>
            <th>Method</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($payouts)): ?>
            <tr><td colspan="12" class="text-center text-muted py-4">No payouts found</td></tr>
          <?php else: foreach($payouts as $p): ?>
            <tr>
              <td><strong>#<?php echo (int)$p['id']; ?></strong></td>
              <td>
                <div><?php echo htmlspecialchars($p['name'] ?? 'Unknown'); ?></div>
                <small class="text-muted"><?php echo htmlspecialchars($p['email'] ?? ''); ?></small>
              </td>
              <td>
                <small><?php echo htmlspecialchars($p['payout_period_start']); ?><br>to<br><?php echo htmlspecialchars($p['payout_period_end']); ?></small>
              </td>
              <td>
                <span class="badge badge-delivery">$<?php echo number_format((float)$p['delivery_earnings'], 2); ?></span>
              </td>
              <td>
                <span class="badge badge-racing">$<?php echo number_format((float)$p['racing_earnings'], 2); ?></span>
              </td>
              <td>
                <span class="badge badge-content">$<?php echo number_format((float)$p['content_earnings'], 2); ?></span>
              </td>
              <td>
                <span class="badge badge-endorsement">$<?php echo number_format((float)$p['endorsement_earnings'], 2); ?></span>
              </td>
              <td><strong>$<?php echo number_format((float)$p['total_earnings'], 2); ?></strong></td>
              <td><strong style="color:#28a745;">$<?php echo number_format((float)$p['net_payout'], 2); ?></strong></td>
              <td>
                <?php
                $statusColor = 'secondary';
                if($p['payment_status'] === 'pending') $statusColor = 'warning';
                elseif($p['payment_status'] === 'completed') $statusColor = 'success';
                elseif($p['payment_status'] === 'failed') $statusColor = 'danger';
                elseif($p['payment_status'] === 'processing') $statusColor = 'info';
                ?>
                <span class="badge bg-<?php echo $statusColor; ?>"><?php echo htmlspecialchars($p['payment_status']); ?></span>
              </td>
              <td><?php echo htmlspecialchars($p['payment_method'] ?? 'bank_transfer'); ?></td>
              <td style="text-align:right;">
                <?php if($p['payment_status'] === 'pending'): ?>
                  <button class="btn btn-sm btn-success" onclick="processPayment(<?php echo (int)$p['id']; ?>)">Process</button>
                <?php endif; ?>
                <button class="btn btn-sm btn-info" onclick="viewDetails(<?php echo (int)$p['id']; ?>)">Details</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal for payout details -->
<div id="detailsModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:white;max-width:600px;width:90%;padding:30px;border-radius:8px;">
    <h5 id="detailsTitle">Payout Details</h5>
    <div id="detailsBody" style="max-height:60vh;overflow:auto;margin:20px 0;"></div>
    <button class="btn btn-secondary" onclick="document.getElementById('detailsModal').style.display='none'">Close</button>
  </div>
</div>

<script>
function applyFilters() {
  const rider = document.getElementById('riderFilter').value;
  const status = document.getElementById('statusFilter').value;
  const period = document.getElementById('periodFilter').value;
  
  let url = window.location.pathname + '?';
  if(rider) url += 'rider=' + rider + '&';
  if(status) url += 'status=' + status + '&';
  if(period) url += 'period=' + period;
  
  window.location.href = url;
}

function generatePayouts() {
  if(!confirm('Generate automatic payouts for all eligible riders?\nThis will create pending payout records.')) return;
  
  fetch('calculate_rider_payouts.php')
    .then(r => r.json())
    .then(j => {
      if(j.ok) {
        alert('Payouts generated:\n' + j.payouts_created + ' payouts created\nPeriod: ' + j.period_start + ' to ' + j.period_end);
        location.reload();
      } else {
        alert('Error: ' + (j.error || 'Unknown error'));
      }
    })
    .catch(e => alert('Network error: ' + e));
}

function processPayment(payoutId) {
  if(!confirm('Mark this payout as processing?\nStatus will change to "processing"')) return;
  
  fetch('update_payout_status.php', {
    method: 'POST',
    body: new URLSearchParams({ payout: payoutId, status: 'processing' })
  })
    .then(r => r.json())
    .then(j => {
      if(j.ok) {
        alert('Payout status updated');
        location.reload();
      } else {
        alert('Error: ' + (j.error || 'Unknown error'));
      }
    })
    .catch(e => alert('Network error: ' + e));
}

function viewDetails(payoutId) {
  fetch('get_payout_details.php?payout=' + payoutId)
    .then(r => r.text())
    .then(html => {
      document.getElementById('detailsBody').innerHTML = html;
      document.getElementById('detailsModal').style.display = 'flex';
    })
    .catch(e => alert('Error loading details: ' + e));
}
</script>

</body>
</html>
