<?php
// get_earnings_csv.php - export earnings CSV for a range
require_once __DIR__ . '/db.php';
session_start();

$range = isset($_GET['range']) ? (int)$_GET['range'] : 7;
if($range <= 0) $range = 7;

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}
$rid = (int)$_SESSION['user_id'];

try{
    $pdo = getPDO();

    // date span for range
    $endDate = new DateTime('today');
    $startDate = (clone $endDate)->modify('-' . ($range - 1) . ' days');
    $start = $startDate->format('Y-m-d');
    $end = $endDate->format('Y-m-d');

    // helper: check for rider_earnings table
    $tblStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rider_earnings'");
    $tblStmt->execute();
    $hasRiderEarnings = (bool)$tblStmt->fetchColumn();

    // compute today's earning
    if($hasRiderEarnings){
        $sth = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) = CURDATE()');
        $sth->execute([':rid'=>$rid]);
        $today = (float)$sth->fetchColumn();
    } else {
        // fallback to deliveries with safe column checks
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME IN ('amount','base_pay','bonus','tip','fee','created_at')");
        $colStmt->execute();
        $cols = array_map(function($r){ return $r['COLUMN_NAME']; }, $colStmt->fetchAll(PDO::FETCH_ASSOC));
        $hasAmount = in_array('amount',$cols,true);
        $components = [];
        foreach(['base_pay','bonus','tip','fee'] as $c){ if(in_array($c,$cols,true)) $components[] = "IFNULL({$c},0)"; }
        $inner = count($components) ? implode('+',$components) : '0';
        $amountExpr = $hasAmount ? "IFNULL(NULLIF(amount,0),({$inner}))" : "({$inner})";
        $sth = $pdo->prepare("SELECT IFNULL(SUM({$amountExpr}),0) FROM deliveries WHERE rider_id = :rid AND DATE(created_at) = CURDATE()");
        $sth->execute([':rid'=>$rid]);
        $today = (float)$sth->fetchColumn();
    }

    // average earning (AVG(amount)) over the provided range
    if($hasRiderEarnings){
        $avgSt = $pdo->prepare('SELECT IFNULL(AVG(amount),0) FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) BETWEEN :start AND :end');
        $avgSt->execute([':rid'=>$rid, ':start'=>$start, ':end'=>$end]);
        $avg = (float)$avgSt->fetchColumn();
    } else {
        // fallback: average per-order from deliveries (sum/count)
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME IN ('amount','base_pay','bonus','tip','fee','created_at')");
        $colStmt->execute();
        $cols = array_map(function($r){ return $r['COLUMN_NAME']; }, $colStmt->fetchAll(PDO::FETCH_ASSOC));
        $hasAmount = in_array('amount',$cols,true);
        $components = [];
        foreach(['base_pay','bonus','tip','fee'] as $c){ if(in_array($c,$cols,true)) $components[] = "IFNULL({$c},0)"; }
        $inner = count($components) ? implode('+',$components) : '0';
        $amountExpr = $hasAmount ? "IFNULL(NULLIF(amount,0),({$inner}))" : "({$inner})";
        $avgSt = $pdo->prepare("SELECT IFNULL(SUM({$amountExpr}),0) as s, COUNT(*) as c FROM deliveries WHERE rider_id = :rid AND DATE(created_at) BETWEEN :start AND :end");
        $avgSt->execute([':rid'=>$rid, ':start'=>$start, ':end'=>$end]);
        $ar = $avgSt->fetch(PDO::FETCH_ASSOC);
        $s = (float)($ar['s'] ?? 0); $c = (int)($ar['c'] ?? 0);
        $avg = $c ? ($s / $c) : 0.0;
    }

    // weekly earning: prefer rider_accounts.week_earn, else compute from rider_earnings/deliveries
    $weekStart = (new DateTime('now'))->setISODate((int)(new DateTime())->format('o'), (int)(new DateTime())->format('W'), 1)->format('Y-m-d');
    $weekEnd = (new DateTime($weekStart))->modify('+6 days')->format('Y-m-d');
    $accS = $pdo->prepare("SELECT week_earn FROM rider_accounts WHERE rider_id = :rid LIMIT 1");
    $accS->execute([':rid'=>$rid]);
    $accRow = $accS->fetch(PDO::FETCH_ASSOC);
    if($accRow && isset($accRow['week_earn'])){
        $weekEarn = (float)$accRow['week_earn'];
    } else {
        if($hasRiderEarnings){
            $wSt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) BETWEEN :ws AND :we');
            $wSt->execute([':rid'=>$rid, ':ws'=>$weekStart, ':we'=>$weekEnd]);
            $weekEarn = (float)$wSt->fetchColumn();
        } else {
            // deliveries fallback
            $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deliveries' AND COLUMN_NAME IN ('amount','base_pay','bonus','tip','fee','created_at')");
            $colStmt->execute();
            $cols = array_map(function($r){ return $r['COLUMN_NAME']; }, $colStmt->fetchAll(PDO::FETCH_ASSOC));
            $hasAmount = in_array('amount',$cols,true);
            $components = [];
            foreach(['base_pay','bonus','tip','fee'] as $c){ if(in_array($c,$cols,true)) $components[] = "IFNULL({$c},0)"; }
            $inner = count($components) ? implode('+',$components) : '0';
            $amountExpr = $hasAmount ? "IFNULL(NULLIF(amount,0),({$inner}))" : "({$inner})";
            $wSt = $pdo->prepare("SELECT IFNULL(SUM({$amountExpr}),0) FROM deliveries WHERE rider_id = :rid AND DATE(created_at) BETWEEN :ws AND :we");
            $wSt->execute([':rid'=>$rid, ':ws'=>$weekStart, ':we'=>$weekEnd]);
            $weekEarn = (float)$wSt->fetchColumn();
        }
    }

    // total orders in the requested range
    if($hasRiderEarnings){
        $oSt = $pdo->prepare('SELECT COUNT(*) FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) BETWEEN :start AND :end');
        $oSt->execute([':rid'=>$rid, ':start'=>$start, ':end'=>$end]);
        $orders = (int)$oSt->fetchColumn();
    } else {
        $oSt = $pdo->prepare('SELECT COUNT(*) FROM deliveries WHERE rider_id = :rid AND DATE(created_at) BETWEEN :start AND :end');
        $oSt->execute([':rid'=>$rid, ':start'=>$start, ':end'=>$end]);
        $orders = (int)$oSt->fetchColumn();
    }

    // output CSV with the requested summary columns
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="earnings_summary_' . date('Ymd') . '.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Today\'s Earning','Average Earning','Weekly Earning','Total Orders']);
    fputcsv($out, [number_format($today,2,'.',''), number_format($avg,2,'.',''), number_format($weekEarn,2,'.',''), $orders]);
    fclose($out);
    exit;
}catch(Exception $e){
    http_response_code(500);
    echo 'Failed to generate CSV: ' . $e->getMessage();
    exit;
}
