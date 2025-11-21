<?php
// get_weekly_earnings.php - returns this week's earnings summary for the logged-in rider
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}
$rid = (int)$_SESSION['user_id'];

// determine current week (Monday-Sunday)
$dt = new DateTime('now');
$dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'), 1);
$week_start = $dt->format('Y-m-d');
$week_end = $dt->modify('+6 days')->format('Y-m-d');

try{
    // try persisted table first
    $sth = $pdo->prepare('SELECT total_amount, total_orders, daily_avg, per_order_avg FROM rider_weekly_earnings WHERE rider_id = :rid AND week_start = :ws LIMIT 1');
    $sth->execute([':rid'=>$rid, ':ws'=>$week_start]);
    $row = $sth->fetch(PDO::FETCH_ASSOC);
    
    // fetch rider_accounts for total_earnings and week_earn (base_pay removed)
    $accountRow = null;
    try{
        $accSth = $pdo->prepare('SELECT total_earnings, week_earn FROM rider_accounts WHERE rider_id = :rid LIMIT 1');
        $accSth->execute([':rid'=>$rid]);
        $accountRow = $accSth->fetch(PDO::FETCH_ASSOC);
    }catch(Exception $e){ /* ignore if table not present */ }
    
    if($row){
        $out = ['ok'=>true,'week_start'=>$week_start,'week_end'=>$week_end,'total'=>number_format((float)$row['total_amount'],2,'.',''),'daily_avg'=>number_format((float)$row['daily_avg'],2,'.',''),'per_order'=>number_format((float)$row['per_order_avg'],2,'.',''),'total_orders'=>(int)$row['total_orders']];
        if($accountRow){
            $out['total_earnings'] = number_format((float)$accountRow['total_earnings'],2,'.','');
        }
        echo json_encode($out);
        exit;
    }

    // compute weekly totals and averages from rider_earnings (authoritative)
    // prefer persisted `week_earn` from rider_accounts if present, otherwise sum from rider_earnings
    $weekTotal = 0.0;
    if($accountRow && isset($accountRow['week_earn']) && $accountRow['week_earn'] !== null){
        $weekTotal = (float)$accountRow['week_earn'];
    } else {
        $sth2 = $pdo->prepare('SELECT IFNULL(SUM(amount),0) AS total, COUNT(*) AS orders FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) BETWEEN :ws AND :we');
        $sth2->execute([':rid'=>$rid, ':ws'=>$week_start, ':we'=>$week_end]);
        $r = $sth2->fetch(PDO::FETCH_ASSOC);
        $weekTotal = (float)($r['total'] ?? 0);
        $orders = (int)($r['orders'] ?? 0);
    }

    // average per-order (AVG(amount)) over the week range
    $avgSth = $pdo->prepare('SELECT IFNULL(AVG(amount),0) AS avg_all FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) BETWEEN :ws AND :we');
    $avgSth->execute([':rid'=>$rid, ':ws'=>$week_start, ':we'=>$week_end]);
    $avgRow = $avgSth->fetch(PDO::FETCH_ASSOC);
    $avgAll = (float)($avgRow['avg_all'] ?? 0);

    // count orders for the week (if not already computed)
    if(!isset($orders)){
        $cntSth = $pdo->prepare('SELECT COUNT(*) AS c FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) BETWEEN :ws AND :we');
        $cntSth->execute([':rid'=>$rid, ':ws'=>$week_start, ':we'=>$week_end]);
        $cntRow = $cntSth->fetch(PDO::FETCH_ASSOC);
        $orders = (int)($cntRow['c'] ?? 0);
    }

    // today's earning (sum of today's rider_earnings)
    $todaySth = $pdo->prepare('SELECT IFNULL(SUM(amount),0) AS today_total FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) = CURDATE()');
    $todaySth->execute([':rid'=>$rid]);
    $todayRow = $todaySth->fetch(PDO::FETCH_ASSOC);
    $todayTotal = (float)($todayRow['today_total'] ?? 0);

    $daily = round($avgAll, 2);
    // per_order field is repurposed to show weekly earning as requested
    $perorder = round($weekTotal, 2);
    $out = ['ok'=>true,'week_start'=>$week_start,'week_end'=>$week_end,'total'=>number_format($weekTotal,2,'.',''),'daily_avg'=>number_format($daily,2,'.',''),'per_order'=>number_format($perorder,2,'.',''),'total_orders'=>$orders,'today'=>number_format($todayTotal,2,'.','')];

    // also add total_earnings from rider_accounts
    if($accountRow){
        $out['total_earnings'] = number_format((float)$accountRow['total_earnings'],2,'.','');
    }
    
    if(isset($_GET['debug']) && $_GET['debug']){
        $out['debug'] = [ 'source' => 'rider_earnings', 'params' => ['rid'=>$rid,'ws'=>$week_start,'we'=>$week_end] ];
    }
    echo json_encode($out);
    exit;
}catch(Exception $e){
    echo json_encode(['ok'=>false,'error'=>'Server error']); exit;
}

?>
