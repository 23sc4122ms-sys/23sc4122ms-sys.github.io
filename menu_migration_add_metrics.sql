-- menu_migration_add_metrics.sql
-- Add buy_count and rating columns to menu_items
ALTER TABLE `menu_items`
  ADD COLUMN IF NOT EXISTS `buy_count` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `rating_total` INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `rating_count` INT NOT NULL DEFAULT 0;
