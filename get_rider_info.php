<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();
session_start();

$rid = isset($_GET['rider']) ? (int)$_GET['rider'] : 0;
if($rid <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid rider']); exit; }

$s = $pdo->prepare('SELECT id, name, email, profile_photo FROM users WHERE id = :id AND role = "rider" LIMIT 1');
$s->execute([':id'=>$rid]);
$r = $s->fetch(PDO::FETCH_ASSOC);
if(!$r){ echo json_encode(['ok'=>false,'error'=>'Rider not found']); exit; }

$avg = $pdo->prepare('SELECT AVG(rating) as avg_rating, COUNT(*) as cnt FROM rider_ratings WHERE rider_id = :rid');
$avg->execute([':rid'=>$rid]);
$ar = $avg->fetch(PDO::FETCH_ASSOC);

$your_rating = null;
if(!empty($_SESSION['user_id'])){
	$uid = (int)$_SESSION['user_id'];
	$yr = $pdo->prepare('SELECT rating FROM rider_ratings WHERE rider_id = :rid AND user_id = :uid LIMIT 1');
	$yr->execute([':rid'=>$rid, ':uid'=>$uid]);
	$val = $yr->fetchColumn();
	if($val !== false && $val !== null) $your_rating = (int)$val;
}

$payload = [
	'ok' => true,
	'id' => (int)$r['id'],
	'name' => $r['name'] ?: $r['email'],
	'email' => $r['email'] ?? null,
	'profile_photo' => !empty($r['profile_photo']) ? $r['profile_photo'] : null,
	'avg' => ($ar && $ar['avg_rating']) ? round($ar['avg_rating'],2) : null,
	'count' => (int)($ar['cnt'] ?? 0),
	'your_rating' => $your_rating
];

echo json_encode($payload);
exit;

?>
