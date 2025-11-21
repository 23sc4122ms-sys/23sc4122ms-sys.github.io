<?php
// update_rider_base_pay.php
// Admin script to populate base_pay column in rider_accounts
// base_pay = hourly_wage × hours_per_week × weeks_per_year ÷ 52 (to get weekly average)

session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Admin check
if(empty($_SESSION['user_id']) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    http_response_code(403);
    echo "<h3>Forbidden</h3><div>Please sign in as admin to run this script.</div>";
    exit;
}

// If the `base_pay` column was removed, exit gracefully.
try{
    $colChk = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rider_accounts' AND COLUMN_NAME = 'base_pay'");
    $colChk->execute();
    $hasBasePay = (bool)$colChk->fetchColumn();
}catch(Exception $e){
    $hasBasePay = false;
}
if(!$hasBasePay){
    echo '<div style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:16px">';
    echo '<h3>Base Pay Column Missing</h3>';
    echo '<div style="color:#6b7280;padding:12px;background:#f8fafc;border:1px solid #eef2ff;border-radius:6px">The <strong>base_pay</strong> column does not exist in <code>rider_accounts</code>. This script is not required. If you removed the column intentionally, no action is needed.</div>';
    echo '<div style="margin-top:12px"><a href="backfill_rider_accounts.php">Run Backfill</a> | <a href="rider_earnings.php">View Earnings</a></div>';
    echo '</div>';
    exit;
}

set_time_limit(0);
echo '<div style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:16px">';
echo '<h3>Update Rider Base Pay</h3>';

// Get parameters
$hourly_rate = isset($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : (isset($_GET['hourly_rate']) ? (float)$_GET['hourly_rate'] : 20.00);
$hours_per_week = isset($_POST['hours_per_week']) ? (int)$_POST['hours_per_week'] : (isset($_GET['hours_per_week']) ? (int)$_GET['hours_per_week'] : 40);
$weeks_per_year = isset($_POST['weeks_per_year']) ? (int)$_POST['weeks_per_year'] : (isset($_GET['weeks_per_year']) ? (int)$_GET['weeks_per_year'] : 52);

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])){
    try{
        // Calculate base_pay for all riders: (hourly_rate × hours_per_week × weeks_per_year) / 52 weeks = weekly base pay
        $basePay = ($hourly_rate * $hours_per_week * $weeks_per_year) / 52;
        
        $up = $pdo->prepare("UPDATE rider_accounts SET base_pay = :base_pay, last_updated = NOW()");
        $up->execute([':base_pay' => number_format($basePay, 2, '.', '')]);
        
        echo '<div style="color:#065f46;background:#d1fae5;padding:12px;border-radius:6px;margin-bottom:16px">';
        echo '<strong>Success!</strong> Updated base_pay for all riders.<br>';
        echo 'Formula: ($' . number_format($hourly_rate, 2) . '/hr × ' . $hours_per_week . ' hrs/wk × ' . $weeks_per_year . ' wks/yr) ÷ 52 = <strong>$' . number_format($basePay, 2) . '/week</strong>';
        echo '</div>';
        
        // Show sample
        $sample = $pdo->query('SELECT rider_id, base_pay, total_earnings, available_amount FROM rider_accounts LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
        if($sample){
            echo '<div style="margin-top:16px"><h4>Sample Updated Rows:</h4>';
            echo '<table style="border-collapse:collapse;width:100%">';
            echo '<tr style="background:#f3f4f6;border-bottom:1px solid #e5e7eb"><th style="padding:8px;text-align:left">Rider ID</th><th style="padding:8px;text-align:left">Base Pay</th><th style="padding:8px;text-align:left">Total Earnings</th><th style="padding:8px;text-align:left">Available</th></tr>';
            foreach($sample as $row){
                echo '<tr style="border-bottom:1px solid #e5e7eb">';
                echo '<td style="padding:8px">' . (int)$row['rider_id'] . '</td>';
                echo '<td style="padding:8px">$' . number_format((float)$row['base_pay'], 2) . '</td>';
                echo '<td style="padding:8px">$' . number_format((float)$row['total_earnings'], 2) . '</td>';
                echo '<td style="padding:8px">$' . number_format((float)$row['available_amount'], 2) . '</td>';
                echo '</tr>';
            }
            echo '</table></div>';
        }
        
        echo '<div style="margin-top:16px"><a href="backfill_rider_accounts.php">Run Full Backfill</a> | <a href="rider_earnings.php">View Earnings</a></div>';
        echo '</div>';
        exit;
    }catch(Exception $e){
        echo '<div style="color:#991b1b;background:#fee2e2;padding:12px;border-radius:6px">';
        echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
        echo '</div>';
    }
}

// Show form
echo '<form method="POST" style="margin-top:16px;background:#f9fafb;padding:16px;border-radius:8px;border:1px solid #eef0f3">';
echo '<div style="margin-bottom:12px">';
echo '<label style="display:block;margin-bottom:4px;font-weight:600">Hourly Wage ($/hr):</label>';
echo '<input type="number" name="hourly_rate" value="' . number_format($hourly_rate, 2) . '" step="0.01" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px">';
echo '</div>';

echo '<div style="margin-bottom:12px">';
echo '<label style="display:block;margin-bottom:4px;font-weight:600">Hours per Week:</label>';
echo '<input type="number" name="hours_per_week" value="' . (int)$hours_per_week . '" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px">';
echo '</div>';

echo '<div style="margin-bottom:12px">';
echo '<label style="display:block;margin-bottom:4px;font-weight:600">Weeks per Year:</label>';
echo '<input type="number" name="weeks_per_year" value="' . (int)$weeks_per_year . '" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:14px">';
echo '</div>';

// Calculate preview
$preview = ($hourly_rate * $hours_per_week * $weeks_per_year) / 52;
echo '<div style="background:#e0e7ff;padding:12px;border-radius:6px;margin-bottom:16px">';
echo '<strong>Preview:</strong> ($' . number_format($hourly_rate, 2) . '/hr × ' . (int)$hours_per_week . ' hrs/wk × ' . (int)$weeks_per_year . ' wks/yr) ÷ 52 weeks = <strong>$' . number_format($preview, 2) . '/week base pay</strong>';
echo '</div>';

echo '<div>';
echo '<input type="hidden" name="confirm" value="1">';
echo '<button type="submit" style="background:#2563eb;color:white;padding:10px 20px;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:14px">Update All Riders</button>';
echo ' <a href="backfill_rider_accounts.php" style="padding:10px 20px;color:#2563eb;text-decoration:none;display:inline-block">Cancel</a>';
echo '</div>';
echo '</form>';

// Show current state
echo '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb">';
echo '<h4>Current Rider Accounts:</h4>';
$current = $pdo->query('SELECT rider_id, base_pay, total_earnings, available_amount FROM rider_accounts ORDER BY rider_id LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
if($current && count($current) > 0){
    echo '<table style="border-collapse:collapse;width:100%;margin-top:12px">';
    echo '<tr style="background:#f3f4f6;border-bottom:1px solid #e5e7eb"><th style="padding:8px;text-align:left">Rider ID</th><th style="padding:8px;text-align:left">Base Pay</th><th style="padding:8px;text-align:left">Total Earnings</th><th style="padding:8px;text-align:left">Available</th></tr>';
    foreach($current as $row){
        echo '<tr style="border-bottom:1px solid #e5e7eb">';
        echo '<td style="padding:8px">' . (int)$row['rider_id'] . '</td>';
        echo '<td style="padding:8px">$' . number_format((float)$row['base_pay'], 2) . '</td>';
        echo '<td style="padding:8px">$' . number_format((float)$row['total_earnings'], 2) . '</td>';
        echo '<td style="padding:8px">$' . number_format((float)$row['available_amount'], 2) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo '<div style="color:#6b7280;padding:12px">No rider accounts found yet. Run <strong>backfill_rider_accounts.php</strong> first.</div>';
}
echo '</div>';

echo '</div>';
?>
