<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$orderId = isset($_POST['order']) ? (int)$_POST['order'] : 0;
if($orderId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid order']); exit; }

try{
  $pdo->beginTransaction();
  // remove order_items first
  $d1 = $pdo->prepare('DELETE FROM order_items WHERE order_id = :oid');
  $d1->execute([':oid'=>$orderId]);
  // then orders
  $d2 = $pdo->prepare('DELETE FROM orders WHERE id = :oid');
  $d2->execute([':oid'=>$orderId]);
  $pdo->commit();
  echo json_encode(['ok'=>true,'deleted'=>$d2->rowCount()]); exit;
}catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
