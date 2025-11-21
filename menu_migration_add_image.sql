-- menu_migration_add_image.sql
-- Run this once if your `menu_items` table already exists to add the `image` column.
ALTER TABLE `menu_items` 
  ADD COLUMN IF NOT EXISTS `image` VARCHAR(255) DEFAULT NULL;
