<?php
// one-time migration script: create/verify customer-related tables and ensure orders are user-aware
require_once __DIR__ . '/db.php';
$pdo = getPDO();

// Create/alter tables for per-user cart, ratings and make orders user-aware
$sql = <<<SQL
-- user_cart stores cart items per user (used when logged in)
CREATE TABLE IF NOT EXISTS user_cart (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  menu_item_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (menu_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- user_ratings stores ratings by user
CREATE TABLE IF NOT EXISTS user_ratings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  menu_item_id INT NOT NULL,
  rating TINYINT NOT NULL,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  INDEX (menu_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure orders table has a user_id column so orders can be associated with accounts
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(128) NOT NULL,
  user_id INT NULL,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure order_items exists (if not already created via other scripts)
CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  menu_item_id INT NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  quantity INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'processing',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

try {
    $pdo->exec($sql);
    // attempt to add foreign keys if `users` table exists (silently ignore errors)
    try {
        $pdo->exec("ALTER TABLE user_cart ADD CONSTRAINT fk_uc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (Exception $e) { /* ignore if exists or users table missing */ }
    try {
        $pdo->exec("ALTER TABLE user_ratings ADD CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (Exception $e) { /* ignore */ }
    try {
        $pdo->exec("ALTER TABLE orders ADD CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL");
    } catch (Exception $e) { /* ignore */ }

    echo "Tables created/verified successfully.\n";
} catch (Exception $e) {
    echo "Error creating tables: " . $e->getMessage();
}
