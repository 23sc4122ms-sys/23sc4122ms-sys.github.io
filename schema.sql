-- schema.sql
-- Run this in MySQL / phpMyAdmin to create the database and users table for JapanFoodOrder

CREATE DATABASE IF NOT EXISTS `japan_food` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `japan_food`;

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('owner','admin','rider','customer') NOT NULL DEFAULT 'customer',
  `status` ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: create a default owner/admin (replace password hash as needed)
-- INSERT INTO `users` (name,email,password,role) VALUES ('Owner','owner@example.com','<password_hash_here>','owner');
