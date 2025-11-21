-- schema_rider_earnings.sql
-- Comprehensive rider earnings system tracking delivery services, racing, content creation, and brand endorsements

USE `japan_food`;

-- ========== DELIVERY SERVICES ==========
-- Enhanced deliveries table with earnings tracking
ALTER TABLE `deliveries` ADD COLUMN `base_pay` DECIMAL(10,2) DEFAULT 0 COMMENT 'Base delivery fee';
ALTER TABLE `deliveries` ADD COLUMN `bonus` DECIMAL(10,2) DEFAULT 0 COMMENT 'Performance bonus';
ALTER TABLE `deliveries` ADD COLUMN `tip` DECIMAL(10,2) DEFAULT 0 COMMENT 'Customer tip';
ALTER TABLE `deliveries` ADD COLUMN `amount` DECIMAL(10,2) DEFAULT 0 COMMENT 'Total earnings for this delivery';
ALTER TABLE `deliveries` ADD COLUMN `paid` TINYINT DEFAULT 0 COMMENT '1=paid to rider, 0=pending';
ALTER TABLE `deliveries` ADD COLUMN `paid_at` DATETIME DEFAULT NULL;
ALTER TABLE `deliveries` ADD COLUMN `payout_id` INT DEFAULT NULL COMMENT 'FK to payouts table';

-- ========== PROFESSIONAL RACING ==========
-- Track racing events and earnings
CREATE TABLE IF NOT EXISTS `racing_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_name` VARCHAR(255) NOT NULL,
  `event_date` DATE NOT NULL,
  `location` VARCHAR(255),
  `race_type` ENUM('track','street','time-trial','endurance') NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(`event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `racing_participations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rider_id` INT NOT NULL,
  `racing_event_id` INT NOT NULL,
  `position` INT,
  `time_seconds` INT,
  `distance_km` DECIMAL(8,2),
  `earnings` DECIMAL(10,2) DEFAULT 0,
  `bonus_reason` VARCHAR(255),
  `paid` TINYINT DEFAULT 0,
  `paid_at` DATETIME DEFAULT NULL,
  `payout_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`racing_event_id`) REFERENCES `racing_events`(`id`) ON DELETE CASCADE,
  INDEX(`rider_id`),
  INDEX(`paid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== CONTENT CREATION ==========
-- Track content (videos, posts, reviews, etc.) and associated earnings
CREATE TABLE IF NOT EXISTS `content_types` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `type_name` VARCHAR(100) NOT NULL UNIQUE COMMENT 'e.g., video, blog, photo, review, story',
  `base_rate` DECIMAL(10,2) DEFAULT 0 COMMENT 'Base payment per content piece',
  `description` TEXT,
  `active` TINYINT DEFAULT 1,
  INDEX(`type_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rider_content` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rider_id` INT NOT NULL,
  `content_type_id` INT NOT NULL,
  `title` VARCHAR(255),
  `description` TEXT,
  `platform` VARCHAR(100) COMMENT 'e.g., YouTube, TikTok, Instagram, Blog',
  `url` VARCHAR(500),
  `views` INT DEFAULT 0,
  `likes` INT DEFAULT 0,
  `shares` INT DEFAULT 0,
  `engagement_rate` DECIMAL(5,2),
  `base_payment` DECIMAL(10,2) DEFAULT 0,
  `engagement_bonus` DECIMAL(10,2) DEFAULT 0 COMMENT 'Bonus based on views/likes',
  `total_earnings` DECIMAL(10,2) DEFAULT 0,
  `status` ENUM('draft','submitted','approved','published','rejected') DEFAULT 'draft',
  `approved_by` INT COMMENT 'FK to users (admin)',
  `approval_notes` TEXT,
  `paid` TINYINT DEFAULT 0,
  `paid_at` DATETIME DEFAULT NULL,
  `payout_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `approved_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`content_type_id`) REFERENCES `content_types`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX(`rider_id`),
  INDEX(`status`),
  INDEX(`paid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== BRAND ENDORSEMENTS ==========
-- Track brand partnerships, endorsements, and associated payments
CREATE TABLE IF NOT EXISTS `brands` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `brand_name` VARCHAR(255) NOT NULL,
  `industry` VARCHAR(100),
  `website` VARCHAR(255),
  `contact_email` VARCHAR(255),
  `commission_rate` DECIMAL(5,2) COMMENT 'Commission % for sales driven',
  `active` TINYINT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(`brand_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `endorsement_deals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rider_id` INT NOT NULL,
  `brand_id` INT NOT NULL,
  `deal_type` ENUM('fixed','commission','hybrid') COMMENT 'fixed=flat fee, commission=per-sale, hybrid=both',
  `fixed_fee` DECIMAL(10,2) DEFAULT 0 COMMENT 'One-time or monthly flat fee',
  `commission_percent` DECIMAL(5,2) DEFAULT 0,
  `min_monthly` DECIMAL(10,2) DEFAULT 0 COMMENT 'Minimum monthly guarantee',
  `start_date` DATE NOT NULL,
  `end_date` DATE,
  `description` TEXT,
  `status` ENUM('active','suspended','completed','pending') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`brand_id`) REFERENCES `brands`(`id`) ON DELETE CASCADE,
  INDEX(`rider_id`),
  INDEX(`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `endorsement_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `endorsement_deal_id` INT NOT NULL,
  `transaction_date` DATE,
  `transaction_type` ENUM('sale','impressions','clicks','fixed_payout') DEFAULT 'sale',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `quantity` INT DEFAULT 1 COMMENT 'e.g., number of items sold',
  `reference` VARCHAR(255) COMMENT 'e.g., order ID, campaign ID',
  `paid` TINYINT DEFAULT 0,
  `paid_at` DATETIME DEFAULT NULL,
  `payout_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`endorsement_deal_id`) REFERENCES `endorsement_deals`(`id`) ON DELETE CASCADE,
  INDEX(`paid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== CENTRALIZED PAYOUT SYSTEM ==========
-- All rider earnings aggregated and paid through this table
CREATE TABLE IF NOT EXISTS `payouts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rider_id` INT NOT NULL,
  `payout_period_start` DATE NOT NULL,
  `payout_period_end` DATE NOT NULL,
  `delivery_earnings` DECIMAL(12,2) DEFAULT 0,
  `racing_earnings` DECIMAL(12,2) DEFAULT 0,
  `content_earnings` DECIMAL(12,2) DEFAULT 0,
  `endorsement_earnings` DECIMAL(12,2) DEFAULT 0,
  `total_earnings` DECIMAL(12,2) DEFAULT 0,
  `deductions` DECIMAL(12,2) DEFAULT 0 COMMENT 'e.g., penalties, fees',
  `net_payout` DECIMAL(12,2) NOT NULL,
  `payment_method` ENUM('bank_transfer','card','check','cryptocurrency') DEFAULT 'bank_transfer',
  `bank_id` INT COMMENT 'FK to bank_info table if exists',
  `payment_status` ENUM('pending','processing','completed','failed','cancelled') DEFAULT 'pending',
  `payment_reference` VARCHAR(255) COMMENT 'Transaction ID from payment provider',
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`rider_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX(`rider_id`),
  INDEX(`payment_status`),
  INDEX(`payout_period_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== PAYOUT HISTORY & AUDIT ==========
-- Track all payout-related activities for audit trail
CREATE TABLE IF NOT EXISTS `payout_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `payout_id` INT NOT NULL,
  `action` VARCHAR(50) COMMENT 'created, processed, failed, cancelled, reversed',
  `old_value` TEXT COMMENT 'JSON: previous state',
  `new_value` TEXT COMMENT 'JSON: new state',
  `performed_by` INT COMMENT 'FK to users (admin)',
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`payout_id`) REFERENCES `payouts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`performed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX(`payout_id`),
  INDEX(`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== PAYOUT SETTINGS & RULES ==========
-- Configure automatic payout thresholds and rules
CREATE TABLE IF NOT EXISTS `payout_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) UNIQUE,
  `setting_value` VARCHAR(500),
  `description` TEXT,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default payout settings
INSERT IGNORE INTO `payout_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('auto_payout_enabled', '1', 'Enable automatic payouts'),
  ('payout_frequency', 'weekly', 'Frequency: weekly, biweekly, monthly'),
  ('payout_threshold', '50', 'Minimum earnings before payout (in currency units)'),
  ('payout_day_of_week', '5', 'Day of week for payouts (0=Sunday, 5=Friday)'),
  ('payout_day_of_month', '1', 'Day of month for monthly payouts'),
  ('delivery_base_rate', '5.00', 'Base rate per delivery'),
  ('delivery_distance_rate', '0.50', 'Rate per km'),
  ('racing_1st_place', '100', 'Prize for 1st place in racing'),
  ('racing_2nd_place', '75', 'Prize for 2nd place in racing'),
  ('racing_3rd_place', '50', 'Prize for 3rd place in racing'),
  ('content_video_base', '25', 'Base rate for video content'),
  ('content_post_base', '10', 'Base rate for blog/text post'),
  ('content_photo_base', '5', 'Base rate for photo content'),
  ('endorsement_default_commission', '5', 'Default commission % for endorsement sales');

-- Insert sample content types
INSERT IGNORE INTO `content_types` (`type_name`, `base_rate`, `description`) VALUES
  ('video', 25.00, 'Video content (YouTube, TikTok, etc.)'),
  ('blog', 10.00, 'Blog post or article'),
  ('photo', 5.00, 'Photo content'),
  ('review', 15.00, 'Product or service review'),
  ('social_story', 8.00, 'Social media story'),
  ('livestream', 30.00, 'Livestream session'),
  ('podcast', 20.00, 'Podcast episode');

