<?php
/**
 * migration_ensure_payouts_and_view.php
 * Ensure the `payouts` table and a convenient `rider_cashouts` view exist.
 * Run from CLI or browser as an admin to create/repair the schema.
 */
require_once __DIR__ . '/db.php';
$pdo = getPDO();

try{
    // Create payouts table if it does not exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS payouts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rider_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(64) DEFAULT 'processing',
        method VARCHAR(64) DEFAULT 'direct_deposit',
        account_info TEXT DEFAULT NULL,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME DEFAULT NULL,
        admin_id INT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX(rider_id), INDEX(status), INDEX(requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Make sure important columns exist (for older installs)
    // Use information_schema checks to support older MySQL/MariaDB versions
    function col_exists($pdo, $table, $col){
        try{
            $sth = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $sth->execute([':t'=>$table, ':c'=>$col]);
            return ((int)$sth->fetchColumn()) > 0;
        }catch(Exception $e){ return false; }
    }
    if(!col_exists($pdo,'payouts','rider_id')){ try{ $pdo->exec("ALTER TABLE payouts ADD COLUMN rider_id INT NOT NULL"); }catch(Exception $e){} }
    if(!col_exists($pdo,'payouts','amount')){ try{ $pdo->exec("ALTER TABLE payouts ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0.00"); }catch(Exception $e){} }
    if(!col_exists($pdo,'payouts','status')){ try{ $pdo->exec("ALTER TABLE payouts ADD COLUMN status VARCHAR(64) DEFAULT 'processing'"); }catch(Exception $e){} }
    if(!col_exists($pdo,'payouts','method')){ try{ $pdo->exec("ALTER TABLE payouts ADD COLUMN method VARCHAR(64) DEFAULT 'direct_deposit'"); }catch(Exception $e){} }
    if(!col_exists($pdo,'payouts','requested_at')){ try{ $pdo->exec("ALTER TABLE payouts ADD COLUMN requested_at DATETIME DEFAULT CURRENT_TIMESTAMP"); }catch(Exception $e){} }
    if(!col_exists($pdo,'payouts','created_at')){ try{ $pdo->exec("ALTER TABLE payouts ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"); }catch(Exception $e){} }
    if(!col_exists($pdo,'payouts','paid_at')){ try{ $pdo->exec("ALTER TABLE payouts ADD COLUMN paid_at DATETIME DEFAULT NULL"); }catch(Exception $e){} }

    // Create or replace a view that exposes Requested, Payout ID, Amount, Status, Method and Rider ID
    // This view makes it easy to generate the table you requested in SQL queries or UI pages.
    $pdo->exec("CREATE OR REPLACE VIEW rider_cashouts AS
        SELECT
            DATE(COALESCE(requested_at, created_at)) AS requested,
            id AS payout_id,
            amount,
            COALESCE(status, 'processing') AS status,
            COALESCE(method, 'direct_deposit') AS method,
            rider_id
        FROM payouts");

    echo "OK: payouts table ensured and view 'rider_cashouts' created.\n";
}catch(Exception $e){
    http_response_code(500);
    echo "Error ensuring payouts/view: " . htmlspecialchars($e->getMessage()) . "\n";
    // Write to debug log as well
    try{ @file_put_contents(__DIR__ . '/storage/debug_payouts.log', json_encode(['ts'=>date('c'),'event'=>'migration_error','message'=>$e->getMessage()]) . "\n", FILE_APPEND | LOCK_EX); }catch(Exception $ee){}
}

?>
