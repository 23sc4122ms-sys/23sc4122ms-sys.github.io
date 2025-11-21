<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if($id <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }

try{
  $u = $pdo->prepare('UPDATE bank_accounts SET status = :s WHERE id = :id');
  $u->execute([':s'=>'accepted', ':id'=>$id]);
  echo json_encode(['ok'=>true,'id'=>$id,'status'=>'accepted']); exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
