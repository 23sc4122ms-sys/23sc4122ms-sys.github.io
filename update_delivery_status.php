<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'not_authorized']);
    exit;
}

$data = $_POST + $_GET;
$deliveryId = isset($data['delivery_id']) ? (int)$data['delivery_id'] : 0;
$status = isset($data['status']) ? trim($data['status']) : '';
if(!$deliveryId || !$status){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_params']);
    exit;
}

$allowed = ['assigned','picked_up','delivered','failed','waiting','confirmed'];
if(!in_array($status,$allowed, true)){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_status']);
    exit;
}

try{
    // check ownership
    $sth = $pdo->prepare('SELECT * FROM deliveries WHERE id = :id');
    $sth->execute([':id'=>$deliveryId]);
    $delivery = $sth->fetch(PDO::FETCH_ASSOC);
    if(!$delivery || (int)$delivery['rider_id'] !== (int)$_SESSION['user_id']){
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'not_assigned']);
        exit;
    }

    $params = [':id'=>$deliveryId, ':status'=>$status];
    $sets = 'status = :status';
    if($status === 'delivered'){
        $sets .= ', delivered_at = NOW()';
    }
    $sql = "UPDATE deliveries SET $sets WHERE id = :id";
    $sth = $pdo->prepare($sql);
    $sth->execute($params);

    echo json_encode(['ok'=>true]);
}catch(Exception $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_error']);
}

?>
