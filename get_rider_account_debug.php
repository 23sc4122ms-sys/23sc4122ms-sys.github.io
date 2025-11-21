<?php
// get_rider_account_debug.php - temporary diagnostic endpoint
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? ''))!=='rider'){
  echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}
$rid=(int)$_SESSION['user_id'];
$out=['ok'=>true,'rider_id'=>$rid];
try{
  $acc=$pdo->prepare('SELECT * FROM rider_accounts WHERE rider_id=:r LIMIT 1');
  $acc->execute([':r'=>$rid]);
  $out['rider_accounts']=$acc->fetch(PDO::FETCH_ASSOC) ?: null;
  $sumE=$pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id=:r');
  $sumE->execute([':r'=>$rid]);
  $out['rider_earnings_sum']=(float)$sumE->fetchColumn();
  // schema-resilient pending lookup
  if(function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status')){
    $pend=$pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id=:r AND status IN ('pending','processing')");
  }else{
    $pend=$pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id=:r");
  }
  $pend->execute([':r'=>$rid]);
  $out['pending_payouts_sum']=(float)$pend->fetchColumn();
  $week=$pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id=:r AND YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)');
  $week->execute([':r'=>$rid]);
  $out['current_week_sum']=(float)$week->fetchColumn();
  // Derived expected available if ledger missing or unsynced
  $expected_avail=max(0,$out['rider_earnings_sum']-$out['pending_payouts_sum']);
  $out['expected_available_from_history']=round($expected_avail,2);
  if(isset($out['rider_accounts']['available_amount'])){
    $out['ledger_available_amount']=(float)$out['rider_accounts']['available_amount'];
  }
}catch(Exception $e){ $out['error']='Diagnostic failed'; }

echo json_encode($out, JSON_PRETTY_PRINT);