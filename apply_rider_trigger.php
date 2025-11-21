<?php
// apply_rider_trigger.php - installs an AFTER INSERT trigger on rider_earnings
require_once __DIR__ . '/db.php';
$pdo = getPDO();
try{
    // Drop existing trigger if present
    $pdo->exec("DROP TRIGGER IF EXISTS trg_rider_earnings_after_insert");

    $sql = "CREATE TRIGGER trg_rider_earnings_after_insert
AFTER INSERT ON rider_earnings
FOR EACH ROW
BEGIN
  INSERT INTO rider_daily_earnings (rider_id, `date`, total_amount, created_at, updated_at)
    VALUES (NEW.rider_id, DATE(NEW.created_at), NEW.amount, NOW(), NOW())
    ON DUPLICATE KEY UPDATE total_amount = total_amount + VALUES(total_amount), updated_at = NOW();

  INSERT INTO rider_accounts (rider_id, total_earned, pending_amount, available_amount, total_earnings, last_updated)
    VALUES (NEW.rider_id, NEW.amount, 0, NEW.amount, NEW.amount, NOW())
    ON DUPLICATE KEY UPDATE
      total_earned = total_earned + VALUES(total_earned),
      available_amount = available_amount + VALUES(available_amount),
      total_earnings = total_earnings + VALUES(total_earnings),
      last_updated = NOW();
END";

    $pdo->exec($sql);
    echo "Trigger installed successfully\n";
}catch(Exception $e){
    echo "Failed to install trigger: " . $e->getMessage() . "\n";
    exit(1);
}
