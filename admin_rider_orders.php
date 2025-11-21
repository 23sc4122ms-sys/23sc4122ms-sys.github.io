<?php
// admin_rider_orders.php - returns HTML fragment listing orders for a given rider (admin only)
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: text/html; charset=utf-8');

$pdo = getPDO();
$uid = $_SESSION['user_id'] ?? null;
if(empty($uid) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    echo '<div class="p-3 text-danger">Unauthorized</div>'; exit;
}

$rid = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;
if($rid <= 0){ echo '<div class="p-3 text-muted">Invalid rider</div>'; exit; }

try{
    // fetch per-order latest rider_earnings amount for this rider
    $stmt = $pdo->prepare(
        'SELECT re.order_id, re.amount, o.created_at as order_date
         FROM rider_earnings re
         LEFT JOIN orders o ON o.id = re.order_id
         INNER JOIN (
           SELECT order_id, MAX(created_at) AS m FROM rider_earnings WHERE rider_id = :rid AND order_id IS NOT NULL GROUP BY order_id
         ) t ON re.order_id = t.order_id AND re.created_at = t.m
         WHERE re.rider_id = :rid
         ORDER BY COALESCE(o.created_at, re.created_at) DESC'
    );
    $stmt->execute([':rid'=>$rid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if(!$rows){
        echo '<div class="p-3 text-muted">No earned orders for this rider.</div>'; exit;
    }

    echo '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Order</th><th>Date</th><th>Earned</th><th>Action</th></tr></thead><tbody>';
    foreach($rows as $r){
        $oid = (int)($r['order_id'] ?? 0);
        $date = htmlspecialchars(isset($r['order_date']) ? date('Y-m-d', strtotime($r['order_date'])) : '');
        $amt = number_format((float)($r['amount'] ?? 0),2);
        echo '<tr><td>#' . $oid . '</td><td>' . $date . '</td><td>$' . $amt . '</td><td><button class="btn btn-sm btn-outline-secondary view-order" data-order="' . $oid . '">View Order</button></td></tr>';
    }
    echo '</tbody></table></div>';
    exit;
}catch(Exception $e){
    echo '<div class="p-3 text-danger">Failed to load orders</div>'; exit;
}

?>
