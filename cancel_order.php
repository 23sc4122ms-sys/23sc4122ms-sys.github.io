<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$orderId = isset($_POST['order']) ? (int)$_POST['order'] : 0;
if($orderId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid order']); exit; }

try{
  // load order and check ownership
  $s = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
  $s->execute([':id'=>$orderId]);
  $order = $s->fetch(PDO::FETCH_ASSOC);
  if(!$order){ echo json_encode(['ok'=>false,'error'=>'Order not found']); exit; }

  $owns = false;
  if(!empty($_SESSION['user_id'])){
    if(isset($order['user_id']) && (int)$order['user_id'] === (int)$_SESSION['user_id']) $owns = true;
  } else {
    if(isset($order['session_id']) && (string)$order['session_id'] === session_id()) $owns = true;
  }
  if(!$owns){ echo json_encode(['ok'=>false,'error'=>'Access denied']); exit; }

  // find cancellable items (before changing them) so we know quantities to adjust
  $itStmt = $pdo->prepare('SELECT oi.id, oi.menu_item_id, oi.quantity FROM order_items oi WHERE oi.order_id = :oid AND LOWER(oi.status) IN ("processing","pending","approved")');
  $itStmt->execute([':oid'=>$orderId]);
  $rows = $itStmt->fetchAll(PDO::FETCH_ASSOC);
  if(!$rows || count($rows) === 0){ echo json_encode(['ok'=>false,'error'=>'No cancellable items found']); exit; }

  // aggregate quantities per menu_item_id for buy_count adjustment
  $decrements = [];
  foreach($rows as $r){
    $mid = (int)$r['menu_item_id']; $q = (int)$r['quantity'];
    if($mid > 0){ if(!isset($decrements[$mid])) $decrements[$mid] = 0; $decrements[$mid] += $q; }
  }

  $pdo->beginTransaction();
  // mark all cancellable items as cancelled
  $u = $pdo->prepare('UPDATE order_items SET status = :s WHERE order_id = :oid AND LOWER(status) IN ("processing","pending","approved")');
  $u->execute([':s'=>'cancelled', ':oid'=>$orderId]);
  $affected = $u->rowCount();

  // adjust menu_items.buy_count using the aggregated quantities
  if(!empty($decrements)){
    $d = $pdo->prepare('UPDATE menu_items SET buy_count = GREATEST(0, buy_count - :q) WHERE id = :mid');
    foreach($decrements as $mid => $q){
      try{ $d->execute([':q'=>$q, ':mid'=>$mid]); }catch(Exception $e){ /* ignore per-item errors */ }
    }
  }

  // recompute order total (exclude cancelled items)
  $t = $pdo->prepare('SELECT COALESCE(SUM(mi.price * oi.quantity),0) FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = :oid AND oi.status != "cancelled"');
  $t->execute([':oid'=>$orderId]);
  $total = (float)$t->fetchColumn();

  // optionally update orders.total column
  try{ $up = $pdo->prepare('UPDATE orders SET total = :total WHERE id = :oid'); $up->execute([':total'=>$total, ':oid'=>$orderId]); }catch(Exception $e){ }

  $pdo->commit();

  echo json_encode(['ok'=>true,'orderId'=>$orderId,'cancelled'=>$affected,'total'=>round($total,2)]);
  exit;
}catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
