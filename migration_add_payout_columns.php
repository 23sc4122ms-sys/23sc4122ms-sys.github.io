<?php
// migration_add_payout_columns.php
// Run this script from the project root to add missing payout columns and backfill values.
require_once __DIR__ . '/db.php';

$pdo = getPDO();

echo "Starting payout columns migration...\n";

function col_exists(PDO $pdo, $table, $col){
    try{
        $sth = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $sth->execute([':t'=>$table, ':c'=>$col]);
        return ((int)$sth->fetchColumn()) > 0;
    }catch(Exception $e){ return false; }
}

// 1) payouts.status
if(!col_exists($pdo,'payouts','status')){
    try{
        echo "Adding column payouts.status...\n";
        $pdo->exec("ALTER TABLE payouts ADD COLUMN status VARCHAR(32) DEFAULT 'pending'");
        echo "Added payouts.status\n";
    }catch(Exception $e){ echo "Failed to add payouts.status: " . $e->getMessage() . "\n"; }
}else{ echo "payouts.status already exists\n"; }

// 2) payouts.admin_id
if(!col_exists($pdo,'payouts','admin_id')){
    try{
        echo "Adding column payouts.admin_id...\n";
        $pdo->exec("ALTER TABLE payouts ADD COLUMN admin_id INT DEFAULT NULL");
        echo "Added payouts.admin_id\n";
    }catch(Exception $e){ echo "Failed to add payouts.admin_id: " . $e->getMessage() . "\n"; }
}else{ echo "payouts.admin_id already exists\n"; }

// 3) payout_logs.admin_id
if(!col_exists($pdo,'payout_logs','admin_id')){
    try{
        echo "Adding column payout_logs.admin_id...\n";
        $pdo->exec("ALTER TABLE payout_logs ADD COLUMN admin_id INT DEFAULT NULL");
        echo "Added payout_logs.admin_id\n";
    }catch(Exception $e){ echo "Failed to add payout_logs.admin_id: " . $e->getMessage() . "\n"; }
}else{ echo "payout_logs.admin_id already exists\n"; }

// Backfill payouts.status from payment_status or paid_at where appropriate
try{
    // If payment_status column exists, map common values
    if(col_exists($pdo,'payouts','payment_status')){
        echo "Backfilling payouts.status from payment_status...\n";
        $pdo->exec("UPDATE payouts SET status = 'completed' WHERE payment_status IN ('paid','completed')");
        $pdo->exec("UPDATE payouts SET status = 'pending' WHERE payment_status IN ('pending','processing') OR payment_status IS NULL");
        echo "Backfilled from payment_status\n";
    }
    // Also ensure rows with paid_at set are marked completed
    if(col_exists($pdo,'payouts','paid_at')){
        echo "Marking payouts with paid_at IS NOT NULL as completed...\n";
        $pdo->exec("UPDATE payouts SET status = 'completed' WHERE paid_at IS NOT NULL");
        echo "Marked paid_at rows completed\n";
    }
}catch(Exception $e){ echo "Backfill failed: " . $e->getMessage() . "\n"; }

echo "Migration complete. Please verify your `payouts` and `payout_logs` tables in phpMyAdmin.\n";
echo "You may now re-run admin actions; the server-side code is compatible with either column names.\n";

?>
