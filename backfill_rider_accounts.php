<?php
// backfill_rider_accounts.php
// Admin script to compute total earned per rider from rider_earnings,
// subtract pending payouts, and upsert into rider_accounts.

session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Basic admin check — adjust to your app's roles
if(empty($_SESSION['user_id']) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    http_response_code(403);
    echo "<h3>Forbidden</h3><div>Please sign in as admin to run this script.</div>";
    exit;
}

set_time_limit(0);
echo '<div style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;padding:16px">';
echo '<h3>Backfill Rider Accounts</h3>';
try{
    // Compute totals per rider from rider_earnings
    $sql = "SELECT rider_id, IFNULL(SUM(amount),0) AS total_earned FROM rider_earnings GROUP BY rider_id";
    $sth = $pdo->query($sql);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows){ echo '<div>No rider_earnings rows found.</div>'; echo '</div>'; exit; }

    // Determine current week range (Monday - Sunday)
    $dt = new DateTime('now');
    $dt->setISODate((int)$dt->format('o'), (int)$dt->format('W'), 1);
    $week_start = $dt->format('Y-m-d');
    $week_end = $dt->modify('+6 days')->format('Y-m-d');

    // Prepare upsert excluding removed base_pay column; maintain totals and week_earn
    $up = $pdo->prepare("INSERT INTO rider_accounts (rider_id, total_earned, current_balance, pending_amount, available_amount, total_earnings, week_earn, last_updated)
        VALUES (:rid,:tot,:current,:pending,:avail,:totalearnings,:weekearn,NOW())
        ON DUPLICATE KEY UPDATE total_earned = VALUES(total_earned), current_balance = VALUES(current_balance), pending_amount = VALUES(pending_amount), available_amount = VALUES(available_amount), total_earnings = VALUES(total_earnings), week_earn = VALUES(week_earn), last_updated = NOW()");

    // prepare pending lookup
    // Detect if payouts.status column exists; adapt pending query accordingly
    $hasStatus = false;
    try {
        $cChk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='payouts' AND COLUMN_NAME='status'");
        $cChk->execute();
        $hasStatus = ((int)$cChk->fetchColumn()) > 0;
    } catch(Exception $e){ $hasStatus = false; }
    $pendingSql = $hasStatus
        ? "SELECT rider_id, IFNULL(SUM(amount),0) AS pending FROM payouts WHERE status IN ('pending','processing') GROUP BY rider_id"
        : "SELECT rider_id, IFNULL(SUM(amount),0) AS pending FROM payouts WHERE paid_at IS NULL GROUP BY rider_id";
    $sthPending = $pdo->query($pendingSql);
    $pendingMap = [];
    foreach($sthPending->fetchAll(PDO::FETCH_ASSOC) as $p){ $pendingMap[(int)$p['rider_id']] = (float)$p['pending']; }

    // prepare total_earnings lookup from rider_earnings (sum of amount per rider)
    $sthEarnings = $pdo->query("SELECT rider_id, IFNULL(SUM(amount),0) AS total_earnings FROM rider_earnings GROUP BY rider_id");
    $earningsMap = [];
    foreach($sthEarnings->fetchAll(PDO::FETCH_ASSOC) as $d){ $earningsMap[(int)$d['rider_id']] = (float)$d['total_earnings']; }

    // prepare week_earn lookup (sum of amount for current week)
    $sthWeek = $pdo->prepare("SELECT rider_id, IFNULL(SUM(amount),0) AS week_earn FROM rider_earnings WHERE DATE(created_at) BETWEEN :ws AND :we GROUP BY rider_id");
    $sthWeek->execute([':ws'=>$week_start, ':we'=>$week_end]);
    $weekMap = [];
    foreach($sthWeek->fetchAll(PDO::FETCH_ASSOC) as $w){ $weekMap[(int)$w['rider_id']] = (float)$w['week_earn']; }

    $count = 0;
    $adminId = (int)$_SESSION['user_id'];
    $insLog = $pdo->prepare("INSERT INTO rider_accounts_backfill_log (rider_id, total_earned, pending_amount, available_amount, admin_id, note, created_at) VALUES (:rid,:tot,:pending,:avail,:aid,:note,NOW())");
    foreach($rows as $r){
        $rid = (int)$r['rider_id'];
        $tot = (float)$r['total_earned'];
        $pending = $pendingMap[$rid] ?? 0.0;
        $avail = max(0.0, $tot - $pending);
        
        $totalEarnings = $earningsMap[$rid] ?? 0.0;
        $weekEarn = $weekMap[$rid] ?? 0.0;
        
        $up->execute([':rid'=>$rid, ':tot'=>number_format($tot,2,'.',''), ':current'=>number_format($tot,2,'.',''), ':pending'=>number_format($pending,2,'.',''), ':avail'=>number_format($avail,2,'.',''), ':totalearnings'=>number_format($totalEarnings,2,'.',''), ':weekearn'=>number_format($weekEarn,2,'.','')]);
        // insert log row for this update
        try{ $insLog->execute([':rid'=>$rid, ':tot'=>number_format($tot,2,'.',''), ':pending'=>number_format($pending,2,'.',''), ':avail'=>number_format($avail,2,'.',''), ':aid'=>$adminId, ':note'=>'backfill_v2_with_earnings']); }catch(Exception $le){}
        $count++;
    }

    echo '<div>Updated rider_accounts for <strong>' . $count . '</strong> riders.</div>';
    echo '<div style="margin-top:8px;color:#0369a1"><strong>Note:</strong> week_earn reflects the current ISO week (Mon-Sun) sum from rider_earnings.</div>';

    // check for negative available_amounts
    $neg = $pdo->query("SELECT rider_id, available_amount FROM rider_accounts WHERE available_amount < 0")->fetchAll(PDO::FETCH_ASSOC);
    if($neg && count($neg) > 0){
        echo '<div style="margin-top:8px;color:#b45309">Found <strong>' . count($neg) . '</strong> negative available_amount entries.</div>';
        if(isset($_GET['fix']) && $_GET['fix'] == '1'){
            $fixStmt = $pdo->prepare('UPDATE rider_accounts SET available_amount = 0, last_updated = NOW() WHERE rider_id = :rid');
            foreach($neg as $n){
                try{ $fixStmt->execute([':rid'=>$n['rider_id']]); $insLog->execute([':rid'=>$n['rider_id'], ':tot'=>0, ':pending'=>0, ':avail'=>0, ':aid'=>$adminId, ':note'=>'fixed_negative']); }catch(Exception $fe){}
            }
            echo '<div style="color:#065f46">Fixed negative available_amount values to 0.</div>';
        } else {
            echo '<div style="color:#9a3412">Run with <code>?fix=1</code> to set them to 0.</div>';
        }
    }

    echo '<div style="margin-top:8px"><a href="get_payouts.php">Open Payouts</a> | <a href="rider_earnings.php">Back to Earnings</a> | <a href="backfill_logs.php">View Backfill Logs</a></div>';
    echo '</div>';
    exit;
}catch(Exception $e){
    echo '<div class="text-danger">Failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
    exit;
}

?>
