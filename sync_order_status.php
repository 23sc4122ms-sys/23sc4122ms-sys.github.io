<?php
// sync_order_status.php
// Scans order_items and writes an aggregated `status` into `orders.status` to keep order-level state consistent.
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

try{
  // ensure status/timestamp columns exist on orders
  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN status VARCHAR(50) DEFAULT NULL, ADD COLUMN accepted_at DATETIME DEFAULT NULL, ADD COLUMN completed_at DATETIME DEFAULT NULL, ADD COLUMN cancelled_at DATETIME DEFAULT NULL"); }catch(Exception $e){ /* ignore if columns already exist or not allowed */ }

  $orders = $pdo->query('SELECT id FROM orders')->fetchAll(PDO::FETCH_COLUMN);
  $updated = [];

  $q = $pdo->prepare(
    'SELECT COUNT(*) AS items_count,
            SUM(CASE WHEN LOWER(status) IN ("processing","pending","approved") THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN LOWER(status) IN ("accepted") THEN 1 ELSE 0 END) AS accepted_count,
            SUM(CASE WHEN LOWER(status) IN ("completed","delivered") THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN LOWER(status) = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count
     FROM order_items WHERE order_id = :oid'
  );

  foreach($orders as $oid){
    $q->execute([':oid'=>$oid]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if(!$r) continue;
    $items = (int)$r['items_count'];
    $pending = (int)$r['pending_count'];
    $accepted = (int)$r['accepted_count'];
    $completed = (int)$r['completed_count'];
    $cancelled = (int)$r['cancelled_count'];

    // derive status using same rules as UI
    if($pending > 0) $computed = 'processing';
    elseif($cancelled > 0 && $completed === 0 && $accepted === 0) $computed = 'cancelled';
    elseif($completed > 0 && $completed >= $items) $computed = 'completed';
    elseif($accepted > 0 && $accepted + $completed >= $items) $computed = 'completed';
    elseif($accepted > 0) $computed = 'accepted';
    else $computed = 'processing';

    // read current order status
    $cur = $pdo->prepare('SELECT status FROM orders WHERE id = :oid LIMIT 1');
    $cur->execute([':oid'=>$oid]);
    $curStatus = strtolower(trim((string)$cur->fetchColumn()));

    if($curStatus !== $computed){
      // Prevent automatic transition to 'completed' if there's a delivery that is not yet admin-confirmed.
      // Only allow marking order as completed when a delivery record (if exists) is in 'confirmed' or 'completed' state.
      if($computed === 'completed'){
        try{
          $dchk = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE order_id = :oid AND LOWER(status) IN ("confirmed","completed")');
          $dchk->execute([':oid'=>$oid]);
          $hasConfirmed = (int)$dchk->fetchColumn() > 0;
        }catch(Exception $e){
          $hasConfirmed = false;
        }
        if(!$hasConfirmed){
          // skip changing to completed until admin confirms delivery
          continue;
        }
      }
      // update status and set timestamp for certain states
      $updates = ['status' => $computed, 'oid' => $oid];
      $sqlSet = 'UPDATE orders SET status = :status';
      if($computed === 'accepted'){
        $sqlSet .= ', accepted_at = NOW()';
      }elseif($computed === 'completed'){
        $sqlSet .= ', completed_at = NOW()';
      }elseif($computed === 'cancelled'){
        $sqlSet .= ', cancelled_at = NOW()';
      }
      $sqlSet .= ' WHERE id = :oid';
      try{
        $u = $pdo->prepare($sqlSet);
        $u->execute($updates);
        $updated[] = ['order'=>$oid,'from'=>$curStatus,'to'=>$computed,'rows'=>$u->rowCount()];
      }catch(Exception $e){
        $updated[] = ['order'=>$oid,'error'=>$e->getMessage()];
      }
    }
  }

  echo json_encode(['ok'=>true,'updated_count'=>count($updated),'details'=>$updated]);
  exit;
}catch(Exception $e){
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}

?>
