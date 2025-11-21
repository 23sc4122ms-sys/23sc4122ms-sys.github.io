<?php
// backfill_weekly_earnings.php
// Admin script to compute this week's (or historical weeks) totals per rider from rider_earnings

session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if(empty($_SESSION['user_id']) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    http_response_code(403);
    echo "<h3>Forbidden</h3><div>Please sign in as admin to run this script.</div>";
    exit;
}

// Accept optional ?week_start=YYYY-MM-DD to backfill a specific week, otherwise use current week (Monday-Sunday)
$weekStart = isset($_GET['week_start']) ? $_GET['week_start'] : null;
if(!$weekStart){
    // compute Monday of this week (ISO)
    $dt = new DateTime('now');
    // ISO week starts Monday
    $dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'), 1);
    $weekStart = $dt->format('Y-m-d');
}
$weekStartDt = new DateTime($weekStart);
$weekEndDt = clone $weekStartDt; $weekEndDt->modify('+6 days');
$ws = $weekStartDt->format('Y-m-d');
$we = $weekEndDt->format('Y-m-d');

echo '<div style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:16px">';
echo '<h3>Backfill Weekly Earnings</h3>';
echo '<div>Computing for week: <strong>' . htmlspecialchars($ws) . '</strong> to <strong>' . htmlspecialchars($we) . '</strong></div>';

try{
    // aggregate per rider from deliveries (preferred source of truth) for the specified week
    $sql = "SELECT DISTINCT d.rider_id 
            FROM deliveries d 
            WHERE DATE(COALESCE(d.delivered_at, d.completed_at, d.created_at)) BETWEEN :ws AND :we
            AND d.rider_id IS NOT NULL";
    $sth = $pdo->prepare($sql);
    $sth->execute([':ws'=>$ws, ':we'=>$we]);
    $riders = $sth->fetchAll(PDO::FETCH_COLUMN);
    
    if(!$riders || count($riders) === 0){ 
        echo '<div style="color:#6b7280;padding:12px">No deliveries found for this week.</div>'; 
        echo '</div>'; 
        exit; 
    }

    // Calculate and upsert for each rider
    $up = $pdo->prepare("INSERT INTO rider_weekly_earnings (rider_id, week_start, week_end, total_amount, total_orders, daily_avg, per_order_avg, created_at)
        VALUES (:rid,:ws,:we,:tot,:orders,:daily,:perorder,NOW())
        ON DUPLICATE KEY UPDATE total_amount = VALUES(total_amount), total_orders = VALUES(total_orders), daily_avg = VALUES(daily_avg), per_order_avg = VALUES(per_order_avg), updated_at = NOW()");

    $count = 0;
    foreach($riders as $rid){
        // Calculate from deliveries: use amount if present, else sum components
        $calc = $pdo->prepare("
            SELECT 
                IFNULL(SUM(IFNULL(NULLIF(amount,0),(IFNULL(base_pay,0)+IFNULL(bonus,0)+IFNULL(tip,0)+IFNULL(fee,0)))),0) as total_amount,
                COUNT(*) as total_orders
            FROM deliveries
            WHERE rider_id = :rid 
            AND DATE(COALESCE(delivered_at, completed_at, created_at)) BETWEEN :ws AND :we
        ");
        $calc->execute([':rid'=>$rid, ':ws'=>$ws, ':we'=>$we]);
        $data = $calc->fetch(PDO::FETCH_ASSOC);
        
        $tot = (float)($data['total_amount'] ?? 0);
        $orders = (int)($data['total_orders'] ?? 0);
        $daily = round($tot / 7.0, 2);
        $perorder = $orders ? round($tot / $orders, 2) : 0.00;
        
        $up->execute([
            ':rid'=>$rid, 
            ':ws'=>$ws, 
            ':we'=>$we, 
            ':tot'=>number_format($tot,2,'.',''), 
            ':orders'=>$orders, 
            ':daily'=>number_format($daily,2,'.',''), 
            ':perorder'=>number_format($perorder,2,'.','')
        ]);
        $count++;
    }

    echo '<div style="background:#d1fae5;padding:12px;border-radius:6px;margin-bottom:16px;color:#065f46">';
    echo '<strong>✓ Success!</strong> Updated <strong>' . $count . '</strong> rider weekly earnings from deliveries table.';
    echo '</div>';
    
    // Show sample
    echo '<div style="margin-top:16px"><h4>Sample Rows:</h4>';
    echo '<table style="border-collapse:collapse;width:100%;font-size:13px">';
    echo '<tr style="background:#f3f4f6;border-bottom:1px solid #e5e7eb">';
    echo '<th style="padding:8px;text-align:left;font-weight:600">Rider ID</th>';
    echo '<th style="padding:8px;text-align:left;font-weight:600">Week Total</th>';
    echo '<th style="padding:8px;text-align:left;font-weight:600">Daily Avg</th>';
    echo '<th style="padding:8px;text-align:left;font-weight:600">Per Order</th>';
    echo '<th style="padding:8px;text-align:left;font-weight:600">Orders</th>';
    echo '</tr>';
    
    $sample = $pdo->query("SELECT rider_id, total_amount, daily_avg, per_order_avg, total_orders FROM rider_weekly_earnings WHERE week_start = '$ws' ORDER BY rider_id LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach($sample as $row){
        echo '<tr style="border-bottom:1px solid #e5e7eb">';
        echo '<td style="padding:8px">' . (int)$row['rider_id'] . '</td>';
        echo '<td style="padding:8px">$' . number_format((float)$row['total_amount'], 2) . '</td>';
        echo '<td style="padding:8px">$' . number_format((float)$row['daily_avg'], 2) . '</td>';
        echo '<td style="padding:8px">$' . number_format((float)$row['per_order_avg'], 2) . '</td>';
        echo '<td style="padding:8px">' . (int)$row['total_orders'] . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    echo '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #e5e7eb">';
    echo '<a href="backfill_rider_accounts.php" style="padding:8px 16px;background:#2563eb;color:white;text-decoration:none;border-radius:6px;display:inline-block;margin-right:8px">→ Backfill Accounts</a>';
    echo '<a href="rider_panel.php" style="padding:8px 16px;background:#6b7280;color:white;text-decoration:none;border-radius:6px;display:inline-block">Back to Panel</a>';
    echo '</div>';
    
    echo '</div>';
    exit;
}catch(Exception $e){
    echo '<div class="p-3 text-danger">Failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
    exit;
}

?>
