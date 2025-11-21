<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$resp = ['ok'=>false, 'orders'=>[]];
try{
    // choose by user_id when logged in, otherwise by session_id
    if(!empty($_SESSION['user_id'])){
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE user_id = :uid ORDER BY created_at DESC LIMIT 20');
        $stmt->execute([':uid'=>(int)$_SESSION['user_id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE session_id = :sid ORDER BY created_at DESC LIMIT 20');
        $stmt->execute([':sid'=>session_id()]);
    }
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if($orders){
        // collect order ids
        $ids = array_map(function($o){return (int)$o['id'];}, $orders);
        $place = implode(',', $ids);
        $items = [];
        if(!empty($place)){
            // include product image and menu_item_id by joining with menu_items
            $itStmt = $pdo->query("SELECT oi.*, mi.image, mi.id AS menu_item_id, mi.name AS menu_name FROM order_items oi LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id WHERE oi.order_id IN ($place) ORDER BY oi.created_at ASC");
            $rows = $itStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $r) $items[$r['order_id']][] = $r;
        }

        // determine which menu items the current user already rated (to avoid showing rate button)
        $userRated = [];
        if(!empty($_SESSION['user_id'])){
            try{
                $uid = (int)$_SESSION['user_id'];
                $midList = [];
                foreach($items as $group){ foreach($group as $it){ if(!empty($it['menu_item_id'])) $midList[] = (int)$it['menu_item_id']; } }
                $midList = array_values(array_unique($midList));
                if(count($midList) > 0){
                    $placeholders = implode(',', array_fill(0, count($midList), '?'));
                    $params = array_merge([$uid], $midList);
                    $qr = $pdo->prepare('SELECT menu_item_id FROM user_ratings WHERE user_id = ? AND menu_item_id IN (' . $placeholders . ') GROUP BY menu_item_id');
                    $qr->execute($params);
                    $rr = $qr->fetchAll(PDO::FETCH_ASSOC);
                    foreach($rr as $row) $userRated[(int)$row['menu_item_id']] = true;
                }
            }catch(Exception $e){ /* ignore */ }
        }

        // prefetch rider ratings the user already made (to avoid showing rider rate button)
        $userRiderRated = [];
        if(!empty($_SESSION['user_id'])){
            try{
                $uid = (int)$_SESSION['user_id'];
                $qr = $pdo->prepare('SELECT rider_id FROM rider_ratings WHERE user_id = :uid');
                $qr->execute([':uid'=>$uid]);
                $rr = $qr->fetchAll(PDO::FETCH_ASSOC);
                foreach($rr as $row) $userRiderRated[(int)$row['rider_id']] = true;
            }catch(Exception $e){ /* ignore */ }
        }

        foreach($orders as $o){
            $oId = (int)$o['id'];
            // attach can_rate per item: item is completed and user hasn't rated it and user is not admin/owner
            $rowItems = $items[$oId] ?? [];
            foreach($rowItems as &$it){
                $it['can_rate'] = false;
                if(strtolower($it['status'] ?? '') === 'completed'){
                    $allowed = true;
                    $role = strtolower($_SESSION['user_role'] ?? '');
                    if(in_array($role, ['admin','owner'])) $allowed = false;
                    if(!empty($_SESSION['user_id']) && !empty($it['menu_item_id']) && isset($userRated[(int)$it['menu_item_id']])) $allowed = false;
                    $it['can_rate'] = $allowed;
                }
            }
            // fetch delivery / rider for this order (if any)
            $riderId = null; $canRateRider = false;
            try{
                $ds = $pdo->prepare('SELECT rider_id FROM deliveries WHERE order_id = :oid LIMIT 1');
                $ds->execute([':oid'=>$oId]);
                $drow = $ds->fetch(PDO::FETCH_ASSOC);
                if($drow && !empty($drow['rider_id'])){
                    $riderId = (int)$drow['rider_id'];
                    // determine if user can rate rider: order must be completed, user not admin/owner, and user hasn't rated this rider yet
                    $role = strtolower($_SESSION['user_role'] ?? '');
                    $orderCompleted = (isset($o['status']) && strtolower($o['status']) === 'completed') || (isset($o['completed_at']) && $o['completed_at']);
                    if($orderCompleted && !in_array($role, ['admin','owner'])){
                        if(empty($_SESSION['user_id']) || !isset($userRiderRated[$riderId])){
                            $canRateRider = true;
                        }
                    }
                }
            }catch(Exception $e){ /* ignore */ }

            $resp['orders'][] = [
                'id' => $oId,
                'ref' => isset($o['ref']) ? $o['ref'] : null,
                'total' => (float)$o['total'],
                'created_at' => $o['created_at'],
                'items' => $rowItems,
                'rider_id' => $riderId,
                'can_rate_rider' => $canRateRider
            ];
        }
    }
    $resp['ok'] = true;
}catch(Exception $e){
    $resp['ok'] = false;
    $resp['error'] = $e->getMessage();
}

echo json_encode($resp);
exit;
?>
