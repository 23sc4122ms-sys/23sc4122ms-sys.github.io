<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
  http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST required']); exit;
}

$riderId = isset($_POST['rider_id']) ? (int)$_POST['rider_id'] : 0;
$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
if($riderId <= 0 || $rating < 1 || $rating > 5){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid parameters']); exit; }

// prevent admin/owner from rating riders
$role = strtolower($_SESSION['user_role'] ?? '');
if(in_array($role, ['admin','owner'], true)){
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Administrators cannot rate riders']);
  exit;
}

try{
  // ensure table exists
  $pdo->exec("CREATE TABLE IF NOT EXISTS rider_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    rating TINYINT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_rider_unique (user_id, rider_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // attempt to add unique index if it doesn't exist (ignore errors)
  try{ $pdo->exec("ALTER TABLE rider_ratings ADD UNIQUE KEY user_rider_unique (user_id, rider_id)"); }catch(Exception $e){ }

  $uid = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
  if(!$uid){
    // require login to rate riders
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Login required to rate riders']); exit;
  }

  // For logged-in users, only allow one rating (do not update existing rating)
  try{
    $sel = $pdo->prepare('SELECT rating FROM rider_ratings WHERE rider_id = :rid AND user_id = :uid LIMIT 1');
    $sel->execute([':rid'=>$riderId, ':uid'=>$uid]);
    $ex = $sel->fetchColumn();
    if($ex !== false && $ex !== null){
      http_response_code(409);
      echo json_encode(['ok'=>false,'error'=>'You have already rated this rider']); exit;
    }

    $ins = $pdo->prepare('INSERT INTO rider_ratings (rider_id, user_id, rating, created_at) VALUES (:rid, :uid, :r, NOW())');
    $ins->execute([':rid'=>$riderId, ':uid'=>$uid, ':r'=>$rating]);
  }catch(Exception $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }

  $avgSt = $pdo->prepare('SELECT AVG(rating) as avg_rating, COUNT(*) as cnt FROM rider_ratings WHERE rider_id = :rid');
  $avgSt->execute([':rid'=>$riderId]);
  $row = $avgSt->fetch(PDO::FETCH_ASSOC);
  $avg = $row && $row['avg_rating'] ? round($row['avg_rating'],2) : 0;
  $cnt = (int)($row['cnt'] ?? 0);

  echo json_encode(['ok'=>true,'avg'=>$avg,'count'=>$cnt]); exit;
}catch(Exception $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
?>