<?php
// calculate_rider_payouts.php
// Aggregates all rider earnings and creates automatic payout records
// Run via cron or manually triggered from admin panel

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$pdo = getPDO();
$executionLog = [];

try {
  // Step 1: Get payout settings
  $settingsStmt = $pdo->query('SELECT setting_key, setting_value FROM payout_settings');
  $settingsRows = $settingsStmt->fetchAll(PDO::FETCH_ASSOC);
  $settings = [];
  foreach($settingsRows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
  }
  
  $executionLog[] = ['step'=>'settings_loaded', 'settings_count' => count($settings)];
  
  // Step 2: Get all active riders
  $ridersStmt = $pdo->query('SELECT id, email, name FROM users WHERE role = "rider" AND status = "active"');
  $riders = $ridersStmt->fetchAll(PDO::FETCH_ASSOC);
  $executionLog[] = ['step'=>'riders_loaded', 'rider_count' => count($riders)];
  
  // Step 3: For each rider, calculate earnings from all sources
  $payoutsCreated = 0;
  $periodStart = date('Y-m-d', strtotime('first day of last month'));
  $periodEnd = date('Y-m-d', strtotime('last day of last month'));
  
  $pdo->beginTransaction();
  
  foreach($riders as $rider) {
    $riderId = (int)$rider['id'];
    
    // Get base delivery rate from settings
    $baseDeliveryRate = (float)($settings['delivery_base_rate'] ?? 5.00);
    
    // Calculate delivery earnings (sum of unpaid, confirmed/completed deliveries from period)
    // If amount is 0, use base rate; otherwise use the set amount
    // Add delivery_bonus (fast delivery incentive)
    // Status can be 'confirmed' (admin confirmed proof) or 'completed' (order fully done)
    $deliveryStmt = $pdo->prepare(
      'SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE :baseRate END), 0) as base_total,
              COALESCE(SUM(delivery_bonus), 0) as bonus_total
       FROM deliveries 
       WHERE rider_id = :rid AND paid = 0 
       AND LOWER(status) IN ("confirmed", "completed")
       AND DATE(confirmed_at) >= :start AND DATE(confirmed_at) <= :end'
    );
    $deliveryStmt->execute([':rid'=>$riderId, ':baseRate'=>$baseDeliveryRate, ':start'=>$periodStart, ':end'=>$periodEnd]);
    $deliveryRow = $deliveryStmt->fetch(PDO::FETCH_ASSOC);
    $deliveryEarnings = (float)$deliveryRow['base_total'] + (float)$deliveryRow['bonus_total'];
    
    // Calculate racing earnings (sum of unpaid race participations from period)
    $racingStmt = $pdo->prepare(
      'SELECT COALESCE(SUM(earnings), 0) as total 
       FROM racing_participations 
       WHERE rider_id = :rid AND paid = 0 
       AND DATE(created_at) >= :start AND DATE(created_at) <= :end'
    );
    $racingStmt->execute([':rid'=>$riderId, ':start'=>$periodStart, ':end'=>$periodEnd]);
    $racingEarnings = (float)$racingStmt->fetchColumn();
    
    // Calculate content earnings (sum of approved, unpaid content from period)
    $contentStmt = $pdo->prepare(
      'SELECT COALESCE(SUM(total_earnings), 0) as total 
       FROM rider_content 
       WHERE rider_id = :rid AND paid = 0 AND status = "approved"
       AND DATE(created_at) >= :start AND DATE(created_at) <= :end'
    );
    $contentStmt->execute([':rid'=>$riderId, ':start'=>$periodStart, ':end'=>$periodEnd]);
    $contentEarnings = (float)$contentStmt->fetchColumn();
    
    // Calculate endorsement earnings (sum of unpaid transactions from period)
    $endorsementStmt = $pdo->prepare(
      'SELECT COALESCE(SUM(et.amount), 0) as total 
       FROM endorsement_transactions et
       INNER JOIN endorsement_deals ed ON et.endorsement_deal_id = ed.id
       WHERE ed.rider_id = :rid AND et.paid = 0
       AND DATE(et.created_at) >= :start AND DATE(et.created_at) <= :end'
    );
    $endorsementStmt->execute([':rid'=>$riderId, ':start'=>$periodStart, ':end'=>$periodEnd]);
    $endorsementEarnings = (float)$endorsementStmt->fetchColumn();
    
    // Calculate totals
    $totalEarnings = $deliveryEarnings + $racingEarnings + $contentEarnings + $endorsementEarnings;
    $deductions = 0; // Can be populated with penalties, fees, etc.
    $netPayout = $totalEarnings - $deductions;
    
    // Check if payout meets minimum threshold
    $threshold = (float)($settings['payout_threshold'] ?? 50);
    if($netPayout < $threshold) {
      $executionLog[] = [
        'rider_id' => $riderId,
        'rider_email' => $rider['email'],
        'total_earnings' => $totalEarnings,
        'status' => 'skipped_below_threshold',
        'threshold' => $threshold
      ];
      continue;
    }
    
    // Create payout record
    $payoutStmt = $pdo->prepare(
      'INSERT INTO payouts 
       (rider_id, payout_period_start, payout_period_end, delivery_earnings, racing_earnings, content_earnings, endorsement_earnings, total_earnings, deductions, net_payout, payment_status)
       VALUES (:rid, :start, :end, :del, :rac, :con, :end_earn, :total, :ded, :net, "pending")'
    );
    
    $payoutStmt->execute([
      ':rid' => $riderId,
      ':start' => $periodStart,
      ':end' => $periodEnd,
      ':del' => $deliveryEarnings,
      ':rac' => $racingEarnings,
      ':con' => $contentEarnings,
      ':end_earn' => $endorsementEarnings,
      ':total' => $totalEarnings,
      ':ded' => $deductions,
      ':net' => $netPayout
    ]);
    
    $payoutId = $pdo->lastInsertId();
    $payoutsCreated++;
    
    // Mark individual items as assigned to this payout
    $pdo->prepare('UPDATE deliveries SET paid = 1, paid_at = NOW(), payout_id = :pid WHERE rider_id = :rid AND paid = 0 AND LOWER(status) IN ("confirmed", "completed") AND DATE(confirmed_at) >= :start AND DATE(confirmed_at) <= :end')
      ->execute([':pid'=>$payoutId, ':rid'=>$riderId, ':start'=>$periodStart, ':end'=>$periodEnd]);
    
    $pdo->prepare('UPDATE racing_participations SET paid = 1, paid_at = NOW(), payout_id = :pid WHERE rider_id = :rid AND paid = 0 AND DATE(created_at) >= :start AND DATE(created_at) <= :end')
      ->execute([':pid'=>$payoutId, ':rid'=>$riderId, ':start'=>$periodStart, ':end'=>$periodEnd]);
    
    $pdo->prepare('UPDATE rider_content SET paid = 1, paid_at = NOW(), payout_id = :pid WHERE rider_id = :rid AND paid = 0 AND status = "approved" AND DATE(created_at) >= :start AND DATE(created_at) <= :end')
      ->execute([':pid'=>$payoutId, ':rid'=>$riderId, ':start'=>$periodStart, ':end'=>$periodEnd]);
    
    // Update endorsement transactions through prepared statement with subquery
    $updateEndorsementSql = 'UPDATE endorsement_transactions SET paid = 1, paid_at = NOW(), payout_id = :pid 
                             WHERE endorsement_deal_id IN (SELECT id FROM endorsement_deals WHERE rider_id = :rid) 
                             AND paid = 0 AND DATE(created_at) >= :start AND DATE(created_at) <= :end';
    $pdo->prepare($updateEndorsementSql)
      ->execute([':pid'=>$payoutId, ':rid'=>$riderId, ':start'=>$periodStart, ':end'=>$periodEnd]);
    
    // Log the payout creation
    $logStmt = $pdo->prepare(
      'INSERT INTO payout_logs (payout_id, action, new_value, performed_by, notes)
       VALUES (:pid, "created", :val, NULL, :notes)'
    );
    $logStmt->execute([
      ':pid' => $payoutId,
      ':val' => json_encode([
        'rider_id' => $riderId,
        'delivery' => $deliveryEarnings,
        'racing' => $racingEarnings,
        'content' => $contentEarnings,
        'endorsement' => $endorsementEarnings,
        'total' => $totalEarnings,
        'net' => $netPayout
      ]),
      ':notes' => 'Auto-generated payout for period ' . $periodStart . ' to ' . $periodEnd
    ]);
    
    $executionLog[] = [
      'rider_id' => $riderId,
      'rider_email' => $rider['email'],
      'payout_id' => $payoutId,
      'delivery_earnings' => $deliveryEarnings,
      'racing_earnings' => $racingEarnings,
      'content_earnings' => $contentEarnings,
      'endorsement_earnings' => $endorsementEarnings,
      'total_earnings' => $totalEarnings,
      'net_payout' => $netPayout,
      'status' => 'created'
    ];
  }
  
  $pdo->commit();
  
  echo json_encode([
    'ok' => true,
    'period_start' => $periodStart,
    'period_end' => $periodEnd,
    'payouts_created' => $payoutsCreated,
    'total_riders_processed' => count($riders),
    'execution_log' => $executionLog
  ]);
  exit;

} catch(Exception $e) {
  if($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
    'execution_log' => $executionLog
  ]);
  exit;
}

?>
