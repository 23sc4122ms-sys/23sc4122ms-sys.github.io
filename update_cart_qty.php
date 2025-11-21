<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$qty = isset($_POST['qty']) ? (int)$_POST['qty'] : 0;
if($id <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }
if($qty < 0) $qty = 0;

try{
  if(!empty($_SESSION['user_id'])){
    $uid = (int)$_SESSION['user_id'];
    // ensure user_cart exists
    try{ $pdo->exec("CREATE TABLE IF NOT EXISTS user_cart (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, menu_item_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"); }catch(Exception $e){}

    if($qty <= 0){
      $stmt = $pdo->prepare('DELETE FROM user_cart WHERE user_id = :uid AND menu_item_id = :mid');
      $stmt->execute([':uid'=>$uid, ':mid'=>$id]);
    } else {
      // update or insert
      $s = $pdo->prepare('SELECT id FROM user_cart WHERE user_id = :uid AND menu_item_id = :mid LIMIT 1');
      $s->execute([':uid'=>$uid, ':mid'=>$id]);
      if($s->fetchColumn()){
        $upd = $pdo->prepare('UPDATE user_cart SET quantity = :q, updated_at = NOW() WHERE user_id = :uid AND menu_item_id = :mid');
        $upd->execute([':q'=>$qty, ':uid'=>$uid, ':mid'=>$id]);
      } else {
        $ins = $pdo->prepare('INSERT INTO user_cart (user_id, menu_item_id, quantity, created_at, updated_at) VALUES (:uid,:mid,:q,NOW(),NOW())');
        $ins->execute([':uid'=>$uid, ':mid'=>$id, ':q'=>$qty]);
      }
    }

    // compute item subtotal and total
    $itm = $pdo->prepare('SELECT mi.price, uc.quantity FROM user_cart uc LEFT JOIN menu_items mi ON mi.id = uc.menu_item_id WHERE uc.user_id = :uid AND uc.menu_item_id = :mid LIMIT 1');
    $itm->execute([':uid'=>$uid, ':mid'=>$id]);
    $row = $itm->fetch(PDO::FETCH_ASSOC);
    $itemSubtotal = 0.0; $newQty = 0;
    if($row){ $itemSubtotal = round(((float)$row['price']) * ((int)$row['quantity']),2); $newQty = (int)$row['quantity']; }

    $tstmt = $pdo->prepare('SELECT COALESCE(SUM(mi.price * uc.quantity),0) as total FROM user_cart uc LEFT JOIN menu_items mi ON mi.id = uc.menu_item_id WHERE uc.user_id = :uid');
    $tstmt->execute([':uid'=>$uid]);
    $total = (float)$tstmt->fetchColumn();

    $cstmt = $pdo->prepare('SELECT COUNT(*) FROM user_cart WHERE user_id = :uid');
    $cstmt->execute([':uid'=>$uid]);
    $count = (int)$cstmt->fetchColumn();

    echo json_encode(['ok'=>true,'qty'=>$newQty,'itemSubtotal'=>round($itemSubtotal,2),'total'=>round($total,2),'count'=>$count]); exit;

  } else {
    if(!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    if($qty <= 0){ unset($_SESSION['cart'][$id]); }
    else { $_SESSION['cart'][$id] = $qty; }

    // compute item subtotal using menu_items
    $ids = array_map('intval', array_keys($_SESSION['cart']));
    $itemPrice = 0.0; $itemQty = $qty;
    if(!empty($ids)){
      $place = implode(',', $ids);
      $stmt = $pdo->query("SELECT id, price FROM menu_items WHERE id IN ($place)");
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $byId = []; foreach($rows as $r) $byId[$r['id']] = $r;
      if(isset($byId[$id])) $itemPrice = (float)$byId[$id]['price'];
    }
    $itemSubtotal = round($itemPrice * $itemQty,2);

    $total = 0.0; $byId = []; foreach($_SESSION['cart'] as $mid => $q) {
      // find price
      $total += (isset($byId[$mid]) ? (float)$byId[$mid]['price'] : 0.0) * (int)$q;
    }
    $count = count($_SESSION['cart']);
    echo json_encode(['ok'=>true,'qty'=>($qty>0? $qty:0),'itemSubtotal'=>round($itemSubtotal,2),'total'=>round($total,2),'count'=>$count]); exit;
  }
}catch(Exception $e){
  echo json_encode(['ok'=>false,'error'=>'DB error: '. $e->getMessage()]); exit;
}

?>
