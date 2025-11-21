<?php
// backfill_week_earn.php
// One-time (or repeatable) ledger reconciliation for rider_accounts.
// Recalculates: total_earned, total_earnings, pending_amount, available_amount, week_earn.
// Usage: visit in browser while logged in as an admin OR run via CLI: php backfill_week_earn.php
// Safety: READ-ONLY on source tables (rider_earnings, payouts), UPDATE/INSERT on rider_accounts.

session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();
header('Content-Type: text/plain; charset=utf-8');

// Optional simple auth gate (adjust as needed)
if(empty($_SESSION['user_id']) || !in_array(strtolower($_SESSION['user_role'] ?? ''), ['admin','owner'])){
  echo "Access denied. Admin/Owner only.\n"; exit;
}

try {
  $pdo->beginTransaction();

  // 1. Build temp aggregates for total earnings per rider
  $totalRows = $pdo->query("SELECT rider_id, IFNULL(SUM(amount),0) AS total_sum FROM rider_earnings GROUP BY rider_id")->fetchAll(PDO::FETCH_ASSOC);
  // 2. Weekly earnings (current ISO week)
  $weekRows = $pdo->query("SELECT rider_id, IFNULL(SUM(amount),0) AS week_sum FROM rider_earnings WHERE YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1) GROUP BY rider_id")->fetchAll(PDO::FETCH_ASSOC);
  // 3. Pending payouts (pending or processing) - be resilient to older schemas
  if(function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status')){
    $pendingRows = $pdo->query("SELECT rider_id, IFNULL(SUM(amount),0) AS pending_sum FROM payouts WHERE status IN ('pending','processing') GROUP BY rider_id")->fetchAll(PDO::FETCH_ASSOC);
  }else{
    $pendingRows = $pdo->query("SELECT rider_id, IFNULL(SUM(amount),0) AS pending_sum FROM payouts WHERE paid_at IS NULL GROUP BY rider_id")->fetchAll(PDO::FETCH_ASSOC);
  }

  $weekMap = []; foreach($weekRows as $r){ $weekMap[(int)$r['rider_id']] = (float)$r['week_sum']; }
  $pendingMap = []; foreach($pendingRows as $r){ $pendingMap[(int)$r['rider_id']] = (float)$r['pending_sum']; }

  $ins = $pdo->prepare("INSERT INTO rider_accounts (rider_id, total_earned, pending_amount, available_amount, total_earnings, week_earn, last_updated)
    VALUES (:rid,:total,:pending,:available,:total,:week,NOW())
    ON DUPLICATE KEY UPDATE
      total_earned = VALUES(total_earned),
      pending_amount = VALUES(pending_amount),
      available_amount = VALUES(available_amount),
      total_earnings = VALUES(total_earnings),
      week_earn = VALUES(week_earn),
      last_updated = NOW()");

  $count = 0;
  foreach($totalRows as $row){
    $rid = (int)$row['rider_id'];
    $total = (float)$row['total_sum'];
    $week = $weekMap[$rid] ?? 0.0;
    $pending = $pendingMap[$rid] ?? 0.0;
    $available = max(0, $total - $pending);
    $ins->execute([
      ':rid'=>$rid,
      ':total'=>$total,
      ':pending'=>$pending,
      ':available'=>$available,
      ':week'=>$week
    ]);
    $count++;
  }

  $pdo->commit();
  echo "Backfill complete. Updated/Inserted ledger rows: {$count}\n";
  echo "NOTE: week_earn reflects current ISO week only.\n";
  echo "You can re-run this script any time to reconcile.\n";
  echo "If riders had historical payouts marked 'paid', those do NOT reduce available balance (only pending/processing).\n";
} catch(Exception $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  echo "Backfill failed: " . $e->getMessage() . "\n";
  exit;
}
