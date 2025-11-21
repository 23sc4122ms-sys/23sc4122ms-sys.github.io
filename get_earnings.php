<?php
// get_earnings.php
// Returns JSON with labels and dataset arrays for the earnings chart.
// Tries to read from `deliveries` table (if available), otherwise returns sample data.
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
session_start();
// require rider context so chart is per-rider
if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    echo json_encode(['labels'=>[], 'base'=>[], 'total'=>[], 'has_data'=>false, 'summary'=>['week_total'=>0,'daily_avg'=>0,'per_order'=>0,'total_orders'=>0]]);
    exit;
}
$rid = (int)$_SESSION['user_id'];

$labels = [];
$base = [];
$total = [];

// Accept optional range param: number of days (e.g., 7,30) or start/end dates
$range = isset($_GET['range']) ? (int)$_GET['range'] : (isset($_POST['range']) ? (int)$_POST['range'] : 7);
if($range <= 0) $range = 7;

try{
    $pdo = getPDO();

    // attempt to read persisted week_earn from rider_accounts
    $accountWeek = null;
    try{
        $accStmt = $pdo->prepare('SELECT week_earn FROM rider_accounts WHERE rider_id = :rid LIMIT 1');
        $accStmt->execute([':rid'=>$rid]);
        $accR = $accStmt->fetch(PDO::FETCH_ASSOC);
        if($accR && isset($accR['week_earn'])) $accountWeek = (float)$accR['week_earn'];
    }catch(Exception $e){ $accountWeek = null; }

    // Build $range days labels ending today and query rider_earnings for amounts per day
    $days = [];
    for($i = $range - 1; $i >= 0; $i--){
        $d = new DateTime("-{$i} days");
        $days[] = $d->format('Y-m-d');
        $labels[] = $d->format('D');
    }

        $placeholders = implode(',', array_fill(0, count($days), '?'));
        // Query rider_earnings by created_at date for total earnings
        $sql = "SELECT DATE(created_at) as d, IFNULL(SUM(amount),0) as total_amt, IFNULL(COUNT(*),0) as cnt, IFNULL(AVG(amount),0) as avg_amt
            FROM rider_earnings
            WHERE rider_id = ? AND DATE(created_at) IN ($placeholders)
            GROUP BY DATE(created_at)";

    $stmt = $pdo->prepare($sql);
    $params = array_merge([$rid], $days);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach($rows as $r) $map[$r['d']] = $r;

    $total_orders = 0;
    foreach($days as $d){
        if(isset($map[$d])){
            $total[] = (float)$map[$d]['total_amt'];
            $total_orders += (int)$map[$d]['cnt'];
        } else {
            $total[] = 0;
        }
    }

    // Build base pay array from deliveries.base_pay (fallback to zero if table/column absent)
    $base = [];
    try {
        // Ensure deliveries table and base_pay column exist before querying
        $colCheck = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'deliveries'");
        $colCheck->execute([':db'=>DB_NAME]);
        $cols = $colCheck->fetchAll(PDO::FETCH_COLUMN);
        $hasBase = in_array('base_pay', $cols);
        if($hasBase){
            $sqlBase = "SELECT DATE(COALESCE(delivered_at, completed_at, created_at)) as d, IFNULL(SUM(IFNULL(base_pay,0)),0) as base_sum
                        FROM deliveries
                        WHERE rider_id = ? AND DATE(COALESCE(delivered_at, completed_at, created_at)) IN ($placeholders)
                        GROUP BY DATE(COALESCE(delivered_at, completed_at, created_at))";
            $stmtBase = $pdo->prepare($sqlBase);
            $stmtBase->execute($params); // params same shape (rider_id + days)
            $rowsBase = $stmtBase->fetchAll(PDO::FETCH_ASSOC);
            $mapBase = [];
            foreach($rowsBase as $rb){ $mapBase[$rb['d']] = $rb; }
            foreach($days as $d){ $base[] = isset($mapBase[$d]) ? (float)$mapBase[$d]['base_sum'] : 0.0; }
        } else {
            foreach($days as $d){ $base[] = 0.0; }
        }
    } catch(Exception $e){ foreach($days as $d){ $base[] = 0.0; } }

    // summary: week total is sum of daily totals; prefer persisted rider_accounts.week_earn when available
    $computed_week_total = array_sum($total);
    $week_total = (is_null($accountWeek) ? $computed_week_total : $accountWeek);
    // compute overall AVG(amount) across range — if there are records, compute via separate query for exact AVG
    $avgStmt = $pdo->prepare("SELECT IFNULL(AVG(amount),0) as avg_all, IFNULL(SUM(amount),0) as sum_all, IFNULL(COUNT(*),0) as cnt_all FROM rider_earnings WHERE rider_id = :rid AND DATE(created_at) BETWEEN :start AND :end");
    $start = reset($days);
    $end = end($days);
    $avgStmt->execute([':rid'=>$rid, ':start'=>$start, ':end'=>$end]);
    $avgRow = $avgStmt->fetch(PDO::FETCH_ASSOC);
    $daily_avg = (float)($avgRow['avg_all'] ?? 0);

    $out = [
        'labels'=>$labels,
        'base'=>$base,
        'total'=>$total,
        'has_data' => (($total_orders > 0) || ($week_total > 0)),
        'summary'=>[ 'week_total'=>$week_total, 'daily_avg'=>$daily_avg, 'per_order'=> $total_orders ? round($week_total/$total_orders,2) : 0, 'total_orders'=>$total_orders ]
    ];

    // include debug info if requested
    if(isset($_GET['debug']) && $_GET['debug']){
        $out['debug'] = [
            'query_days' => $days,
            'db_rows' => $rows,
            'sql' => $sql,
            'params' => $params
        ];
    }

    echo json_encode($out);
    exit;

}catch(Exception $e){
    // If anything fails, return empty datasets (no sample data) so UI shows 'no data yet'
    $labels = [];
    for($i = $range - 1; $i >= 0; $i--){ $labels[] = (new DateTime("-{$i} days"))->format('D'); }
    $base = array_fill(0, count($labels), 0);
    $total = array_fill(0, count($labels), 0);
    echo json_encode([
        'labels'=>$labels,
        'base'=>array_fill(0, count($labels), 0),
        'total'=>array_fill(0, count($labels), 0),
        'has_data' => false,
        'summary'=>[ 'week_total'=>0, 'daily_avg'=>0, 'per_order'=>0, 'total_orders'=>0 ]
    ]);
    exit;
}
