<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$orderId = isset($_POST['order']) ? (int)$_POST['order'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
if($orderId <= 0 || !$action){ echo json_encode(['ok'=>false,'error'=>'Invalid parameters']); exit; }

try{
  if($action === 'complete'){
    // mark all non-cancelled items as completed
    $u = $pdo->prepare('UPDATE order_items SET status = :s WHERE order_id = :oid AND LOWER(status) IN ("processing","pending","approved")');
    $u->execute([':s'=>'completed', ':oid'=>$orderId]);
    $affected = $u->rowCount();
    
    // also mark the order record as completed
    try{ $pdo->exec("ALTER TABLE orders ADD COLUMN status VARCHAR(50) DEFAULT NULL, ADD COLUMN completed_at DATETIME DEFAULT NULL"); }catch(Exception $e){ /* ignore if exists */ }
    try{
      $up = $pdo->prepare('UPDATE orders SET status = :s, completed_at = NOW() WHERE id = :oid');
      $up->execute([':s'=>'completed', ':oid'=>$orderId]);
    }catch(Exception $e){ /* non-fatal */ }
    
    // store snapshot of completed order for auditing (non-fatal)
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

      // persist to rider_earnings as well
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
      }catch(Exception $e){ }
    }catch(Exception $e){ /* ignore */ }

    echo json_encode(['ok'=>true,'action'=>'complete','affected'=>$affected]); exit;
  }

  if($action === 'accept'){
    // mark all non-cancelled items as accepted (not completed)
    $u = $pdo->prepare('UPDATE order_items SET status = :s WHERE order_id = :oid AND LOWER(status) IN ("processing","pending","approved")');
    $u->execute([':s'=>'accepted', ':oid'=>$orderId]);
    $affected = $u->rowCount();
    // also mark the parent order record as accepted (create column if needed)
    try{ $pdo->exec("ALTER TABLE orders ADD COLUMN status VARCHAR(50) DEFAULT NULL, ADD COLUMN accepted_at DATETIME DEFAULT NULL"); }catch(Exception $e){ /* ignore if exists or cannot alter */ }
    try{
      $up = $pdo->prepare('UPDATE orders SET status = :s, accepted_at = NOW() WHERE id = :oid');
      $up->execute([':s'=>'accepted', ':oid'=>$orderId]);
    }catch(Exception $e){ /* non-fatal */ }

    echo json_encode(['ok'=>true,'action'=>'accept','affected'=>$affected]); exit;
  }

  if($action === 'cancel'){
    // cancel cancellable items and adjust buy_count
    $itStmt = $pdo->prepare('SELECT oi.id, oi.menu_item_id, oi.quantity FROM order_items oi WHERE oi.order_id = :oid AND LOWER(oi.status) IN ("processing","pending","approved")');
    $itStmt->execute([':oid'=>$orderId]);
    $rows = $itStmt->fetchAll(PDO::FETCH_ASSOC);

    if(!$rows || count($rows) === 0){ echo json_encode(['ok'=>false,'error'=>'No cancellable items']); exit; }

    $decrements = [];
    foreach($rows as $r){ $mid = (int)$r['menu_item_id']; $q = (int)$r['quantity']; if($mid>0){ if(!isset($decrements[$mid])) $decrements[$mid]=0; $decrements[$mid] += $q; }}

    $pdo->beginTransaction();
    $u = $pdo->prepare('UPDATE order_items SET status = :s WHERE order_id = :oid AND LOWER(status) IN ("processing","pending","approved")');
    $u->execute([':s'=>'cancelled', ':oid'=>$orderId]);
    $affected = $u->rowCount();

    if(!empty($decrements)){
      $d = $pdo->prepare('UPDATE menu_items SET buy_count = GREATEST(0, buy_count - :q) WHERE id = :mid');
      foreach($decrements as $mid => $q){ try{ $d->execute([':q'=>$q, ':mid'=>$mid]); }catch(Exception $e){ /* ignore per-item errors */ } }
    }

    // recompute order total
    $t = $pdo->prepare('SELECT COALESCE(SUM(mi.price * oi.quantity),0) FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = :oid AND oi.status != "cancelled"');
    $t->execute([':oid'=>$orderId]);
    $total = (float)$t->fetchColumn();
    try{ $up = $pdo->prepare('UPDATE orders SET total = :total WHERE id = :oid'); $up->execute([':total'=>$total, ':oid'=>$orderId]); }catch(Exception $e){ }
    
    // also update order status to 'cancelled'
    try{ 
      $us = $pdo->prepare('UPDATE orders SET status = :s, cancelled_at = NOW() WHERE id = :oid');
      $us->execute([':s'=>'cancelled', ':oid'=>$orderId]);
    }catch(Exception $e){ /* non-fatal */ }

    $pdo->commit();
    echo json_encode(['ok'=>true,'action'=>'cancel','affected'=>$affected,'total'=>round($total,2)]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;

}catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
