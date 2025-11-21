<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$orderId = isset($_POST['order']) ? (int)$_POST['order'] : 0;
$riderId = isset($_POST['rider']) ? (int)$_POST['rider'] : 0;
if($orderId <= 0 || $riderId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid parameters']); exit; }

try{
  // ensure rider exists
  $s = $pdo->prepare('SELECT id FROM users WHERE id = :id AND role = "rider" LIMIT 1');
  $s->execute([':id'=>$riderId]);
  if(!$s->fetch()){ echo json_encode(['ok'=>false,'error'=>'Rider not found']); exit; }

  // insert or update deliveries row
  $ins = $pdo->prepare('INSERT INTO deliveries (order_id, rider_id, status, assigned_at) VALUES (:oid, :rid, :st, NOW()) ON DUPLICATE KEY UPDATE rider_id = VALUES(rider_id), status = VALUES(status), assigned_at = VALUES(assigned_at)');
  $ins->execute([':oid'=>$orderId, ':rid'=>$riderId, ':st'=>'assigned']);

  echo json_encode(['ok'=>true,'order'=>$orderId,'rider'=>$riderId]); exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
