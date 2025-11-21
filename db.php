<?php
// db.php - simple PDO connection helper
// Adjust the DSN, user and password to match your local setup.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'japan_food');
define('DB_USER', 'root');
define('DB_PASS', '');

// Minimum cashout amount (riders cannot request payouts below this)
define('CASHOUT_MIN', 10.00);

function getPDO(){
    static $pdo = null;
    if($pdo) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try{
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Ensure common optional columns exist so queries referencing them don't fail
        try{
            $pdo->exec("ALTER TABLE deliveries ADD COLUMN IF NOT EXISTS delivered_at DATETIME DEFAULT NULL");
        }catch(Exception $e){ /* ignore if table doesn't exist or cannot alter */ }
        // Ensure orders table has status and common timestamps so status updates succeed
        try{
            $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT NULL");
            $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS accepted_at DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS completed_at DATETIME DEFAULT NULL");
            $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS cancelled_at DATETIME DEFAULT NULL");
        }catch(Exception $e){ /* ignore if orders table doesn't exist or cannot alter */ }
        // Create completed_orders table (snapshot of completed orders)
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS completed_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                rider_id INT DEFAULT NULL,
                completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_by INT DEFAULT NULL,
                rider_fee DECIMAL(10,2) DEFAULT 0.00,
                snapshot TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(order_id), INDEX(rider_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $e){ /* ignore if cannot create */ }
        // Ensure rider_fee exists for older installs
        try{
            $pdo->exec("ALTER TABLE completed_orders ADD COLUMN IF NOT EXISTS rider_fee DECIMAL(10,2) DEFAULT 0.00");
        }catch(Exception $e){ /* ignore */ }
        // Create rider_earnings table to store per-order/per-delivery payouts
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS rider_earnings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rider_id INT NOT NULL,
                order_id INT DEFAULT NULL,
                delivery_id INT DEFAULT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                source VARCHAR(64) DEFAULT 'system',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(rider_id), INDEX(order_id), INDEX(delivery_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $e){ /* ignore */ }

        // Create rider_daily_earnings table to track per-rider daily totals
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS rider_daily_earnings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rider_id INT NOT NULL,
                `date` DATE NOT NULL,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_rider_date (rider_id, `date`),
                INDEX(rider_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            // attempt to add FK to users table if present
            try{ $pdo->exec("ALTER TABLE rider_daily_earnings ADD CONSTRAINT IF NOT EXISTS fk_rde_rider FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE"); }catch(Exception $e){ /* ignore if users table missing or FK not supported */ }
        }catch(Exception $e){ /* ignore */ }

        // Create payouts table to track rider cashout requests
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS payouts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rider_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                method VARCHAR(64) DEFAULT 'direct_deposit',
                account_info TEXT DEFAULT NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at DATETIME DEFAULT NULL,
                admin_id INT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX(rider_id), INDEX(status), INDEX(requested_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $e){ /* ignore */ }

        // Create payout_logs table to record actions taken on payouts
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS payout_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                payout_id INT NOT NULL,
                action VARCHAR(64) NOT NULL,
                admin_id INT DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(payout_id), INDEX(action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $e){ /* ignore */ }

        // Create rider_accounts table to keep a quick ledger of balances for cashouts
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS rider_accounts (
                rider_id INT PRIMARY KEY,
                total_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                available_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                base_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Hourly wage × hours_per_week × weeks_per_year',
                total_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of all earnings from rider_earnings/deliveries',
                week_earn DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of rider_earnings for current week (resets weekly)',
                last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $e){ /* ignore */ }
        
        // Ensure base_pay and total_earnings columns exist for older installations
        try{
            // NOTE: `base_pay` column may be removed intentionally. Commented out to avoid recreating it.
            // $pdo->exec("ALTER TABLE rider_accounts ADD COLUMN IF NOT EXISTS base_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Hourly wage × hours_per_week × weeks_per_year'");
            $pdo->exec("ALTER TABLE rider_accounts ADD COLUMN IF NOT EXISTS total_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of all earnings from deliveries'");
            $pdo->exec("ALTER TABLE rider_accounts ADD COLUMN IF NOT EXISTS week_earn DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of rider_earnings for current week (resets weekly)'");
            // total_payouts: persist sum of all payouts that were marked paid (monetary amount)
            $pdo->exec("ALTER TABLE rider_accounts ADD COLUMN IF NOT EXISTS total_payouts DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of all completed payouts' ");
        }catch(Exception $e){ /* ignore */ }

        // Create backfill log table to audit updates made by backfill scripts
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS rider_accounts_backfill_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rider_id INT NOT NULL,
                total_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                pending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                available_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                admin_id INT DEFAULT NULL,
                note TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX(rider_id), INDEX(admin_id), INDEX(created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $e){ /* ignore */ }

        // Create rider_weekly_earnings table to store weekly snapshots per rider
        try{
            $pdo->exec("CREATE TABLE IF NOT EXISTS rider_weekly_earnings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                rider_id INT NOT NULL,
                week_start DATE NOT NULL,
                week_end DATE NOT NULL,
                total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_orders INT NOT NULL DEFAULT 0,
                daily_avg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                per_order_avg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_rider_week (rider_id, week_start), INDEX(rider_id), INDEX(week_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }catch(Exception $e){ /* ignore */ }

        // Seed two sample rider_earnings entries if table is empty (one-time convenience)
        try{
            $cnt = (int)$pdo->query('SELECT COUNT(*) FROM rider_earnings')->fetchColumn();
            if($cnt === 0){
                $rows = $pdo->query('SELECT id, order_id, rider_id, rider_fee FROM completed_orders WHERE rider_id IS NOT NULL ORDER BY created_at DESC LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
                if($rows && count($rows)>0){
                    $ins = $pdo->prepare('INSERT INTO rider_earnings (rider_id, order_id, delivery_id, amount, source) VALUES (:rid,:oid,:did,:amt,:src)');
                    foreach($rows as $r){
                        $rid = (int)($r['rider_id'] ?? 0);
                        $oid = isset($r['order_id']) ? (int)$r['order_id'] : null;
                        // create a small random earning around existing rider_fee or a default
                        $base = isset($r['rider_fee']) ? (float)$r['rider_fee'] : 5.00;
                        $rand = round(($base * 0.5) + (mt_rand(0,1000)/1000.0 * $base),2);
                        $ins->execute([':rid'=>$rid, ':oid'=>$oid, ':did'=>null, ':amt'=>$rand, ':src'=>'seed']);
                    }
                }
            }
        }catch(Exception $e){ /* ignore seeding errors */ }
        return $pdo;
    }catch(PDOException $e){
        http_response_code(500);
        echo "Database connection failed: " . htmlspecialchars($e->getMessage());
        exit;
    }
}

/**
 * Check whether a given column exists on a table in the current database.
 * Returns true if the column exists, false otherwise.
 */
function schema_has_column(PDO $pdo, string $table, string $column): bool{
    try{
        $sth = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :col");
        $sth->execute([':table'=>$table, ':col'=>$column]);
        return ((int)$sth->fetchColumn()) > 0;
    }catch(Exception $e){
        return false;
    }
}
/**
 * Record a rider earning in `rider_earnings` if a similar entry doesn't already exist.
 * Returns the inserted id or false on failure/no-op.
 */
function record_rider_earning(PDO $pdo, int $riderId, ?int $orderId, ?int $deliveryId, float $amount, string $source = 'system'){
    if(!$riderId || $amount <= 0) return false;
    try{
        // avoid duplicate exact entries (same rider, order, delivery, amount)
        $q = 'SELECT id FROM rider_earnings WHERE rider_id = :rid AND COALESCE(order_id,0) = COALESCE(:oid,0) AND COALESCE(delivery_id,0) = COALESCE(:did,0) AND amount = :amt LIMIT 1';
        $sth = $pdo->prepare($q);
        $sth->execute([':rid'=>$riderId, ':oid'=>$orderId, ':did'=>$deliveryId, ':amt'=>$amount]);
        $exists = $sth->fetchColumn();
        if($exists) return false;

        $ins = $pdo->prepare('INSERT INTO rider_earnings (rider_id, order_id, delivery_id, amount, source) VALUES (:rid,:oid,:did,:amt,:src)');
        $ins->execute([':rid'=>$riderId, ':oid'=>$orderId, ':did'=>$deliveryId, ':amt'=>$amount, ':src'=>$source]);
        $lastId = (int)$pdo->lastInsertId();
        // also update daily totals (upsert into rider_daily_earnings)
        try{
            $d = date('Y-m-d');
            $up = $pdo->prepare("INSERT INTO rider_daily_earnings (rider_id, `date`, total_amount) VALUES (:rid,:d,:amt)
                ON DUPLICATE KEY UPDATE total_amount = total_amount + VALUES(total_amount), updated_at = NOW()");
            $up->execute([':rid'=>$riderId, ':d'=>$d, ':amt'=>$amount]);
        }catch(Exception $ee){ /* ignore daily update errors */ }
        // update rider_accounts ledger if present
        try{
            // Maintain running totals and a weekly sum (week_earn). If last_updated is from a previous ISO week, reset week_earn.
            $upd = $pdo->prepare("INSERT INTO rider_accounts (rider_id, total_earned, pending_amount, available_amount, total_earnings, week_earn, last_updated)
                VALUES (:rid, :amt, 0, :amt, :amt, :amt, NOW())
                ON DUPLICATE KEY UPDATE
                  total_earned = total_earned + VALUES(total_earned),
                  available_amount = available_amount + VALUES(available_amount),
                  total_earnings = total_earnings + VALUES(total_earnings),
                  week_earn = CASE
                    WHEN YEARWEEK(last_updated, 1) = YEARWEEK(CURDATE(), 1) THEN week_earn + VALUES(week_earn)
                    ELSE VALUES(week_earn)
                  END,
                  last_updated = NOW()");
            $upd->execute([':rid'=>$riderId, ':amt'=>$amount]);
        }catch(Exception $ee){ /* ignore ledger update errors */ }
        return $lastId;
    }catch(Exception $e){
        return false;
    }
}
