-- SQL Migration: Add base_pay and total_earnings columns to riders_account table
-- Run this in phpMyAdmin or MySQL CLI if needed

USE `japan_food`;

-- Add new columns if they don't already exist
ALTER TABLE rider_accounts ADD COLUMN IF NOT EXISTS base_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Hourly wage × hours_per_week × weeks_per_year';
ALTER TABLE rider_accounts ADD COLUMN IF NOT EXISTS total_earnings DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of all earnings from deliveries';

-- Populate total_earnings from deliveries table (sum of amounts or component totals)
UPDATE rider_accounts ra
SET total_earnings = IFNULL((
  SELECT IFNULL(SUM(IFNULL(NULLIF(amount,0),(IFNULL(base_pay,0)+IFNULL(bonus,0)+IFNULL(tip,0)+IFNULL(fee,0)))),0)
  FROM deliveries d
  WHERE d.rider_id = ra.rider_id
), 0),
last_updated = NOW();

-- Note: base_pay requires manual configuration or a rider_profiles table with hourly_rate
-- Example formula: base_pay = $20/hour × 40 hours/week × 52 weeks/year = $41,600/year ÷ 52 = $800/week
-- You can manually update base_pay values or create a rider_profiles table with:
--   CREATE TABLE rider_profiles (
--     rider_id INT PRIMARY KEY,
--     hourly_rate DECIMAL(10,2) DEFAULT 20.00,
--     hours_per_week INT DEFAULT 40,
--     weeks_per_year INT DEFAULT 52,
--     FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
--   );
-- Then: UPDATE rider_accounts SET base_pay = (hourly_rate * hours_per_week * weeks_per_year / 52) FROM rider_profiles ...

-- Verify the update
SELECT rider_id, total_earned, total_earnings, base_pay, available_amount, last_updated
FROM rider_accounts
LIMIT 10;
