-- MilkBook Database Schema
-- Strict InnoDB, utf8mb4, foreign keys, sensible indexes

CREATE DATABASE IF NOT EXISTS `milkbook` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `milkbook`;

-- 1. Customers Table
CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `address` TEXT NULL,
    `mobile` VARCHAR(20) NOT NULL,
    `committed_qty` DECIMAL(6, 2) NOT NULL DEFAULT 1.00 COMMENT 'Daily committed litres, multiple of 0.25',
    `custom_rate` DECIMAL(8, 2) NULL DEFAULT NULL COMMENT 'Optional special rate per litre for this customer',
    `route_order` INT NOT NULL DEFAULT 0 COMMENT 'Delivery sequence order',
    `start_date` DATE NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_route_order` (`route_order`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_mobile` (`mobile`),
    INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Milk Rates Table (Rate History)
CREATE TABLE IF NOT EXISTS `milk_rates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rate_per_litre` DECIMAL(8, 2) NOT NULL,
    `effective_from` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_effective_from` (`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Settings Table (Key/Value Configuration)
CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` VARCHAR(50) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Invoices Table (Created before deliveries to satisfy foreign key reference)
CREATE TABLE IF NOT EXISTS `invoices` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_no` VARCHAR(50) NOT NULL UNIQUE,
    `customer_id` INT NOT NULL,
    `period_month` TINYINT NOT NULL COMMENT '1 to 12',
    `period_year` SMALLINT NOT NULL COMMENT 'e.g. 2026',
    `period_from` DATE NOT NULL,
    `period_to` DATE NOT NULL,
    `days_delivered` INT NOT NULL DEFAULT 0,
    `total_qty` DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    `milk_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `previous_balance` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Signed: positive = pending dues, negative = advance credit',
    `total_payable` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `paid_amount` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `status` ENUM('unpaid', 'partial', 'paid', 'overpaid') NOT NULL DEFAULT 'unpaid',
    INDEX `idx_invoices_customer_id` (`customer_id`),
    INDEX `idx_customer_period` (`customer_id`, `period_year`, `period_month`),
    INDEX `idx_invoice_period` (`period_year`, `period_month`),
    INDEX `idx_invoice_status` (`status`),
    CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Deliveries Table
CREATE TABLE IF NOT EXISTS `deliveries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `delivery_date` DATE NOT NULL,
    `quantity` DECIMAL(6, 2) NOT NULL DEFAULT 0.00 COMMENT 'Litres delivered that day',
    `rate` DECIMAL(8, 2) NOT NULL COMMENT 'Snapshot of the rate applied that day',
    `amount` DECIMAL(10, 2) NOT NULL COMMENT 'quantity * rate rounded to 2 decimals',
    `invoice_id` INT NULL DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_customer_delivery_date` (`customer_id`, `delivery_date`),
    INDEX `idx_delivery_date` (`delivery_date`),
    CONSTRAINT `fk_deliveries_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_deliveries_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Payments Table
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `invoice_id` INT NULL DEFAULT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `payment_date` DATE NOT NULL,
    `mode` ENUM('cash', 'upi', 'bank', 'other') NOT NULL DEFAULT 'cash',
    `reference` VARCHAR(100) NULL COMMENT 'UPI transaction ref or cheque number',
    `note` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_payment_date` (`payment_date`),
    INDEX `idx_payment_mode` (`mode`),
    CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_payments_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Ledger Table (Single Source of Truth for Customer Balance)
CREATE TABLE IF NOT EXISTS `ledger` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NOT NULL,
    `entry_date` DATE NOT NULL,
    `type` ENUM('invoice', 'payment', 'adjustment') NOT NULL,
    `ref_id` INT NULL COMMENT 'ID of related invoice or payment',
    `description` VARCHAR(255) NOT NULL,
    `debit` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Increases customer liability (e.g. invoice bill)',
    `credit` DECIMAL(10, 2) NOT NULL DEFAULT 0.00 COMMENT 'Decreases customer liability (e.g. payment received)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ledger_customer_date` (`customer_id`, `entry_date`),
    INDEX `idx_ledger_type` (`type`),
    CONSTRAINT `fk_ledger_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
