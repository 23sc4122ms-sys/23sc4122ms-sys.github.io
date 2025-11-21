<?php
// admin_get_order.php - admin-safe order detail fragment (HTML)
require_once __DIR__ . '/db.php';
session_start();
header('Content-Type: text/html; charset=utf-8');

$pdo = getPDO();
$uid = $_SESSION['user_id'] ?? null;
if(empty($uid) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    echo '<div class="p-3 text-danger">Unauthorized</div>'; exit;
}

$orderId = isset($_GET['order']) ? (int)$_GET['order'] : 0;
if($orderId <= 0){ echo '<div class="p-3 text-muted">Invalid order id</div>'; exit; }

try{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id'=>$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$order){ echo '<div class="p-3 text-muted">Order not found</div>'; exit; }

    // items
    $it = $pdo->prepare('SELECT oi.*, mi.name as menu_name FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = :oid');
    $it->execute([':oid'=>$orderId]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="p-3 admin-order-detail">';
    echo '<div class="d-flex justify-content-between align-items-center"><h5 class="m-0">Order #' . (int)$orderId . ' <small class="text-muted">' . htmlspecialchars($order['created_at'] ?? '') . '</small></h5>';
    echo '<button class="btn btn-sm btn-outline-secondary close-order" data-order="' . (int)$orderId . '">Close Order</button></div>';
    echo '<div class="mt-2"><strong>Total:</strong> $' . number_format((float)($order['total'] ?? 0),2) . '</div>';
    echo '<div class="mt-2"><strong>Items</strong></div>';
    echo '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Item</th><th>Qty</th><th>Price</th></tr></thead><tbody>';
    foreach($items as $it){
        echo '<tr><td>' . htmlspecialchars($it['menu_name'] ?? $it['name'] ?? '') . '</td><td>' . (int)($it['quantity'] ?? 0) . '</td><td>$' . number_format((float)($it['unit_price'] ?? $it['price'] ?? 0),2) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '</div>';
    exit;
}catch(Exception $e){ echo '<div class="p-3 text-danger">Failed to load order</div>'; exit; }

?>
