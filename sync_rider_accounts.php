<?php
// sync_rider_accounts.php
// Admin-only script to recalculate rider_accounts totals and available_amount
// Usage: visit in browser while signed in as admin/owner, or run `php sync_rider_accounts.php` from CLI.

session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// basic admin check (adjust to your roles)
if(PHP_SAPI !== 'cli'){
    if(empty($_SESSION['user_id']) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
        http_response_code(403);
        echo "<h3>Forbidden</h3><p>Sign in as admin/owner to run this script.</p>";
        exit;
    }
}

set_time_limit(0);
try{
    // Build derived tables for earnings and pending per rider
    $earnSql = "SELECT rider_id, IFNULL(SUM(amount),0) AS total_earned FROM rider_earnings GROUP BY rider_id";

    // Detect whether `payouts` table has a `status` column; older installs may use paid_at NULL instead
    $hasStatus = false;
    try{
        $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payouts' AND COLUMN_NAME = 'status'");
        $chk->execute();
        $hasStatus = ((int)$chk->fetchColumn()) > 0;
    }catch(Exception $e){ $hasStatus = false; }

    if($hasStatus){
        $pendingSql = "SELECT rider_id, IFNULL(SUM(amount),0) AS pending_amount FROM payouts WHERE status IN ('pending','processing') GROUP BY rider_id";
    }else{
        // fallback for schemas without `status` column
        $pendingSql = "SELECT rider_id, IFNULL(SUM(amount),0) AS pending_amount FROM payouts WHERE paid_at IS NULL GROUP BY rider_id";
    }

    $earn = $pdo->query($earnSql)->fetchAll(PDO::FETCH_ASSOC);
    $pending = $pdo->query($pendingSql)->fetchAll(PDO::FETCH_ASSOC);

    $pendingMap = [];
    foreach($pending as $p){ $pendingMap[(int)$p['rider_id']] = (float)$p['pending_amount']; }

    $up = $pdo->prepare("INSERT INTO rider_accounts (rider_id, total_earned, pending_amount, available_amount, total_earnings, week_earn, last_updated)
        VALUES (:rid,:total,:pending,:avail,:totalearnings,:weekearn,NOW())
        ON DUPLICATE KEY UPDATE total_earned = VALUES(total_earned), pending_amount = VALUES(pending_amount), available_amount = VALUES(available_amount), total_earnings = VALUES(total_earnings), last_updated = NOW()");

    $count = 0; $rowsAffected = 0; $report = [];
    foreach($earn as $e){
        $rid = (int)$e['rider_id'];
        $totalEarned = (float)$e['total_earned'];
        $pendingAmt = $pendingMap[$rid] ?? 0.0;
        $avail = max(0.0, $totalEarned - $pendingAmt);
        // total_earnings mirror totalEarned here (preserve semantic if you separately track other sources)
        $up->execute([':rid'=>$rid, ':total'=>number_format($totalEarned,2,'.',''), ':pending'=>number_format($pendingAmt,2,'.',''), ':avail'=>number_format($avail,2,'.',''), ':totalearnings'=>number_format($totalEarned,2,'.',''), ':weekearn'=>0]);
        $count++;
        $report[] = ['rider_id'=>$rid,'total_earned'=>$totalEarned,'pending'=>$pendingAmt,'available'=>$avail];
    }

    // Also ensure riders with payouts or accounts but no rider_earnings are represented (set totals to 0)
    $extraRidersStmt = $pdo->query("SELECT DISTINCT p.rider_id FROM payouts p LEFT JOIN (SELECT rider_id FROM rider_earnings GROUP BY rider_id) re ON p.rider_id = re.rider_id WHERE re.rider_id IS NULL");
    $extra = $extraRidersStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($extra as $er){
        $rid=(int)$er['rider_id'];
        $pendingAmt = $pendingMap[$rid] ?? 0.0;
        $avail = max(0.0, 0.0 - $pendingAmt);
        $up->execute([':rid'=>$rid, ':total'=>number_format(0,2,'.',''), ':pending'=>number_format($pendingAmt,2,'.',''), ':avail'=>number_format($avail,2,'.',''), ':totalearnings'=>number_format(0,2,'.',''), ':weekearn'=>0]);
        $report[] = ['rider_id'=>$rid,'total_earned'=>0,'pending'=>$pendingAmt,'available'=>$avail];
    }

    // Optional: update any rows in rider_accounts not covered by above to have non-negative available_amount
    $fix = $pdo->prepare('UPDATE rider_accounts SET available_amount = GREATEST(0, available_amount), last_updated = NOW() WHERE available_amount < 0');
    $fix->execute();

    // Output
    if(PHP_SAPI === 'cli'){
        echo "Synced " . count($report) . " riders\n";
        foreach($report as $r) echo sprintf("rider %d: total=%.2f pending=%.2f available=%.2f\n", $r['rider_id'],$r['total_earned'],$r['pending'],$r['available']);
    }else{
        echo '<div style="font-family:system-ui;padding:16px">';
        echo '<h3>Sync Rider Accounts</h3>';
        echo '<div>Updated ' . count($report) . ' riders.</div>';
        echo '<pre>' . htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT)) . '</pre>';
        echo '<div><a href="rider_earnings.php">Back to Earnings</a></div>';
        echo '</div>';
    }
    exit;
}catch(Exception $e){
    if(PHP_SAPI === 'cli'){
        echo "Sync failed: " . $e->getMessage() . "\n";
    }else{
        http_response_code(500);
        echo '<div class="text-danger">Sync failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    exit;
}
