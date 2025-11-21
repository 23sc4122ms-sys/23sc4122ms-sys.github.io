<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    echo json_encode(['ok'=>false,'error'=>'POST required']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$qty = isset($_POST['qty']) ? max(1, (int)$_POST['qty']) : 1;
if($qty < 1) $qty = 1;
if($id <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }

// verify product exists
try{
    $stmt = $pdo->prepare('SELECT id, name, price FROM menu_items WHERE id = :id');
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row){ echo json_encode(['ok'=>false,'error'=>'Product not found']); exit; }
}catch(Exception $e){ echo json_encode(['ok'=>false,'error'=>'DB error: '. $e->getMessage()]); exit; }

// If user is logged in, save cart to `user_cart` table; otherwise use session cart
if(!empty($_SESSION['user_id'])){
    $uid = (int)$_SESSION['user_id'];
    try{
        // ensure user_cart exists (in case migration wasn't run)
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_cart (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                menu_item_id INT NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (user_id),
                INDEX (menu_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $inner){ /* ignore create errors */ }

        // try to update existing row
        $s = $pdo->prepare('SELECT quantity FROM user_cart WHERE user_id = :uid AND menu_item_id = :mid LIMIT 1');
        $s->execute([':uid'=>$uid, ':mid'=>$id]);
        $existing = $s->fetchColumn();
        if($existing !== false){
            $upd = $pdo->prepare('UPDATE user_cart SET quantity = quantity + :q, updated_at = NOW() WHERE user_id = :uid AND menu_item_id = :mid');
            $upd->execute([':q'=>$qty, ':uid'=>$uid, ':mid'=>$id]);
        } else {
            $ins = $pdo->prepare('INSERT INTO user_cart (user_id, menu_item_id, quantity, created_at, updated_at) VALUES (:uid,:mid,:q,NOW(),NOW())');
            $ins->execute([':uid'=>$uid, ':mid'=>$id, ':q'=>$qty]);
        }

        // compute total distinct product count for this user
        $cstmt = $pdo->prepare('SELECT COUNT(*) FROM user_cart WHERE user_id = :uid');
        $cstmt->execute([':uid'=>$uid]);
        $count = (int)$cstmt->fetchColumn();
        echo json_encode(['ok'=>true,'count'=>$count]);
        exit;
    }catch(Exception $e){
        echo json_encode(['ok'=>false,'error'=>'DB error: '. $e->getMessage()]);
        exit;
    }
} else {
    if(!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    if(!isset($_SESSION['cart'][$id])) $_SESSION['cart'][$id] = 0;
    $_SESSION['cart'][$id] += $qty;

    // compute total distinct product count for session
    $count = count($_SESSION['cart']);

    echo json_encode(['ok'=>true,'count'=>$count]);
    exit;
}
?>

