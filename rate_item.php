<?php
// rate_item.php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/db.php';
if($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'Method']); exit; }
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
if(!$id || $rating < 1 || $rating > 5){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }

// prevent admin/owner from rating products
$role = strtolower($_SESSION['user_role'] ?? '');
if(in_array($role, ['admin','owner'], true)){
  http_response_code(403);
  echo json_encode(['ok'=>false,'msg'=>'Administrators cannot rate products']);
  exit;
}

try{
  $pdo = getPDO();

  // ensure user_ratings exists with unique constraint (user_id, menu_item_id)
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    rating TINYINT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_item_unique (user_id, menu_item_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  try{ $pdo->exec("ALTER TABLE user_ratings ADD UNIQUE KEY user_item_unique (user_id, menu_item_id)"); }catch(Exception $e){ }

  $uid = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  if(!$uid){
    // Require login to rate to prevent anonymous repeated ratings
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Login required to rate items']);
    exit;
  }

  // logged-in user: allow rating only once. If a rating exists, reject.
  try{
    $pdo->beginTransaction();
    $sel = $pdo->prepare('SELECT rating FROM user_ratings WHERE user_id = :uid AND menu_item_id = :mid LIMIT 1');
    $sel->execute([':uid'=>$uid, ':mid'=>$id]);
    $old = $sel->fetchColumn();
    if($old !== false && $old !== null){
      // user already rated this item - disallow changing
      $pdo->rollBack();
      http_response_code(409);
      echo json_encode(['ok'=>false,'msg'=>'You have already rated this item']);
      exit;
    }

    // insert new rating and update aggregates
    $ins = $pdo->prepare('INSERT INTO user_ratings (user_id, menu_item_id, rating, created_at) VALUES (:uid, :mid, :r, NOW())');
    $ins->execute([':uid'=>$uid, ':mid'=>$id, ':r'=>$rating]);
    $adj = $pdo->prepare('UPDATE menu_items SET rating_total = COALESCE(rating_total,0) + :r, rating_count = COALESCE(rating_count,0) + 1 WHERE id = :id');
    $adj->execute([':r'=>$rating, ':id'=>$id]);
    $pdo->commit();
  }catch(Exception $e){ try{ $pdo->rollBack(); }catch(Exception $e2){} throw $e; }

  $s = $pdo->prepare('SELECT rating_total, rating_count FROM menu_items WHERE id = :id');
  $s->execute([':id'=>$id]);
  $row = $s->fetch(PDO::FETCH_ASSOC);
  $avg = $row && $row['rating_count'] ? round($row['rating_total'] / $row['rating_count'],2) : 0;
  echo json_encode(['ok'=>true,'avg'=>$avg,'count'=>(int)$row['rating_count']]);
}catch(Exception $e){ http_response_code(500); echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
