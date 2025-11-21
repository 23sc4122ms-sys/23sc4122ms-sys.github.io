-- insert_sample_delivery.sql
-- Replace RID with the rider id you want to test (e.g., 123)
-- Run this in phpMyAdmin or MySQL shell

SET @RID = 123;
INSERT INTO deliveries (order_id, rider_id, status, assigned_at, picked_up_at, delivered_at, completed_at, amount, base_pay, fee, created_at, updated_at)
VALUES (999999, @RID, 'delivered', NOW() - INTERVAL 2 HOUR, NOW() - INTERVAL 90 MINUTE, NOW() - INTERVAL 60 MINUTE, NOW() - INTERVAL 60 MINUTE, 15.50, 5.00, 0.00, NOW(), NOW());

-- Optional: Insert a pending payout to test available computation
-- INSERT INTO payouts (rider_id, amount, status, method, requested_at, created_at) VALUES (@RID, 5.00, 'pending', 'direct_deposit', NOW(), NOW());
