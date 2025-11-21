<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$orderId = isset($_POST['order']) ? (int)$_POST['order'] : 0;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
if($orderId <= 0 || !$action){ echo json_encode(['ok'=>false,'error'=>'Invalid parameters']); exit; }

// must be logged in as rider
if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
  echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}
$riderId = (int)$_SESSION['user_id'];

try{
  // ensure delivery exists and assigned to this rider
  $s = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = :oid LIMIT 1');
  $s->execute([':oid'=>$orderId]);
  $d = $s->fetch(PDO::FETCH_ASSOC);
  if(!$d){ echo json_encode(['ok'=>false,'error'=>'Delivery not found']); exit; }
  if((int)$d['rider_id'] !== $riderId){ echo json_encode(['ok'=>false,'error'=>'Not assigned to you']); exit; }

  if($action === 'delivered'){
    // mark delivery as delivered
    try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN delivered_at DATETIME DEFAULT NULL"); }catch(Exception $e){}
    $u = $pdo->prepare('UPDATE deliveries SET status = :s, delivered_at = NOW() WHERE order_id = :oid');
    $u->execute([':s'=>'delivered', ':oid'=>$orderId]);
    echo json_encode(['ok'=>true,'status'=>'delivered']); exit;
  }

  if($action === 'failed'){
    $u = $pdo->prepare('UPDATE deliveries SET status = :s WHERE order_id = :oid');
    $u->execute([':s'=>'failed', ':oid'=>$orderId]);
    echo json_encode(['ok'=>true,'status'=>'failed']); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
