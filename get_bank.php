<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if($id <= 0){ echo json_encode(['ok'=>false,'error'=>'Invalid id']); exit; }

$s = $pdo->prepare('SELECT id, bank_name, account_name, account_number, status, created_at FROM bank_accounts WHERE id = :id LIMIT 1');
$s->execute([':id'=>$id]);
$b = $s->fetch(PDO::FETCH_ASSOC);
if(!$b){ echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

echo json_encode(['ok'=>true,'bank'=>$b]);
exit;

?>
