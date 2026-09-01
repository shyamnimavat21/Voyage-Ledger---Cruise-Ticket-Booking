-- ============================================================
-- Voyage Ledger — Database Schema
-- Import this once via phpMyAdmin / mysql CLI before using api.php
-- ============================================================

CREATE DATABASE IF NOT EXISTS voyage_ledger_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE voyage_ledger_db;

-- ------------------------------------------------------------
-- Users (clients + admins)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(191) NOT NULL,
  password VARCHAR(255) NOT NULL,   -- stores a bcrypt hash (see password_hash())
  role ENUM('client','admin') NOT NULL DEFAULT 'client',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_email_role (email, role)
) ENGINE=InnoDB;

-- Seed the two admin accounts.
-- Password for BOTH seeded accounts below is:  ChangeMe123!
-- (This hash was generated and self-verified with Python's bcrypt
-- library right before being written here — it is confirmed to
-- unlock exactly that password, nothing else.)
-- CHANGE THIS before going live — log in and update the row,
-- or regenerate a hash with: php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);"
INSERT INTO users (email, password, role) VALUES
  ('shyam@gmail.com', '$2b$10$8mSbquExWfrVEgT1WtqDQ.23U8VLR/YiMPVuXzQhnAAJ6Y.x3FPCm', 'admin'),
  ('jeet@gmail.com',  '$2b$10$8mSbquExWfrVEgT1WtqDQ.23U8VLR/YiMPVuXzQhnAAJ6Y.x3FPCm', 'admin')
ON DUPLICATE KEY UPDATE email = email;

-- ------------------------------------------------------------
-- Bookings (confirmed, paid tickets)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bookings (
  id VARCHAR(20) PRIMARY KEY,          -- e.g. VL-26-9F3K
  passenger VARCHAR(191) NOT NULL,
  booked_by VARCHAR(191) NOT NULL,     -- account email that made the booking
  cabin VARCHAR(20) NOT NULL,
  cabin_class VARCHAR(50) NOT NULL,
  from_port VARCHAR(100) NOT NULL,
  code_from VARCHAR(5) NOT NULL,
  to_port VARCHAR(100) NOT NULL,
  code_to VARCHAR(5) NOT NULL,
  departure_date DATE NOT NULL,
  fare VARCHAR(30) NOT NULL,           -- display string, e.g. ₹27,000
  amount_paise INT NOT NULL DEFAULT 0, -- authoritative amount actually charged
  trip_type VARCHAR(20) NOT NULL,
  razorpay_order_id VARCHAR(64) DEFAULT NULL,
  razorpay_payment_id VARCHAR(64) DEFAULT NULL,
  payment_status ENUM('paid','refunded') NOT NULL DEFAULT 'paid',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Pending orders — created when the Razorpay order is opened,
-- consumed once payment is verified (turned into a `bookings` row).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pending_orders (
  razorpay_order_id VARCHAR(64) PRIMARY KEY,
  passenger VARCHAR(191) NOT NULL,
  booked_by VARCHAR(191) NOT NULL,
  cabin VARCHAR(20) NOT NULL,
  cabin_class VARCHAR(50) NOT NULL,
  from_port VARCHAR(100) NOT NULL,
  code_from VARCHAR(5) NOT NULL,
  to_port VARCHAR(100) NOT NULL,
  code_to VARCHAR(5) NOT NULL,
  departure_date DATE NOT NULL,
  fare VARCHAR(30) NOT NULL,
  amount_paise INT NOT NULL,
  trip_type VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Payments — audit trail of every Razorpay order/payment attempt,
-- independent of `bookings` so a failed or abandoned payment is
-- never silently lost (kept even if the pending order gets deleted).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  razorpay_order_id VARCHAR(64) NOT NULL UNIQUE,
  razorpay_payment_id VARCHAR(64) DEFAULT NULL,
  booking_id VARCHAR(20) DEFAULT NULL,
  amount_paise INT UNSIGNED NOT NULL,
  status ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_booking_id (booking_id)
) ENGINE=InnoDB;