<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$ids = [];
// accept ids[] from form-encoded or a comma-separated ids param
if(!empty($_POST['ids']) && is_array($_POST['ids'])){
  foreach($_POST['ids'] as $v) $ids[] = (int)$v;
} elseif(!empty($_POST['ids'])){
  // fallback: single value or comma-separated
  $parts = is_string($_POST['ids']) ? explode(',', $_POST['ids']) : [$_POST['ids']];
  foreach($parts as $v) $ids[] = (int) $v;
}

$ids = array_values(array_filter($ids, function($v){ return $v>0; }));
if(empty($ids)){
  echo json_encode(['ok'=>false,'error'=>'No ids provided']); exit;
}

try{
  if(!empty($_SESSION['user_id'])){
    $uid = (int)$_SESSION['user_id'];
    // ensure table exists (safe noop if it does)
    try{ $pdo->exec("CREATE TABLE IF NOT EXISTS user_cart (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, menu_item_id INT NOT NULL, quantity INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"); }catch(Exception $e){}
    $place = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM user_cart WHERE user_id = ? AND menu_item_id IN ($place)";
    $stmt = $pdo->prepare($sql);
    $params = array_merge([$uid], $ids);
    $stmt->execute($params);

    $cstmt = $pdo->prepare('SELECT COUNT(*) FROM user_cart WHERE user_id = :uid');
    $cstmt->execute([':uid'=>$uid]);
    $count = (int)$cstmt->fetchColumn();
    echo json_encode(['ok'=>true,'count'=>$count]); exit;
  } else {
    if(!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
    foreach($ids as $mid) unset($_SESSION['cart'][$mid]);
    $count = count($_SESSION['cart']);
    echo json_encode(['ok'=>true,'count'=>$count]); exit;
  }
}catch(Exception $e){
  echo json_encode(['ok'=>false,'error'=>'DB error: '. $e->getMessage()]); exit;
}

?>
