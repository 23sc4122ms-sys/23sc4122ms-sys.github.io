<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$resp = ['ok'=>false, 'items'=>[], 'total'=>0];
try{
    if(!empty($_SESSION['user_id'])){
        $uid = (int)$_SESSION['user_id'];
        // join user_cart with menu_items
        $stmt = $pdo->prepare('SELECT uc.menu_item_id as id, uc.quantity, mi.name, mi.price, mi.image FROM user_cart uc LEFT JOIN menu_items mi ON mi.id = uc.menu_item_id WHERE uc.user_id = :uid');
        $stmt->execute([':uid'=>$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r){
            $resp['items'][] = [
                'id' => (int)$r['id'],
                'name' => $r['name'],
                'price' => (float)$r['price'],
                'quantity' => (int)$r['quantity'],
                'image' => $r['image'] ?? null,
                'subtotal' => round(((float)$r['price']) * ((int)$r['quantity']),2)
            ];
        }
    } else {
        $cart = $_SESSION['cart'] ?? [];
        if($cart && is_array($cart)){
            $ids = array_map('intval', array_keys($cart));
            $place = implode(',', $ids);
            if($place){
                $stmt = $pdo->query("SELECT * FROM menu_items WHERE id IN ($place)");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $byId = [];
                foreach($rows as $r) $byId[$r['id']] = $r;
                foreach($cart as $id => $qty){
                    if(isset($byId[$id])){
                        $item = $byId[$id];
                        $resp['items'][] = [
                            'id' => (int)$id,
                            'name' => $item['name'],
                            'price' => (float)$item['price'],
                            'quantity' => (int)$qty,
                            'image' => $item['image'] ?? null,
                            'subtotal' => round(((float)$item['price']) * ((int)$qty),2)
                        ];
                    }
                }
            }
        }
    }

    // compute total
    $total = 0.0;
    foreach($resp['items'] as $it) $total += $it['subtotal'];
    $resp['total'] = round($total,2);
    $resp['ok'] = true;
}catch(Exception $e){
    $resp['ok'] = false;
    $resp['error'] = $e->getMessage();
}

echo json_encode($resp);
exit;
?>
