<?php
// update_payout_status.php
// Admin endpoint to update payout status (e.g., pending -> processing -> completed)

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Auth: admin/owner only
$role = strtolower($_SESSION['user_role'] ?? '');
if(!in_array($role, ['admin', 'owner'])) {
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok' => false, 'error' => 'POST required']);
  exit;
}

$payoutId = isset($_POST['payout']) ? (int)$_POST['payout'] : 0;
$newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
$paymentRef = isset($_POST['reference']) ? trim($_POST['reference']) : '';

if($payoutId <= 0 || !in_array($newStatus, ['pending', 'processing', 'completed', 'failed', 'cancelled'])) {
  echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
  exit;
}

try {
  $pdo->beginTransaction();
  
  // Get current payout
  $getStmt = $pdo->prepare('SELECT * FROM payouts WHERE id = :id LIMIT 1');
  $getStmt->execute([':id' => $payoutId]);
  $current = $getStmt->fetch(PDO::FETCH_ASSOC);
  
  if(!$current) {
    echo json_encode(['ok' => false, 'error' => 'Payout not found']);
    exit;
  }
  
  $oldStatus = $current['payment_status'];
  
  // Update payout status
  $updateStmt = $pdo->prepare('UPDATE payouts SET payment_status = :status, processed_at = NOW()' . (!empty($paymentRef) ? ', payment_reference = :ref' : '') . ' WHERE id = :id');
  $params = [':status' => $newStatus, ':id' => $payoutId];
  if(!empty($paymentRef)) $params[':ref'] = $paymentRef;
  $updateStmt->execute($params);
  
  // Log the change
  $logStmt = $pdo->prepare(
    'INSERT INTO payout_logs (payout_id, action, old_value, new_value, performed_by, notes)
     VALUES (:pid, "status_updated", :old, :new, :uid, :notes)'
  );
  $logStmt->execute([
    ':pid' => $payoutId,
    ':old' => json_encode(['status' => $oldStatus]),
    ':new' => json_encode(['status' => $newStatus, 'reference' => $paymentRef]),
    ':uid' => $_SESSION['user_id'] ?? null,
    ':notes' => 'Status changed from ' . $oldStatus . ' to ' . $newStatus
  ]);
  
  $pdo->commit();
  
  echo json_encode([
    'ok' => true,
    'payout_id' => $payoutId,
    'old_status' => $oldStatus,
    'new_status' => $newStatus,
    'processed_at' => date('Y-m-d H:i:s')
  ]);
  exit;
  
} catch(Exception $e) {
  if($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}

?>
