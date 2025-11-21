<?php
// migration_add_current_balance_column.php
// Run once as admin/owner to add current_balance column to rider_accounts.

session_start();
require_once __DIR__ . '/db.php';
$pdo = getPDO();

if(empty($_SESSION['user_id']) || !in_array(strtolower(($_SESSION['user_role'] ?? '')), ['admin','owner'])){
    http_response_code(403);
    echo "<h3>Forbidden</h3><div>Admin/Owner required.</div>";
    exit;
}

try {
    // Check if column already exists
    $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='rider_accounts' AND COLUMN_NAME='current_balance'");
    $chk->execute();
    $exists = ((int)$chk->fetchColumn()) > 0;
    if($exists){
        echo '<div style="font-family:system-ui;padding:16px"><h3>Migration: current_balance</h3><div>Column already exists. No action taken.</div></div>'; exit;
    }
    $pdo->exec("ALTER TABLE rider_accounts ADD COLUMN current_balance DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER total_earned");
    echo '<div style="font-family:system-ui;padding:16px"><h3>Migration: current_balance</h3><div>Column added successfully.</div></div>';
} catch(Exception $e){
    http_response_code(500);
    echo '<div style="font-family:system-ui;padding:16px"><h3>Migration Failed</h3><div>' . htmlspecialchars($e->getMessage()) . '</div></div>';
}
