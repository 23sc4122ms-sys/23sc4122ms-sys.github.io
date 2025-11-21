<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$orderId = isset($_GET['order']) ? (int)$_GET['order'] : 0;
if($orderId <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid order id']); exit; }

try{
    // ensure the requester owns the order: either by user_id or by session
    if(!empty($_SESSION['user_id'])){
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND user_id = :uid LIMIT 1');
        $stmt->execute([':id'=>$orderId, ':uid'=>(int)$_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id AND session_id = :sid LIMIT 1');
        $stmt->execute([':id'=>$orderId, ':sid'=>session_id()]);
    }

    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$order){ echo json_encode(['ok'=>false,'error'=>'Order not found or access denied']); exit; }

        $itStmt = $pdo->prepare('SELECT oi.*, mi.image, mi.name as menu_name, mi.id as menu_item_id FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id = :oid ORDER BY oi.created_at ASC');
        $itStmt->execute([':oid'=>$orderId]);
        $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);

        // include delivery + rider info if present
        $delivery = null;
        try{
            $dstmt = $pdo->prepare('SELECT * FROM deliveries WHERE order_id = :oid LIMIT 1');
            $dstmt->execute([':oid'=>$orderId]);
            $delivery = $dstmt->fetch(PDO::FETCH_ASSOC);
        }catch(Exception $e){ /* ignore */ }

        // include customer address/name where possible
        $customer = ['name' => null, 'address' => null];
        if(!empty($order['user_id'])){
            try{
                $cstmt = $pdo->prepare('SELECT name, address FROM users WHERE id = :uid LIMIT 1');
                $cstmt->execute([':uid'=>(int)$order['user_id']]);
                $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
                if($crow){
                    $customer['name'] = $crow['name'] ?? null;
                    $customer['address'] = $crow['address'] ?? null;
                }
            }catch(Exception $e){ /* ignore */ }
        } else {
            // guest session-based orders may store address on the orders table
            if(!empty($order['delivery_address'])){
                $customer['address'] = $order['delivery_address'];
            }
        }

        // determine per-item whether current user can rate (item completed and user hasn't rated it)
        $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $itemRatings = [];
        if($userId){
            try{
                $placeholders = implode(',', array_fill(0, count($items), '?'));
                $ids = array_map(function($it){ return (int)$it['id']; }, $items);
                if(count($ids) > 0){
                    $irStmt = $pdo->prepare('SELECT menu_item_id, COUNT(*) as cnt FROM user_ratings WHERE user_id = ? AND menu_item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY menu_item_id');
                    $params = array_merge([$userId], $ids);
                    $irStmt->execute($params);
                    $rows = $irStmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach($rows as $r) $itemRatings[(int)$r['menu_item_id']] = (int)$r['cnt'];
                }
            }catch(Exception $e){ /* ignore */ }
        }

        // attach can_rate flag to items
        foreach($items as &$it){
            $it['can_rate'] = false;
            if(strtolower($it['status'] ?? '') === 'completed'){
                // only allow rating if user not admin/owner and hasn't rated this menu item in user_ratings
                $allowed = true;
                $role = strtolower($_SESSION['user_role'] ?? '');
                if(in_array($role, ['admin','owner'])) $allowed = false;
                if($userId && !empty($it['menu_item_id']) && isset($itemRatings[(int)$it['menu_item_id']])) $allowed = false;
                $it['can_rate'] = $allowed;
            }
        }

        // rider can be rated if order/delivery is completed and user hasn't rated rider yet
        $can_rate_rider = false; $rider_id = null; $your_rider_rating = null;
        if($delivery && !empty($delivery['rider_id'])){
            $rider_id = (int)$delivery['rider_id'];
            // check delivery/order status
            $orderStatus = strtolower($order['status'] ?? '');
            $deliveryStatus = strtolower($delivery['status'] ?? '');
            if($orderStatus === 'completed' || $deliveryStatus === 'completed'){
                // check if user already rated this rider
                if($userId){
                    try{
                        $yr = $pdo->prepare('SELECT rating FROM rider_ratings WHERE rider_id = :rid AND user_id = :uid LIMIT 1');
                        $yr->execute([':rid'=>$rider_id, ':uid'=>$userId]);
                        $val = $yr->fetchColumn();
                        if($val !== false && $val !== null) $your_rider_rating = (int)$val;
                        else $your_rider_rating = null;
                    }catch(Exception $e){ }
                }
                $role = strtolower($_SESSION['user_role'] ?? '');
                if(!in_array($role, ['admin','owner'])){
                    $can_rate_rider = ($userId && $your_rider_rating === null);
                }
            }
        }

        // normalize paid flag for client
        $order['paid'] = isset($order['paid']) ? (int)$order['paid'] : 0;
        echo json_encode(['ok'=>true,'order'=>$order,'items'=>$items,'delivery'=>$delivery,'can_rate_rider'=>$can_rate_rider,'rider_id'=>$rider_id,'your_rider_rating'=>$your_rider_rating]);
    exit;
}catch(Exception $e){
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}

?>
