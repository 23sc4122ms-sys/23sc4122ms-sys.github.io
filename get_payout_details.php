<?php
// get_payout_details.php
// Returns HTML with detailed breakdown of a payout (earnings by source)

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$payoutId = isset($_GET['payout']) ? (int)$_GET['payout'] : 0;
if($payoutId <= 0) {
  echo '<div class="alert alert-danger">Invalid payout ID</div>';
  exit;
}

try {
  // Get payout
  $payoutStmt = $pdo->prepare('SELECT p.*, u.name, u.email FROM payouts p LEFT JOIN users u ON u.id = p.rider_id WHERE p.id = :id LIMIT 1');
  $payoutStmt->execute([':id' => $payoutId]);
  $payout = $payoutStmt->fetch(PDO::FETCH_ASSOC);
  
  if(!$payout) {
    echo '<div class="alert alert-danger">Payout not found</div>';
    exit;
  }
  
  // Get delivery items
  $deliveryStmt = $pdo->prepare(
    'SELECT id, order_id, base_pay, bonus, tip, amount, delivered_at FROM deliveries 
     WHERE payout_id = :pid ORDER BY delivered_at DESC LIMIT 100'
  );
  $deliveryStmt->execute([':pid' => $payoutId]);
  $deliveries = $deliveryStmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Get racing items
  $racingStmt = $pdo->prepare(
    'SELECT id, racing_event_id, position, earnings, created_at FROM racing_participations 
     WHERE payout_id = :pid ORDER BY created_at DESC LIMIT 50'
  );
  $racingStmt->execute([':pid' => $payoutId]);
  $racings = $racingStmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Get content items
  $contentStmt = $pdo->prepare(
    'SELECT id, title, platform, base_payment, engagement_bonus, total_earnings, created_at FROM rider_content 
     WHERE payout_id = :pid ORDER BY created_at DESC LIMIT 50'
  );
  $contentStmt->execute([':pid' => $payoutId]);
  $contents = $contentStmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Get endorsement transactions
  $endorsementStmt = $pdo->prepare(
    'SELECT et.id, et.transaction_type, et.amount, et.transaction_date, ed.brand_id, b.brand_name 
     FROM endorsement_transactions et
     INNER JOIN endorsement_deals ed ON et.endorsement_deal_id = ed.id
     LEFT JOIN brands b ON ed.brand_id = b.id
     WHERE et.payout_id = :pid ORDER BY et.transaction_date DESC LIMIT 50'
  );
  $endorsementStmt->execute([':pid' => $payoutId]);
  $endorsements = $endorsementStmt->fetchAll(PDO::FETCH_ASSOC);
  
  // Output HTML
  echo '<div style="background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:20px;">';
  echo '<h6>Payout Summary</h6>';
  echo '<div class="row">';
  echo '<div class="col-md-6"><strong>Rider:</strong> ' . htmlspecialchars($payout['name'] ?? 'Unknown') . ' (' . htmlspecialchars($payout['email'] ?? '') . ')</div>';
  echo '<div class="col-md-6"><strong>Period:</strong> ' . htmlspecialchars($payout['payout_period_start']) . ' to ' . htmlspecialchars($payout['payout_period_end']) . '</div>';
  echo '<div class="col-md-6"><strong>Created:</strong> ' . htmlspecialchars($payout['created_at']) . '</div>';
  echo '<div class="col-md-6"><strong>Status:</strong> <span class="badge bg-info">' . htmlspecialchars($payout['payment_status']) . '</span></div>';
  echo '</div>';
  echo '</div>';
  
  // Earnings breakdown
  echo '<div style="margin-top:20px;">';
  echo '<h6>Earnings Breakdown</h6>';
  echo '<table class="table table-sm table-bordered" style="font-size:13px;">';
  echo '<tr style="background:#f8f9fa;"><th>Source</th><th style="text-align:right;">Amount</th></tr>';
  echo '<tr><td><span class="badge badge-delivery">Deliveries</span></td><td style="text-align:right;"><strong>$' . number_format((float)$payout['delivery_earnings'], 2) . '</strong></td></tr>';
  echo '<tr><td><span class="badge badge-racing">Racing</span></td><td style="text-align:right;"><strong>$' . number_format((float)$payout['racing_earnings'], 2) . '</strong></td></tr>';
  echo '<tr><td><span class="badge badge-content">Content</span></td><td style="text-align:right;"><strong>$' . number_format((float)$payout['content_earnings'], 2) . '</strong></td></tr>';
  echo '<tr><td><span class="badge badge-endorsement">Endorsements</span></td><td style="text-align:right;"><strong>$' . number_format((float)$payout['endorsement_earnings'], 2) . '</strong></td></tr>';
  echo '<tr style="background:#e8f5e9;"><td><strong>Total Earnings</strong></td><td style="text-align:right;"><strong>$' . number_format((float)$payout['total_earnings'], 2) . '</strong></td></tr>';
  echo '<tr><td><strong>Deductions</strong></td><td style="text-align:right;">-$' . number_format((float)$payout['deductions'], 2) . '</td></tr>';
  echo '<tr style="background:#fff3cd;"><td><strong>Net Payout</strong></td><td style="text-align:right;"><strong style="color:#28a745;">$' . number_format((float)$payout['net_payout'], 2) . '</strong></td></tr>';
  echo '</table>';
  echo '</div>';
  
  // Delivery details
  if(!empty($deliveries)) {
    echo '<div style="margin-top:20px;">';
    echo '<h6>Deliveries (' . count($deliveries) . ')</h6>';
    echo '<div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:10px;">';
    foreach($deliveries as $d) {
      echo '<div style="padding:8px;border-bottom:1px solid #eee;font-size:12px;">';
      echo 'Order #' . (int)$d['order_id'] . ': <strong>$' . number_format((float)$d['amount'], 2) . '</strong> ';
      echo '(Base: $' . number_format((float)$d['base_pay'], 2) . ', Bonus: $' . number_format((float)$d['bonus'], 2) . ', Tip: $' . number_format((float)$d['tip'], 2) . ') ';
      echo '<small class="text-muted">' . htmlspecialchars($d['delivered_at']) . '</small>';
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
  }
  
  // Racing details
  if(!empty($racings)) {
    echo '<div style="margin-top:20px;">';
    echo '<h6>Racing Events (' . count($racings) . ')</h6>';
    echo '<div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:10px;">';
    foreach($racings as $race) {
      echo '<div style="padding:8px;border-bottom:1px solid #eee;font-size:12px;">';
      echo 'Event #' . (int)$race['racing_event_id'] . ' - Position #' . (int)$race['position'] . ': <strong>$' . number_format((float)$race['earnings'], 2) . '</strong> ';
      echo '<small class="text-muted">' . htmlspecialchars($race['created_at']) . '</small>';
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
  }
  
  // Content details
  if(!empty($contents)) {
    echo '<div style="margin-top:20px;">';
    echo '<h6>Content Creation (' . count($contents) . ')</h6>';
    echo '<div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:10px;">';
    foreach($contents as $c) {
      echo '<div style="padding:8px;border-bottom:1px solid #eee;font-size:12px;">';
      echo htmlspecialchars($c['title'] ?? 'Untitled') . ' (' . htmlspecialchars($c['platform'] ?? '') . '): ';
      echo '<strong>$' . number_format((float)$c['total_earnings'], 2) . '</strong> ';
      echo '(Base: $' . number_format((float)$c['base_payment'], 2) . ', Engagement: $' . number_format((float)$c['engagement_bonus'], 2) . ') ';
      echo '<small class="text-muted">' . htmlspecialchars($c['created_at']) . '</small>';
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
  }
  
  // Endorsement details
  if(!empty($endorsements)) {
    echo '<div style="margin-top:20px;">';
    echo '<h6>Brand Endorsements (' . count($endorsements) . ')</h6>';
    echo '<div style="max-height:200px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:10px;">';
    foreach($endorsements as $e) {
      echo '<div style="padding:8px;border-bottom:1px solid #eee;font-size:12px;">';
      echo htmlspecialchars($e['brand_name'] ?? 'Unknown') . ' - ' . htmlspecialchars($e['transaction_type']) . ': ';
      echo '<strong>$' . number_format((float)$e['amount'], 2) . '</strong> ';
      echo '<small class="text-muted">' . htmlspecialchars($e['transaction_date']) . '</small>';
      echo '</div>';
    }
    echo '</div>';
    echo '</div>';
  }
  
} catch(Exception $e) {
  echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

?>
