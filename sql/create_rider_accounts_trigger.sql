-- create_rider_accounts_trigger.sql
-- Creates a trigger that updates rider_accounts and rider_daily_earnings when a new row is inserted into rider_earnings.

DELIMITER $$

DROP TRIGGER IF EXISTS trg_rider_earnings_after_insert$$

CREATE TRIGGER trg_rider_earnings_after_insert
AFTER INSERT ON rider_earnings
FOR EACH ROW
BEGIN
  -- Update rider_daily_earnings: add to today's total (or the date of created_at if you prefer)
  INSERT INTO rider_daily_earnings (rider_id, `date`, total_amount, created_at, updated_at)
    VALUES (NEW.rider_id, DATE(NEW.created_at), NEW.amount, NOW(), NOW())
    ON DUPLICATE KEY UPDATE total_amount = total_amount + VALUES(total_amount), updated_at = NOW();

  -- Update / upsert rider_accounts ledger
  INSERT INTO rider_accounts (rider_id, total_earned, pending_amount, available_amount, total_earnings, last_updated)
    VALUES (NEW.rider_id, NEW.amount, 0, NEW.amount, NEW.amount, NOW())
    ON DUPLICATE KEY UPDATE
      total_earned = total_earned + VALUES(total_earned),
      available_amount = available_amount + VALUES(available_amount),
      total_earnings = total_earnings + VALUES(total_earnings),
      last_updated = NOW();
END$$

DELIMITER ;
