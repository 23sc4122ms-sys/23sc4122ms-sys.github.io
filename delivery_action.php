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
  if($action === 'accept'){
    // mark delivery accepted
    $u = $pdo->prepare('UPDATE deliveries SET status = :s WHERE order_id = :oid');
    $u->execute([':s'=>'accepted', ':oid'=>$orderId]);
    echo json_encode(['ok'=>true,'action'=>'accept','affected'=>$u->rowCount()]); exit;
  }

  if($action === 'reject'){
    // mark rejected and clear rider assignment
    $u = $pdo->prepare('UPDATE deliveries SET status = :s, rider_id = NULL WHERE order_id = :oid');
    $u->execute([':s'=>'rejected', ':oid'=>$orderId]);
    echo json_encode(['ok'=>true,'action'=>'reject','affected'=>$u->rowCount()]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
