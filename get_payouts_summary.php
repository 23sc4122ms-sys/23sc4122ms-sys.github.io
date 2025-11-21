<?php
// get_payouts_summary.php - returns JSON summary of payouts for the logged-in rider
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
// buffer any stray output so client always receives valid JSON
ob_start();
function send_json_summary($arr){
    $buf = '';
    try{ $buf = ob_get_clean(); }catch(Exception $e){ $buf = ''; }
    if($buf !== ''){
        try{ $logDir = __DIR__ . '/storage'; if(!is_dir($logDir)) @mkdir($logDir,0755,true); @file_put_contents($logDir . '/debug_payouts.log', json_encode(['ts'=>date('c'),'event'=>'stray_output_get_payouts_summary','output'=>substr($buf,0,2000)]) . "\n", FILE_APPEND | LOCK_EX); }catch(Exception $ee){}
    }
    echo json_encode($arr);
    exit;
}

$pdo = getPDO();

if(empty($_SESSION['user_id']) || strtolower(($_SESSION['user_role'] ?? '')) !== 'rider'){
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}
$rid = (int)$_SESSION['user_id'];

try{
    // Fetch recent payout (schema-resilient)
    try{
        $hasStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status');
        $hasRequested = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','requested_at');
        $hasPaidAt = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','paid_at');
    }catch(Exception $e){ $hasStatus = $hasRequested = $hasPaidAt = false; }
    $statusSel = $hasStatus ? 'status' : 'NULL AS status';
    $requestedSel = $hasRequested ? 'DATE(requested_at) as requested_date' : 'DATE(created_at) as requested_date';
    $paidSel = $hasPaidAt ? 'DATE(paid_at) as paid_date' : 'NULL AS paid_date';
    $recentSql = "SELECT id, amount, " . $statusSel . ", " . $paidSel . ", " . $requestedSel . 
                 " FROM payouts WHERE rider_id = :rid ORDER BY " . ($hasRequested ? 'requested_at' : 'created_at') . " DESC LIMIT 1";
    $recentStmt = $pdo->prepare($recentSql);
    $recentStmt->execute([':rid'=>$rid]);
    $recent = $recentStmt->fetch(PDO::FETCH_ASSOC);

    // Primary ledger row
    $accStmt = $pdo->prepare('SELECT total_earned, pending_amount, available_amount FROM rider_accounts WHERE rider_id = :rid LIMIT 1');
    $accStmt->execute([':rid'=>$rid]);
    $acc = $accStmt->fetch(PDO::FETCH_ASSOC);

    // Determine pending payouts in a schema-resilient way (try status-based, fall back safely)
    try{
        $hasStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status');
    }catch(Exception $e){ $hasStatus = false; }
    if($hasStatus){
        try{
            $pendingPayoutStmt = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS total, COUNT(*) AS cnt FROM payouts WHERE rider_id = :rid AND status IN ('pending','processing')");
            $pendingPayoutStmt->execute([':rid'=>$rid]);
            $p = $pendingPayoutStmt->fetch(PDO::FETCH_ASSOC);
            $pendingAmt = (float)($p['total'] ?? 0);
            $pendingCount = (int)($p['cnt'] ?? 0);
        }catch(PDOException $pe){
            // fall back to simple aggregate if status query fails
            $pendingPayoutStmt = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS total, COUNT(*) AS cnt FROM payouts WHERE rider_id = :rid");
            $pendingPayoutStmt->execute([':rid'=>$rid]);
            $p = $pendingPayoutStmt->fetch(PDO::FETCH_ASSOC);
            $pendingAmt = (float)($p['total'] ?? 0);
            $pendingCount = (int)($p['cnt'] ?? 0);
        }
    }else{
        $pendingPayoutStmt = $pdo->prepare("SELECT IFNULL(SUM(amount),0) AS total, COUNT(*) AS cnt FROM payouts WHERE rider_id = :rid");
        $pendingPayoutStmt->execute([':rid'=>$rid]);
        $p = $pendingPayoutStmt->fetch(PDO::FETCH_ASSOC);
        $pendingAmt = (float)($p['total'] ?? 0);
        $pendingCount = (int)($p['cnt'] ?? 0);
    }

    if($acc){
        // Available Balance must rely directly on available_amount column
        $available = (float)$acc['available_amount'];
        if($available < 0) $available = 0.0;
        $computed_from = 'ledger.available_amount';
        $te = isset($acc['total_earned']) ? (float)$acc['total_earned'] : 0.0;
        // Sanity: compute historical expected available (earnings - pending payouts) to detect desync
        $earnStmt2 = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id = :rid');
        $earnStmt2->execute([':rid'=>$rid]);
        $historicalEarned = (float)$earnStmt2->fetchColumn();
        $expectedFromHistory = max(0.0, $historicalEarned - $pendingAmt);
        // Compute total payouts amount for this rider by summing `amount` in `payouts`
        // This is deterministic and ensures the UI always reflects payouts.amount totals.
        $total_payouts = 0.00;
        try{
            $tp = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id = :rid');
            $tp->execute([':rid' => $rid]);
            $total_payouts = (float)$tp->fetchColumn();
        }catch(Exception $e){
            $total_payouts = 0.00;
        }
        // If ledger available is zero but historical expected positive with no pending, derive from history
        if($available === 0.0 && $expectedFromHistory > 0 && $pendingAmt <= 0){
            $available = $expectedFromHistory;
            try{ $pdo->prepare('UPDATE rider_accounts SET available_amount = :v WHERE rider_id = :rid')->execute([':v'=>$available, ':rid'=>$rid]); }catch(Exception $se){}
            $computed_from = 'derived.history_fallback';
        }
        // If ledger available differs significantly from expected and there are no pending payouts, auto-correct (tolerance 0.01)
        if($pendingAmt <= 0 && abs($available - $expectedFromHistory) > 0.01){
            try{ $pdo->prepare('UPDATE rider_accounts SET available_amount = :v WHERE rider_id = :rid')->execute([':v'=>$expectedFromHistory, ':rid'=>$rid]); $available = $expectedFromHistory; $computed_from = 'auto_corrected.history'; }catch(Exception $se){}
        }
    } else {
        // No ledger row: compute earned total minus pending payouts
        $earnStmt = $pdo->prepare('SELECT IFNULL(SUM(amount),0) FROM rider_earnings WHERE rider_id = :rid');
        $earnStmt->execute([':rid'=>$rid]);
        $earned = (float)$earnStmt->fetchColumn();
        if($earned <= 0){
            // fallback to deliveries components
            $delStmt = $pdo->prepare("SELECT IFNULL(SUM(IFNULL(NULLIF(amount,0),(IFNULL(base_pay,0)+IFNULL(bonus,0)+IFNULL(tip,0)+IFNULL(fee,0)))),0) FROM deliveries WHERE rider_id = :rid");
            $delStmt->execute([':rid'=>$rid]);
            $earned = (float)$delStmt->fetchColumn();
        }
        $available = max(0, $earned - $pendingAmt);
        $computed_from = 'computed.deliveries_or_history';
    }

        // Return numeric values for amounts (not formatted strings) so frontend can parse reliably
        $out = [
            'ok'=>true,
            'recent'=>$recent ?: null,
            'pending'=>['total'=>round($pendingAmt,2),'count'=>$pendingCount],
            'total_earned'=> $acc ? round((float)$acc['total_earned'],2) : null,
            // Fixed: expose both raw ledger available_amount and resolved available used for eligibility
            'available_amount'=> $acc ? round((float)$acc['available_amount'],2) : null,
            'available'=>round($available,2),
            'can_cashout'=> $available >= 10.0,
            'total_payouts' => isset($total_payouts) ? round((float)$total_payouts,2) : 0.00,
        ];
    if(isset($_GET['debug']) && $_GET['debug']){
        $out['debug'] = [
            'available_raw' => $available,
            'pending_raw' => $pendingAmt,
            'total_earned' => isset($acc['total_earned']) ? (float)$acc['total_earned'] : null,
            'available_amount_before_correction' => isset($acc['available_amount']) ? (float)$acc['available_amount'] : null,
            'historical_expected_available' => isset($expectedFromHistory) ? $expectedFromHistory : null,
            'computed_from' => $computed_from ?? (isset($acc) ? 'ledger' : 'history'),
        ];
    }
    send_json_summary($out);
}catch(Exception $e){
    send_json_summary(['ok'=>false,'error'=>'Server error']);
}

?>
