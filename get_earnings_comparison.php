<?php
// get_earnings_comparison.php - returns HTML fragment comparing current range vs previous same-length range
require_once __DIR__ . '/db.php';
header('Content-Type: application/json');

$range = isset($_GET['range']) ? (int)$_GET['range'] : 7;
if($range <= 0) $range = 7;

try{
    $pdo = getPDO();
    // compute totals for current range
    $sth = $pdo->prepare("SELECT IFNULL(SUM(IFNULL(NULLIF(amount,0),(IFNULL(base_pay,0)+IFNULL(bonus,0)+IFNULL(tip,0)+IFNULL(fee,0)))),0) FROM deliveries WHERE DATE(COALESCE(delivered_at, completed_at, created_at)) >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $sth->execute([$range]);
    $current = (float)$sth->fetchColumn();

    // previous range
    $sth = $pdo->prepare("SELECT IFNULL(SUM(IFNULL(NULLIF(amount,0),(IFNULL(base_pay,0)+IFNULL(bonus,0)+IFNULL(tip,0)+IFNULL(fee,0)))),0) FROM deliveries WHERE DATE(COALESCE(delivered_at, completed_at, created_at)) >= DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE(COALESCE(delivered_at, completed_at, created_at)) < DATE_SUB(CURDATE(), INTERVAL ? DAY)");
    $sth->execute([$range * 2, $range]);
    $previous = (float)$sth->fetchColumn();

    $diff = $current - $previous;
    $pct = $previous ? round(($diff / $previous) * 100, 1) : null;

    echo json_encode(['current'=>$current,'previous'=>$previous,'diff'=>$diff,'pct'=>$pct]);
    exit;

}catch(Exception $e){
    echo json_encode(['error'=>'no data']);
    exit;
}
