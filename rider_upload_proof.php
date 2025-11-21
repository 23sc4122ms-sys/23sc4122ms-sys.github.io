<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

$deliveryId = isset($_POST['delivery_id']) ? (int)$_POST['delivery_id'] : 0;
if($deliveryId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid delivery id']); exit; }

// ensure file exists
if(empty($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK){ echo json_encode(['ok'=>false,'error'=>'No file uploaded']); exit; }

try{
  // ensure delivery assigned to rider
  $sth = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id LIMIT 1');
  $sth->execute([':id'=>$deliveryId]);
  $d = $sth->fetch(PDO::FETCH_ASSOC);
  if(!$d || (int)$d['rider_id'] !== (int)$_SESSION['user_id']){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Not assigned']); exit; }

  // ensure uploads dir
  $uploadsDir = __DIR__ . '/uploads/delivery_proofs';
  if(!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

  $f = $_FILES['proof'];
  $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
  $newName = 'proof_' . $deliveryId . '_' . time() . '.' . ($ext ?: 'jpg');
  $dest = $uploadsDir . '/' . $newName;
  if(!move_uploaded_file($f['tmp_name'], $dest)){
    echo json_encode(['ok'=>false,'error'=>'Failed to move uploaded file']); exit;
  }

  $proofPath = 'uploads/delivery_proofs/' . $newName;

  // ensure columns
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS proof_path VARCHAR(255) DEFAULT NULL"); }catch(Exception $e){}
  try{ $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS proof_uploaded_at DATETIME DEFAULT NULL"); }catch(Exception $e){}

  // update delivery: set status to waiting and store proof path
  $u = $pdo->prepare('UPDATE deliveries SET status = :s, proof_path = :p, proof_uploaded_at = NOW() WHERE id = :id');
  $u->execute([':s'=>'waiting', ':p'=>$proofPath, ':id'=>$deliveryId]);

  echo json_encode(['ok'=>true,'status'=>'waiting','proof'=>$proofPath]); exit;
}catch(Exception $e){
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit;
}

?>
