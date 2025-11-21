<?php
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Support optional selected ids via POST (only checkout selected items)
$cart = [];
$selectedIds = [];
if($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ids'])){
    if(is_array($_POST['ids'])){
        $selectedIds = array_values(array_map('intval', $_POST['ids']));
    } else {
        $selectedIds = array_map('intval', explode(',', (string)$_POST['ids']));
    }
    $selectedIds = array_values(array_filter($selectedIds,function($v){return $v>0;}));
    if(!empty($selectedIds)){
        // build cart only for selected ids
        if(!empty($_SESSION['user_id'])){
            try{
                $uid = (int)$_SESSION['user_id'];
                $place = implode(',', array_fill(0,count($selectedIds),'?'));
                $stmt = $pdo->prepare("SELECT menu_item_id, quantity FROM user_cart WHERE user_id = ? AND menu_item_id IN ($place)");
                $params = array_merge([$uid], $selectedIds);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach($rows as $r) $cart[(int)$r['menu_item_id']] = (int)$r['quantity'];
            }catch(Exception $e){ /* fall back below */ }
        } else {
            // guest: use session cart but only selected ids
            $sess = $_SESSION['cart'] ?? [];
            foreach($selectedIds as $id){ if(isset($sess[$id]) && $sess[$id] > 0) $cart[$id] = (int)$sess[$id]; }
        }
    }
}

// if no selected ids or building from selected failed, fallback to full cart behavior
if(empty($cart)){
    $cart = $_SESSION['cart'] ?? [];
    // If user is logged in and session cart is empty, try to load from user_cart table
    if((empty($cart) || !is_array($cart)) && !empty($_SESSION['user_id'])){
        try{
            $uid = (int)$_SESSION['user_id'];
            $cstmt = $pdo->prepare('SELECT menu_item_id, quantity FROM user_cart WHERE user_id = :uid');
            $cstmt->execute([':uid'=>$uid]);
            $rows = $cstmt->fetchAll(PDO::FETCH_ASSOC);
            $cart = [];
            foreach($rows as $r) $cart[(int)$r['menu_item_id']] = (int)$r['quantity'];
        }catch(Exception $e){
            // ignore and fall back to session cart
            $cart = $_SESSION['cart'] ?? [];
        }
    }
}

if(empty($cart)){
    // If AJAX, return JSON; otherwise show a message
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
    if($isAjax){ echo json_encode(['ok'=>false,'error'=>'No items selected for checkout']); exit; }
    die('Cart is empty.');
}
// create orders and items tables if not exist (lightweight migration)
$pdo->exec("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref VARCHAR(64) DEFAULT NULL,
    session_id VARCHAR(128) NOT NULL,
    user_id INT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'processing',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)");

// Ensure `user_id` column exists on orders table (handle existing installations)
try{
    $col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'user_id'")->fetch();
    if(!$col){
        // add the column and index
        try{ $pdo->exec("ALTER TABLE orders ADD COLUMN user_id INT NULL AFTER session_id"); }catch(Exception $e){}
        try{ $pdo->exec("ALTER TABLE orders ADD INDEX(user_id)"); }catch(Exception $e){}
    }
    // ensure ref column exists
    $col2 = $pdo->query("SHOW COLUMNS FROM orders LIKE 'ref'")->fetch();
    if(!$col2){ try{ $pdo->exec("ALTER TABLE orders ADD COLUMN ref VARCHAR(64) NULL AFTER id"); }catch(Exception $e){} }
}catch(Exception $e){
    // ignore: if orders table doesn't exist or user lacks privileges, insertion will fall back later
}

// fetch product rows
$ids = array_map('intval', array_keys($cart));
$place = implode(',', $ids);
$stmt = $pdo->query("SELECT * FROM menu_items WHERE id IN ($place)");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach($rows as $r) $byId[$r['id']] = $r;

$total = 0.0;
foreach($cart as $id => $qty){
    if(isset($byId[$id])) $total += $byId[$id]['price'] * $qty;
}

try{
    $pdo->beginTransaction();
    // generate a random order reference (e.g., ORD-AB12CD)
    try{ $rand = bin2hex(random_bytes(4)); } catch(Exception $e){ $rand = substr(md5(uniqid('',true)),0,8); }
    $ref = 'ORD-' . strtoupper(substr($rand,0,6));
    // Insert order; attach user_id when user is logged in
    if(!empty($_SESSION['user_id'])){
        $s = $pdo->prepare('INSERT INTO orders (ref, session_id, user_id, total) VALUES (:ref, :sid, :uid, :total)');
        $s->execute([':ref'=>$ref, ':sid'=>session_id(), ':uid'=>(int)$_SESSION['user_id'], ':total'=>$total]);
    } else {
        $s = $pdo->prepare('INSERT INTO orders (ref, session_id, total) VALUES (:ref, :sid, :total)');
        $s->execute([':ref'=>$ref, ':sid'=>session_id(), ':total'=>$total]);
    }
    $orderId = $pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO order_items (order_id, menu_item_id, product_name, quantity, price, status) VALUES (:oid,:mid,:pname,:qty,:price,:status)');
    $upd = $pdo->prepare('UPDATE menu_items SET buy_count = buy_count + :qty WHERE id = :id');

    foreach($cart as $id => $qty){
        if(!isset($byId[$id])) continue;
        $it = $byId[$id];
        $ins->execute([
            ':oid'=>$orderId, ':mid'=>$id, ':pname'=>$it['name'], ':qty'=>$qty, ':price'=>$it['price'], ':status'=>'processing'
        ]);
        $upd->execute([':qty'=>$qty, ':id'=>$id]);
    }

    $pdo->commit();
    // clear cart and set last order id
    if(!empty($_SESSION['user_id'])){
        // remove items from user_cart for this user
        try{
            if(!empty($selectedIds)){
                $place = implode(',', array_fill(0,count($selectedIds),'?'));
                $sql = "DELETE FROM user_cart WHERE user_id = ? AND menu_item_id IN ($place)";
                $stmt = $pdo->prepare($sql);
                $params = array_merge([(int)$_SESSION['user_id']], $selectedIds);
                $stmt->execute($params);
            } else {
                $d = $pdo->prepare('DELETE FROM user_cart WHERE user_id = :uid');
                $d->execute([':uid'=>(int)$_SESSION['user_id']]);
            }
        }catch(Exception $e){ /* ignore deletion errors */ }
    }
    // clear session cart or remove only selected items
    if(!empty($selectedIds)){
        if(!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        foreach($selectedIds as $mid) unset($_SESSION['cart'][$mid]);
    } else {
        unset($_SESSION['cart']);
    }
    $_SESSION['last_order_id'] = $orderId;
    // If the request expects JSON (AJAX), return JSON with order ref so frontend can open My Orders modal
    $isAjax = false;
    if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') $isAjax = true;
    if(strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) $isAjax = true;
    // compute remaining cart product-count after deletion to return to AJAX clients
    $remainingCount = 0;
    try{
        if(!empty($_SESSION['user_id'])){
            $cstmt2 = $pdo->prepare('SELECT COUNT(*) FROM user_cart WHERE user_id = :uid');
            $cstmt2->execute([':uid'=>(int)$_SESSION['user_id']]);
            $remainingCount = (int)$cstmt2->fetchColumn();
        } else {
            $remainingCount = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
        }
    }catch(Exception $e){ $remainingCount = 0; }

    if($isAjax){
        echo json_encode(['ok'=>true,'order_id'=>$orderId,'ref'=>$ref,'count'=>$remainingCount]);
        exit;
    }

    header('Location: order_progress.php');
    exit;
}catch(Exception $e){
    $pdo->rollBack();
    die('Checkout failed: ' . $e->getMessage());
}
