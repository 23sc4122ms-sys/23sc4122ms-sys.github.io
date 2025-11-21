<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$orderId = isset($_POST['order']) ? (int)$_POST['order'] : 0;
if($orderId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid order id']); exit; }

// must be admin or owner
$role = strtolower($_SESSION['user_role'] ?? '');
if(!in_array($role, ['admin','owner'])){ echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

try{
  // ensure delivery exists and is delivered
  $s = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = :oid LIMIT 1');
  $s->execute([':oid'=>$orderId]);
  $d = $s->fetch(PDO::FETCH_ASSOC);
  if(!$d){ echo json_encode(['ok'=>false,'error'=>'Delivery not found']); exit; }
  if(strtolower($d['status']) !== 'delivered'){ echo json_encode(['ok'=>false,'error'=>'Delivery not yet delivered by rider']); exit; }

  // mark deliveries.status = completed and set completed_at if possible
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN completed_at DATETIME DEFAULT NULL"); }catch(Exception $e){}
  $u1 = $pdo->prepare('UPDATE deliveries SET status = :s, completed_at = NOW() WHERE order_id = :oid');
  $u1->execute([':s'=>'completed', ':oid'=>$orderId]);

  // mark order_items as completed for this order
  $u2 = $pdo->prepare('UPDATE order_items SET status = :s WHERE order_id = :oid');
  $u2->execute([':s'=>'completed', ':oid'=>$orderId]);

  // also mark the order record as completed (create column if needed)
  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN status VARCHAR(50) DEFAULT NULL, ADD COLUMN completed_at DATETIME DEFAULT NULL"); }catch(Exception $e){}
  try{
    $u3 = $pdo->prepare('UPDATE orders SET status = :s, completed_at = NOW() WHERE id = :oid');
    $u3->execute([':s'=>'completed', ':oid'=>$orderId]);
  }catch(Exception $e){ /* non-fatal */ }

  // record completed order snapshot
  try{
    $oid = (int)$orderId;
    $ord = $pdo->prepare('SELECT * FROM orders WHERE id = :oid LIMIT 1'); $ord->execute([':oid'=>$oid]); $orderRow = $ord->fetch(PDO::FETCH_ASSOC);
    $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :oid'); $it->execute([':oid'=>$oid]); $itemsRows = $it->fetchAll(PDO::FETCH_ASSOC);
    $del = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = :oid LIMIT 1'); $del->execute([':oid'=>$oid]); $delRow = $del->fetch(PDO::FETCH_ASSOC);
    $snapshot = json_encode(['order'=>$orderRow,'items'=>$itemsRows,'delivery'=>$delRow]);
    $riderId = isset($delRow['rider_id']) ? (int)$delRow['rider_id'] : null;
    $completedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    // compute rider_fee for snapshot
    $rider_fee = 0.00;
    try{
      if($delRow){
        $amount = isset($delRow['amount']) ? (float)$delRow['amount'] : 0.0;
        $bonus = isset($delRow['delivery_bonus']) ? (float)$delRow['delivery_bonus'] : 0.0;
        if($amount > 0){
          $rider_fee = $amount + $bonus;
        }else{
          $baseRate = 0.0;
          if(isset($delRow['base_pay']) && (float)$delRow['base_pay'] > 0){
            $baseRate = (float)$delRow['base_pay'];
          }else{
            $sr = $pdo->prepare('SELECT setting_value FROM payout_settings WHERE setting_key = :k LIMIT 1');
            $sr->execute([':k'=>'delivery_base_rate']);
            $baseRate = (float)$sr->fetchColumn();
            if($baseRate <= 0) $baseRate = 3.00;
          }
          $rider_fee = $baseRate + $bonus;
        }
      }
    }catch(Exception $ee){ }

    $ins = $pdo->prepare('INSERT INTO completed_orders (order_id, rider_id, completed_by, rider_fee, snapshot) VALUES (:oid,:rid,:by,:fee,:snap)');
    $ins->execute([':oid'=>$oid, ':rid'=>$riderId, ':by'=>$completedBy, ':fee'=>round($rider_fee,2), ':snap'=>$snapshot]);

    // persist into rider_earnings for reporting
    try{
      if($riderId){
        $deliveryId = isset($delRow['id']) ? (int)$delRow['id'] : null;
        if(function_exists('record_rider_earning')){
          record_rider_earning($pdo, $riderId, $oid, $deliveryId, round($rider_fee,2), 'admin_complete_snapshot');
        }else{
          $re = $pdo->prepare('INSERT INTO rider_earnings (rider_id, order_id, delivery_id, amount, source) VALUES (:rid,:oid,:did,:amt,:src)');
          $re->execute([':rid'=>$riderId, ':oid'=>$oid, ':did'=>$deliveryId, ':amt'=>round($rider_fee,2), ':src'=>'admin_complete_snapshot']);
        }
      }
    }catch(Exception $e){ /* ignore */ }
  }catch(Exception $e){ /* ignore snapshot errors */ }

  echo json_encode(['ok'=>true,'order'=>$orderId,'updated_items'=>$u2->rowCount()]); exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
