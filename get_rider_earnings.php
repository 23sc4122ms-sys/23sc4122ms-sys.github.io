<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
  exit;
}
$rid = (int)$_SESSION['user_id'];

try{
  // total earnings (sum of rider_earnings.amount)
  $sth = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS total FROM rider_earnings WHERE rider_id = :rid');
  $sth->execute([':rid'=>$rid]);
  $total = (float)$sth->fetchColumn();

  // completed count from completed_orders
  $sth = $pdo->prepare('SELECT COUNT(*) FROM completed_orders WHERE rider_id = :rid');
  $sth->execute([':rid'=>$rid]);
  $completed = (int)$sth->fetchColumn();

  // per-order latest earning: prefer latest rider_earnings per order_id (robust SQL)
  $perOrder = [];
  $stmtLatest = $pdo->prepare(
    'SELECT re.order_id, re.amount FROM rider_earnings re
     INNER JOIN (
       SELECT order_id, MAX(created_at) AS m FROM rider_earnings WHERE rider_id = :rid AND order_id IS NOT NULL GROUP BY order_id
     ) t ON re.order_id = t.order_id AND re.created_at = t.m
     WHERE re.rider_id = :rid'
  );
  $stmtLatest->execute([':rid'=>$rid]);
  $rows = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as $r){
    $oid = (int)$r['order_id'];
    $perOrder[$oid] = (float)$r['amount'];
  }

  // Fallback: if completed_orders has rider_fee for orders missing above, use that
  $stmtCO = $pdo->prepare('SELECT order_id, rider_fee FROM completed_orders WHERE rider_id = :rid AND order_id IS NOT NULL');
  $stmtCO->execute([':rid'=>$rid]);
  $crow = $stmtCO->fetchAll(PDO::FETCH_ASSOC);
  foreach($crow as $cr){
    $oid = (int)$cr['order_id'];
    if($oid && (!isset($perOrder[$oid]) || (float)$perOrder[$oid] == 0.0)){
      $perOrder[$oid] = (float)$cr['rider_fee'];
    }
  }

  // build optional source map: mark per-order values that came from rider_earnings vs completed_orders
  $source = [];
  // mark those present in rider_earnings
  $sthMark = $pdo->prepare('SELECT DISTINCT order_id FROM rider_earnings WHERE rider_id = :rid AND order_id IS NOT NULL');
  $sthMark->execute([':rid'=>$rid]);
  $rowsMark = $sthMark->fetchAll(PDO::FETCH_COLUMN);
  foreach($rowsMark as $o){ $source[(int)$o] = 'rider_earnings'; }
  // for orders where we used completed_orders fallback, mark if not already
  $stmtCO2 = $pdo->prepare('SELECT order_id FROM completed_orders WHERE rider_id = :rid AND order_id IS NOT NULL');
  $stmtCO2->execute([':rid'=>$rid]);
  $coRows = $stmtCO2->fetchAll(PDO::FETCH_COLUMN);
  foreach($coRows as $o){ if(!isset($source[(int)$o])) $source[(int)$o] = 'completed_orders'; }

  echo json_encode(['ok'=>true,'total'=> $total, 'completed'=>$completed, 'per_order'=>$perOrder, 'source'=>$source]);
  exit;
}catch(Exception $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  exit;
}
