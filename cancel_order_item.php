<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$itemId = isset($_POST['item']) ? (int)$_POST['item'] : 0;
if($itemId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid item']); exit; }

try{
  // find the order_item and ensure ownership
  $stmt = $pdo->prepare('SELECT oi.*, o.user_id, o.session_id FROM order_items oi LEFT JOIN orders o ON o.id = oi.order_id WHERE oi.id = :iid LIMIT 1');
  $stmt->execute([':iid'=>$itemId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if(!$row){ echo json_encode(['ok'=>false,'error'=>'Item not found']); exit; }

  $owns = false;
  if(!empty($_SESSION['user_id'])){
    if((int)$row['user_id'] === (int)$_SESSION['user_id']) $owns = true;
  } else {
    if((string)$row['session_id'] === session_id()) $owns = true;
  }
  if(!$owns){ echo json_encode(['ok'=>false,'error'=>'Access denied']); exit; }

  // only allow cancelling when current status is processing (or similar)
  $curStatus = strtolower((string)$row['status']);
  if(!in_array($curStatus, ['processing','pending','approved']) ){
    // don't allow cancelling delivered or already cancelled
    echo json_encode(['ok'=>false,'error'=>'This item cannot be cancelled']); exit;
  }

  // perform cancellation in a transaction
  $pdo->beginTransaction();
  // update order_items
  $u = $pdo->prepare('UPDATE order_items SET status = :s WHERE id = :iid AND status = :cur');
  $u->execute([':s'=>'cancelled', ':iid'=>$itemId, ':cur'=> $row['status']]);
  if($u->rowCount() === 0){
    // maybe already changed
    $pdo->rollBack();
    echo json_encode(['ok'=>false,'error'=>'Unable to cancel item']); exit;
  }

  // adjust menu_items.buy_count (decrement by quantity) if applicable
  if(!empty($row['menu_item_id']) && !empty($row['quantity'])){
    try{
      $d = $pdo->prepare('UPDATE menu_items SET buy_count = GREATEST(0, buy_count - :q) WHERE id = :mid');
      $d->execute([':q'=>(int)$row['quantity'], ':mid'=>(int)$row['menu_item_id']]);
    }catch(Exception $e){ /* ignore */ }
  }

  // recompute order total excluding cancelled items
  $t = $pdo->prepare('SELECT COALESCE(SUM(mi.price * oi.quantity),0) FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = :oid AND oi.status != "cancelled"');
  $t->execute([':oid'=>$row['order_id']]);
  $total = (float)$t->fetchColumn();

  $pdo->commit();

  echo json_encode(['ok'=>true,'itemId'=>$itemId,'status'=>'cancelled','total'=>round($total,2),'orderId'=>$row['order_id']]); exit;
}catch(Exception $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}

?>
