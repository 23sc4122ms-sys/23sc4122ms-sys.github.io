-- menu_schema.sql
-- Run this in your MySQL (phpMyAdmin or mysql CLI) to create the `menu_items` table
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
  `availability` VARCHAR(50) NOT NULL DEFAULT 'Available',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example seed
INSERT INTO `menu_items` (`name`,`category`,`price`,`availability`) VALUES
('Margherita Pizza','Pizza',12.00,'Available'),
('California Roll','Sushi',15.50,'Available'),
('Ramen','Noodles',10.00,'Limited');
