<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$role = strtolower($_SESSION['user_role'] ?? '');
if(!in_array($role, ['admin','owner'])){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$deliveryId = isset($_POST['delivery_id']) ? (int)$_POST['delivery_id'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : 'confirm';

if($deliveryId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid delivery id']); exit; }

try{
  $sth = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id LIMIT 1');
  $sth->execute([':id'=>$deliveryId]);
  $d = $sth->fetch(PDO::FETCH_ASSOC);
  if(!$d){ echo json_encode(['ok'=>false,'error'=>'Delivery not found']); exit; }
  if(strtolower($d['status']) !== 'waiting'){ echo json_encode(['ok'=>false,'error'=>'Delivery not waiting for confirmation']); exit; }

  // Ensure orders table has a paid column and paid_at timestamp (migration convenience)
  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid TINYINT(1) DEFAULT 0"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_at DATETIME DEFAULT NULL"); }catch(Exception $e){}

  // Check associated order payment status; if not paid we'll auto-pay the delivery below
  $orderPaid = false;
  if(!empty($d['order_id'])){
    try{
      $oSt = $pdo->prepare('SELECT paid FROM orders WHERE id = :oid LIMIT 1');
      $oSt->execute([':oid'=> (int)$d['order_id']]);
      $oRow = $oSt->fetch(PDO::FETCH_ASSOC);
      if(!$oRow){ echo json_encode(['ok'=>false,'error'=>'Associated order not found']); exit; }
      $orderPaid = !empty($oRow['paid']) && (int)$oRow['paid'] === 1;
    }catch(Exception $e){ /* ignore and treat as not paid */ $orderPaid = false; }
  }

  // ensure columns exist
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS confirmed_at DATETIME DEFAULT NULL"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS amount DECIMAL(10,2) DEFAULT 0.00"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS base_pay DECIMAL(10,2) DEFAULT 0.00"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS delivery_bonus DECIMAL(10,2) DEFAULT 0.00"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS delivery_minutes INT DEFAULT NULL"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS accepted_at DATETIME DEFAULT NULL"); }catch(Exception $e){}

  if($action === 'reject'){
    // Reset delivery back to pending state so rider can resubmit proof
    $u = $pdo->prepare('UPDATE deliveries SET status = :s, proof_path = NULL, proof_uploaded_at = NULL WHERE id = :id');
    $u->execute([':s'=>'pending', ':id'=>$deliveryId]);
    echo json_encode(['ok'=>true,'status'=>'rejected']); exit;
  }

  // Default: confirm action
  // Mark status as 'confirmed' and calculate delivery time bonus
  $bonus = 0;
  $deliveryMinutes = null;
  
  // Get order's accepted_at timestamp to calculate delivery time
  if(!empty($d['order_id'])){
    try{
      $bSt = $pdo->prepare('SELECT accepted_at FROM orders WHERE id = :oid LIMIT 1');
      $bSt->execute([':oid'=> (int)$d['order_id']]);
      $bRow = $bSt->fetch(PDO::FETCH_ASSOC);
      if($bRow && !empty($bRow['accepted_at'])){
        $acceptedAt = new DateTime($bRow['accepted_at']);
        $now = new DateTime();
        // use timestamps to compute exact seconds difference (more reliable than DateInterval format)
        $deliverySeconds = $now->getTimestamp() - $acceptedAt->getTimestamp();
        if($deliverySeconds < 0) $deliverySeconds = abs($deliverySeconds);
        $deliveryMinutes = (int) floor($deliverySeconds / 60);

        // Bonus tiers (fast delivery incentive):
        // < 20 min: +$5 bonus
        // 20-40 min: +$3 bonus
        // 40-60 min: +$1 bonus
        // > 60 min: no bonus
        if($deliveryMinutes < 20){
          $bonus = 5.00;
        }elseif($deliveryMinutes < 40){
          $bonus = 3.00;
        }elseif($deliveryMinutes < 60){
          $bonus = 1.00;
        }
      }
    }catch(Exception $e){ /* ignore bonus calculation errors */ }
  }
  
  $u = $pdo->prepare('UPDATE deliveries SET status = :s, confirmed_at = NOW(), delivery_bonus = :bonus, delivery_minutes = :mins, delivered_at = NOW() WHERE id = :id');
  $u->execute([':s'=>'confirmed', ':bonus'=>$bonus, ':mins'=>$deliveryMinutes, ':id'=>$deliveryId]);

  // compute small speed-based earning (fast delivery reward): 1.00 if <=20 minutes, 0.50 otherwise
  $speed_amount = null;
  try{
    if(isset($deliverySeconds) && $deliverySeconds !== null){
      $speed_amount = ($deliverySeconds <= 20 * 60) ? 1.00 : 0.50;
    }else{
      // if accepted_at missing, assume fast (1.00)
      $speed_amount = 1.00;
    }
  }catch(Exception $e){ $speed_amount = null; }

  // Also mark associated order items as completed so orders list reflects confirmation
  try{
    if(!empty($d['order_id'])){
      $oid = (int)$d['order_id'];
      $up = $pdo->prepare('UPDATE order_items SET status = :s WHERE order_id = :oid AND LOWER(status) IN ("processing","pending","accepted","approved","delivered")');
      $up->execute([':s'=>'completed', ':oid'=>$oid]);
      $affected = $up->rowCount();
      
      // Update order status to 'completed' as well
      try{
        $os = $pdo->prepare('UPDATE orders SET status = :s, completed_at = NOW() WHERE id = :oid');
        $os->execute([':s'=>'completed', ':oid'=>$oid]);
      }catch(Exception $e){ /* ignore */ }
      
      // optional: recompute order total (exclude cancelled items)
      try{
        $t = $pdo->prepare('SELECT COALESCE(SUM(mi.price * oi.quantity),0) FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = :oid AND oi.status != "cancelled"');
        $t->execute([':oid'=>$oid]);
        $total = (float)$t->fetchColumn();
        $w = $pdo->prepare('UPDATE orders SET total = :total WHERE id = :oid');
        $w->execute([':total'=>$total, ':oid'=>$oid]);
      }catch(Exception $e){ /* ignore total update errors */ }
    }
  }catch(Exception $e){ /* ignore per-item update errors */ }

  // Insert a completed_orders snapshot for auditing (non-fatal)
  try{
    if(!empty($d['order_id'])){
      $oid = (int)$d['order_id'];
      $ord = $pdo->prepare('SELECT * FROM orders WHERE id = :oid LIMIT 1'); $ord->execute([':oid'=>$oid]); $orderRow = $ord->fetch(PDO::FETCH_ASSOC);
      $it = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :oid'); $it->execute([':oid'=>$oid]); $itemsRows = $it->fetchAll(PDO::FETCH_ASSOC);
      $del = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = :oid LIMIT 1'); $del->execute([':oid'=>$oid]); $delRow = $del->fetch(PDO::FETCH_ASSOC);
      $snapshot = json_encode(['order'=>$orderRow,'items'=>$itemsRows,'delivery'=>$delRow]);
      $riderId = isset($d['rider_id']) ? (int)$d['rider_id'] : null;
      $completedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

      // compute rider_fee to persist with snapshot (use amount >0 else base_pay or settings + bonus)
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
      }catch(Exception $ee){ /* ignore compute errors */ }

      $ins = $pdo->prepare('INSERT INTO completed_orders (order_id, rider_id, completed_by, rider_fee, snapshot) VALUES (:oid,:rid,:by,:fee,:snap)');
      $ins->execute([':oid'=>$oid, ':rid'=>$riderId, ':by'=>$completedBy, ':fee'=>round($rider_fee,2), ':snap'=>$snapshot]);

      // also persist to rider_earnings for quick reporting (non-fatal)
      try{
        if($riderId){
          $deliveryId = isset($delRow['id']) ? (int)$delRow['id'] : $deliveryId ?? null;
          // use helper to avoid duplicate entries
          if(function_exists('record_rider_earning')){
            record_rider_earning($pdo, $riderId, $oid, $deliveryId, round($rider_fee,2), 'confirmed_snapshot');
          }else{
            $re = $pdo->prepare('INSERT INTO rider_earnings (rider_id, order_id, delivery_id, amount, source) VALUES (:rid,:oid,:did,:amt,:src)');
            $re->execute([':rid'=>$riderId, ':oid'=>$oid, ':did'=>$deliveryId, ':amt'=>round($rider_fee,2), ':src'=>'confirmed_snapshot']);
          }
          // also add the small speed-based earning if computed
          if(isset($speed_amount) && $speed_amount !== null && $speed_amount > 0){
            if(function_exists('record_rider_earning')){
              record_rider_earning($pdo, $riderId, $oid, $deliveryId, round($speed_amount,2), 'speed_payout');
            }else{
              $re2 = $pdo->prepare('INSERT INTO rider_earnings (rider_id, order_id, delivery_id, amount, source) VALUES (:rid,:oid,:did,:amt,:src)');
              $re2->execute([':rid'=>$riderId, ':oid'=>$oid, ':did'=>$deliveryId, ':amt'=>round($speed_amount,2), ':src'=>'speed_payout']);
            }
          }
        }
      }catch(Exception $e){ /* ignore earning persistence errors */ }
    }
  }catch(Exception $e){ /* ignore snapshot failures */ }

  // Create a payout for this delivery (auto-pay on confirmation) if a rider is assigned
  try{
    if(!empty($d['rider_id'])){
      // compute delivery amount
      $deliveryAmount = 0.00;
      // refresh delivery row to get amount/base_pay/bonus
      $drow = $pdo->prepare('SELECT amount, base_pay, delivery_bonus FROM deliveries WHERE id = :id LIMIT 1');
      $drow->execute([':id'=>$deliveryId]);
      $dr = $drow->fetch(PDO::FETCH_ASSOC);
      $baseRate = 0.00;
      if(isset($dr['amount']) && (float)$dr['amount'] > 0){
        $deliveryAmount = (float)$dr['amount'];
      } else {
        if(isset($dr['base_pay']) && (float)$dr['base_pay'] > 0){
          $baseRate = (float)$dr['base_pay'];
        } else {
          // fallback to setting
          $sr = $pdo->prepare('SELECT setting_value FROM payout_settings WHERE setting_key = :k LIMIT 1');
          $sr->execute([':k'=>'delivery_base_rate']);
          $baseRate = (float)$sr->fetchColumn();
          if($baseRate <= 0) $baseRate = 3.00; // safe default
        }
        $deliveryAmount = $baseRate + ((isset($dr['delivery_bonus']) ? (float)$dr['delivery_bonus'] : 0.00));
      }

      // Insert payout record for this single delivery (mark as completed/paid)
      $pstmt = $pdo->prepare('INSERT INTO payouts (rider_id, payout_period_start, payout_period_end, delivery_earnings, racing_earnings, content_earnings, endorsement_earnings, total_earnings, deductions, net_payout, payment_status, processed_at) VALUES (:rid, :start, :end, :del, 0, 0, 0, :total, 0, :net, :status, NOW())');
      $today = date('Y-m-d');
      $pstmt->execute([
        ':rid' => (int)$d['rider_id'],
        ':start' => $today,
        ':end' => $today,
        ':del' => $deliveryAmount,
        ':total' => $deliveryAmount,
        ':net' => $deliveryAmount,
        ':status' => 'completed'
      ]);
      $payoutId = $pdo->lastInsertId();

      // ensure deliveries table has paid columns
      try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS paid TINYINT(1) DEFAULT 0"); }catch(Exception $e){}
      try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS paid_at DATETIME DEFAULT NULL"); }catch(Exception $e){}
      try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS payout_id INT DEFAULT NULL"); }catch(Exception $e){}

      // update deliveries row with computed amount/base pay, mark delivery as paid and link to payout
      try{
        $pdo->prepare('UPDATE deliveries SET amount = :amt, base_pay = :base, paid = 1, paid_at = NOW(), payout_id = :pid WHERE id = :id')
          ->execute([':amt'=>$deliveryAmount, ':base'=>$baseRate, ':pid'=>$payoutId, ':id'=>$deliveryId]);
      }catch(Exception $e){
        // fallback: at least mark as paid
        $pdo->prepare('UPDATE deliveries SET paid = 1, paid_at = NOW(), payout_id = :pid WHERE id = :id')->execute([':pid'=>$payoutId, ':id'=>$deliveryId]);
      }

      // mark order as paid if associated and not already
      if(!empty($d['order_id']) && !$orderPaid){
        try{
          $pdo->prepare('UPDATE orders SET paid = 1, paid_at = NOW() WHERE id = :oid')->execute([':oid'=> (int)$d['order_id']]);
        }catch(Exception $e){ /* ignore */ }
      }

      // add payout log
      $log = $pdo->prepare('INSERT INTO payout_logs (payout_id, action, new_value, performed_by, notes) VALUES (:pid, "created", :val, :by, :notes)');
      $log->execute([':pid'=>$payoutId, ':val'=>json_encode(['delivery_id'=>$deliveryId,'amount'=>$deliveryAmount]), ':by'=>$_SESSION['user_id'] ?? null, ':notes'=>'Auto payout created on delivery confirmation']);
    }
  }catch(Exception $e){ /* non-fatal: ignore auto-payout failures */ }

  echo json_encode(['ok'=>true,'status'=>'confirmed']); exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>

