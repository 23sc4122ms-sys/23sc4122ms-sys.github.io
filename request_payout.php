<?php
// request_payout.php - rider requests a payout (cash out)
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$pdo = getPDO();

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}
$rid = (int)$_SESSION['user_id'];

$raw = file_get_contents('php://input');
$data = $_POST;
if(!$data && $raw){
    $json = json_decode($raw, true);
    if(is_array($json)) $data = $json;
}

$amount = isset($data['amount']) ? (float)$data['amount'] : 0.0;
if($amount <= 0){
    echo json_encode(['ok'=>false,'error'=>'Invalid amount']);
    exit;
}
// enforce minimum cashout
if(defined('CASHOUT_MIN') && $amount < CASHOUT_MIN){
    echo json_encode(['ok'=>false,'error'=>'Amount below minimum','min'=>number_format(CASHOUT_MIN,2)]);
    exit;
}

try{
    // compute available balance from rider_earnings - pending payouts
    $sth = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id = :rid');
    $sth->execute([':rid'=>$rid]);
    $earned = (float)$sth->fetchColumn();

    // schema-resilient pending lookup
    if(function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status')){
        $sth2 = $pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id = :rid AND status IN ('pending','processing')");
    }else{
        $sth2 = $pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id = :rid AND paid_at IS NULL");
    }
    $sth2->execute([':rid'=>$rid]);
    $pending = (float)$sth2->fetchColumn();

    $available = max(0, $earned - $pending);

    if($amount > $available){
        echo json_encode(['ok'=>false,'error'=>'Amount exceeds available balance','available'=>number_format($available,2)]);
        exit;
    }

    // choose INSERT form depending on schema
    if(function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status')){
           $ins = $pdo->prepare('INSERT INTO payouts (rider_id, amount, status, method, requested_at, created_at) VALUES (:rid,:amt,:status,:method,NOW(),NOW())');
           $ins->execute([':rid'=>$rid, ':amt'=>$amount, ':status'=>'processing', ':method'=>'direct_deposit']);
    }else{
        $ins = $pdo->prepare('INSERT INTO payouts (rider_id, amount, method, requested_at, created_at) VALUES (:rid,:amt,:method,NOW(),NOW())');
        $ins->execute([':rid'=>$rid, ':amt'=>$amount, ':method'=>'direct_deposit']);
    }
    $payoutId = (int)$pdo->lastInsertId();

    echo json_encode(['ok'=>true,'payout_id'=>$payoutId,'amount'=>number_format($amount,2)]);
    exit;
}catch(Exception $e){
    echo json_encode(['ok'=>false,'error'=>'Server error']);
    exit;
}

?>
