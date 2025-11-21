<?php
// increment_buy.php
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
if($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'Method']); exit; }
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if(!$id){ http_response_code(400); echo json_encode(['ok'=>false,'msg'=>'Missing id']); exit; }
try{
  $pdo = getPDO();
  $stmt = $pdo->prepare('UPDATE menu_items SET buy_count = buy_count + 1 WHERE id = :id');
  $stmt->execute([':id'=>$id]);
  $s = $pdo->prepare('SELECT buy_count FROM menu_items WHERE id = :id');
  $s->execute([':id'=>$id]);
  $v = $s->fetchColumn();
  echo json_encode(['ok'=>true,'buy_count'=>(int)$v]);
}catch(Exception $e){ http_response_code(500); echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
