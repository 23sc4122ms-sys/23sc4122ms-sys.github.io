<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// must be a logged in rider
if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    echo json_encode([]); exit;
}
$riderId = (int)$_SESSION['user_id'];

try{
    $sql = "SELECT d.id, d.order_id, d.rider_id, d.status, d.assigned_at, d.created_at AS delivery_created, o.created_at AS order_created,
                   d.proof_path, d.proof_uploaded_at, COALESCE(u.name, CONCAT('Guest ', LEFT(o.session_id,8))) AS customer_name, u.address AS customer_address
            FROM deliveries d
            JOIN orders o ON o.id = d.order_id
            LEFT JOIN users u ON u.id = o.user_id
            WHERE d.rider_id = :rid
            ORDER BY d.assigned_at DESC, d.created_at DESC
            LIMIT 200";
    $sth = $pdo->prepare($sql);
    $sth->execute([':rid'=>$riderId]);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    // normalize address - use customer address if available, otherwise show placeholder
    foreach($rows as &$r){
        $r['dropoff_address'] = $r['customer_address'] ?: 'Address not provided';
    }
    echo json_encode($rows);
}catch(Exception $e){
    echo json_encode([]);
}

?>
