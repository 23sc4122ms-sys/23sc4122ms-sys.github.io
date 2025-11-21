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

$orderId = isset($_POST['order']) ? (int)$_POST['order'] : 0;
if($orderId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid order id']); exit; }

try{
  // ensure columns exist
  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid TINYINT(1) DEFAULT 0"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS paid_at DATETIME DEFAULT NULL"); }catch(Exception $e){}

  $u = $pdo->prepare('UPDATE orders SET paid = 1, paid_at = NOW() WHERE id = :id');
  $u->execute([':id'=>$orderId]);
  echo json_encode(['ok'=>true,'order'=>$orderId,'paid'=>1]); exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>