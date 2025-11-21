<?php
// confirm_payout.php - admin endpoint to mark a payout as paid
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$pdo = getPDO();

// Simple admin check - adjust according to your app's roles
if(empty($_SESSION['user_id']) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
}
$adminId = (int)$_SESSION['user_id'];

$id = isset($_POST['payout_id']) ? (int)$_POST['payout_id'] : 0;
if(!$id){ echo json_encode(['ok'=>false,'error'=>'Missing payout_id']); exit; }

try{
    // Be defensive about schema: some installs may not have status/paid_at/admin_id columns.
    try{ $hasStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status'); }catch(Exception $e){ $hasStatus = false; }
    try{ $hasPaymentStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','payment_status'); }catch(Exception $e){ $hasPaymentStatus = false; }
    try{ $hasPaidAt = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','paid_at'); }catch(Exception $e){ $hasPaidAt = false; }
    try{ $hasAdminId = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','admin_id'); }catch(Exception $e){ $hasAdminId = false; }
    try{ $hasUpdatedAt = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','updated_at'); }catch(Exception $e){ $hasUpdatedAt = false; }

    $pdo->beginTransaction();
    $selectCols = 'id, rider_id, amount';
    if($hasStatus) $selectCols .= ', status';
    if($hasPaymentStatus && !$hasStatus) $selectCols .= ', payment_status';
    if($hasPaidAt) $selectCols .= ', paid_at';
    $sth = $pdo->prepare("SELECT {$selectCols} FROM payouts WHERE id = :id FOR UPDATE");
    $sth->execute([':id'=>$id]);
    $p = $sth->fetch(PDO::FETCH_ASSOC);
    if(!$p){ $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'Not found']); exit; }

    // Determine if already paid (status, payment_status or paid_at)
    $alreadyPaid = false;
    if($hasStatus && isset($p['status'])){
        $s = strtolower(trim((string)$p['status']));
        if(in_array($s, ['paid','completed'], true)) $alreadyPaid = true;
    }
    if(!$alreadyPaid && $hasPaymentStatus && isset($p['payment_status'])){
        $ps = strtolower(trim((string)$p['payment_status']));
        if(in_array($ps, ['paid','completed','completed'], true)) $alreadyPaid = true;
    }
    if(!$alreadyPaid && $hasPaidAt && !empty($p['paid_at'])){
        $alreadyPaid = true;
    }
    if($alreadyPaid){ $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'Already paid']); exit; }

    // Build a safe UPDATE that only touches columns that exist on this schema
    $updParts = [];
    // Only include params that will actually be used in the SQL to avoid PDO binding mismatch
    $params = [':id'=>$id];
    if($hasStatus) $updParts[] = "status = 'paid'";
    // If the schema uses `payment_status` instead of `status`, update that too
    if(!$hasStatus && $hasPaymentStatus) $updParts[] = "payment_status = 'completed'";
    if($hasPaidAt) $updParts[] = 'paid_at = NOW()';
    if($hasAdminId){ $updParts[] = 'admin_id = :aid'; $params[':aid'] = $adminId; }
    if($hasUpdatedAt) $updParts[] = 'updated_at = NOW()';
    if(count($updParts) > 0){
        $updSql = 'UPDATE payouts SET ' . implode(', ', $updParts) . ' WHERE id = :id';
        $upd = $pdo->prepare($updSql);
        $upd->execute($params);
    }

    // Insert into payout_logs - be resilient to different schema column names (admin_id vs performed_by)
    $logCols = ['payout_id','action','notes','created_at'];
    $logPlaceholders = [':pid',':act',':notes','NOW()'];
    $logParams = [':pid'=>$id, ':act'=>'marked_paid', ':notes'=>'Marked as paid via admin panel'];
    // prefer admin_id if present, otherwise fallback to performed_by
    $hasLogAdmin = function_exists('schema_has_column') && schema_has_column($pdo,'payout_logs','admin_id');
    $hasLogPerformedBy = function_exists('schema_has_column') && schema_has_column($pdo,'payout_logs','performed_by');
    if($hasLogAdmin){
        // insert admin_id column
        array_splice($logCols, 2, 0, 'admin_id'); // insert before notes
        array_splice($logPlaceholders, 2, 0, ':aid');
        $logParams[':aid'] = $adminId;
    }elseif($hasLogPerformedBy){
        // older schema uses performed_by
        array_splice($logCols, 2, 0, 'performed_by');
        array_splice($logPlaceholders, 2, 0, ':by');
        $logParams[':by'] = $adminId;
    }
    $insSql = 'INSERT INTO payout_logs (' . implode(',', $logCols) . ') VALUES (' . implode(',', $logPlaceholders) . ')';
    $insLog = $pdo->prepare($insSql);
    $insLog->execute($logParams);

    // adjust rider_accounts if present: decrease pending_amount by payout amount
    // Also reduce total_earned to reflect the payout being paid out
    try{
        $sth2 = $pdo->prepare('SELECT rider_id, amount FROM payouts WHERE id = :id LIMIT 1');
        $sth2->execute([':id'=>$id]);
        $p = $sth2->fetch(PDO::FETCH_ASSOC);
        if($p){
            $rid = (int)$p['rider_id'];
            $amt = (float)$p['amount'];
            // Decrement pending and total_earned, and increment total_payouts if column exists
            if(function_exists('schema_has_column') && schema_has_column($pdo,'rider_accounts','total_payouts')){
                $updSql = "UPDATE rider_accounts SET pending_amount = GREATEST(0, pending_amount - :amt), total_earned = GREATEST(0, total_earned - :amt), total_payouts = total_payouts + :amt, last_updated = NOW() WHERE rider_id = :rid";
            }else{
                $updSql = "UPDATE rider_accounts SET pending_amount = GREATEST(0, pending_amount - :amt), total_earned = GREATEST(0, total_earned - :amt), last_updated = NOW() WHERE rider_id = :rid";
            }
            $updAcc = $pdo->prepare($updSql);
            $updAcc->execute([':amt'=>$amt, ':rid'=>$rid]);
        }
    }catch(Exception $e){ /* ignore missing table */ }

    $pdo->commit();
    // Recompute authoritative total_payouts for this rider from the payouts table and persist it
    try{
        // detect columns for completed/payed condition
        try{ $hasStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','status'); }catch(Exception $e){ $hasStatus = false; }
        try{ $hasPaymentStatus = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','payment_status'); }catch(Exception $e){ $hasPaymentStatus = false; }
        try{ $hasPaidAt = function_exists('schema_has_column') && schema_has_column($pdo,'payouts','paid_at'); }catch(Exception $e){ $hasPaidAt = false; }

        if(isset($rid) && $rid){
            $compWhere = '';
            if($hasStatus){
                $compWhere = "status IN ('paid','completed')";
            }elseif($hasPaymentStatus){
                $compWhere = "payment_status IN ('paid','completed')";
            }elseif($hasPaidAt){
                $compWhere = 'paid_at IS NOT NULL';
            }

            if($compWhere !== ''){
                $sumStmt = $pdo->prepare("SELECT IFNULL(SUM(amount),0) FROM payouts WHERE rider_id = :rid AND {$compWhere}");
                $sumStmt->execute([':rid'=>$rid]);
                $sumAmt = (float)$sumStmt->fetchColumn();
                if(function_exists('schema_has_column') && schema_has_column($pdo,'rider_accounts','total_payouts')){
                    try{
                        $updTot = $pdo->prepare('UPDATE rider_accounts SET total_payouts = :v, last_updated = NOW() WHERE rider_id = :rid');
                        $updTot->execute([':v'=>$sumAmt, ':rid'=>$rid]);
                    }catch(Exception $e){ /* ignore update failures */ }
                }
            }
        }
    }catch(Exception $e){ /* ignore recompute failures */ }

    echo json_encode(['ok'=>true,'payout_id'=>$id]);
    exit;
}catch(Exception $e){
    try{ $pdo->rollBack(); }catch(Exception $e2){}
    // Log exception for debugging
    try{
        $logDir = __DIR__ . '/storage'; if(!is_dir($logDir)) @mkdir($logDir,0755,true);
        @file_put_contents($logDir . '/debug_payouts.log', json_encode(['ts'=>date('c'),'event'=>'confirm_payout_error','payout_id'=>$id,'message'=>$e->getMessage(),'trace'=>substr($e->getTraceAsString(),0,2000)]) . "\n", FILE_APPEND | LOCK_EX);
    }catch(Exception $ee){}
    echo json_encode(['ok'=>false,'error'=>'Server error','message'=>$e->getMessage()]);
    exit;
}

?>
