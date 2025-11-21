<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$orderId = isset($_GET['order']) ? (int)$_GET['order'] : 0;
if($orderId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid order id']); exit; }

// Public access: no authentication required for viewing order details.
// Note: this endpoint returns order and item rows for the given order id.

try{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id'=>$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$order){ echo json_encode(['ok'=>false,'error'=>'Order not found']); exit; }

    $itStmt = $pdo->prepare('SELECT oi.*, mi.image FROM order_items oi LEFT JOIN menu_items mi ON oi.menu_item_id = mi.id WHERE oi.order_id = :oid ORDER BY oi.created_at ASC');
    $itStmt->execute([':oid'=>$orderId]);
    $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);

    // fetch delivery info (if any)
    $dStmt = $pdo->prepare('SELECT id, rider_id, status, proof_path, proof_uploaded_at, confirmed_at, amount, delivery_bonus FROM deliveries WHERE order_id = :oid LIMIT 1');
    $dStmt->execute([':oid'=>$orderId]);
    $delivery = $dStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'order'=>$order,'items'=>$items,'delivery'=>$delivery]);
    exit;
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

?>
