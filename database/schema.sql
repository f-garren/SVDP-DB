-- SVDP Database Schema
-- Version: 1.0

CREATE DATABASE IF NOT EXISTS svdp_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE svdp_db;

-- Employees table
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    password_reset_required BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Employee permissions table
CREATE TABLE IF NOT EXISTS employee_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    permission VARCHAR(50) NOT NULL,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    UNIQUE KEY unique_permission (employee_id, permission),
    INDEX idx_employee (employee_id),
    INDEX idx_permission (permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers table
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(50) NOT NULL,
    zip_code VARCHAR(10) NOT NULL,
    phone_country_code VARCHAR(10) DEFAULT '1',
    phone_local_number VARCHAR(10) NOT NULL,
    description TEXT,
    previous_application BOOLEAN DEFAULT FALSE,
    subsidized_housing BOOLEAN DEFAULT FALSE,
    signup_date DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer_id (customer_id),
    INDEX idx_name (name),
    INDEX idx_phone (phone_local_number),
    INDEX idx_city (city),
    INDEX idx_state (state),
    INDEX idx_signup_date (signup_date),
    INDEX idx_city_state (city, state),
    FULLTEXT INDEX idx_name_address (name, address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer audit trail
CREATE TABLE IF NOT EXISTS customer_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    old_value TEXT,
    new_value TEXT,
    changed_by INT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES employees(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Household members table
CREATE TABLE IF NOT EXISTS household_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    birthdate DATE,
    relationship VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Household income table
CREATE TABLE IF NOT EXISTS household_income (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    income_type ENUM('Child Support', 'Pension', 'Wages', 'SS/SSD/SSI', 'Unemployment', 'Food Stamps', 'Other') NOT NULL,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Visits table
CREATE TABLE IF NOT EXISTS visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    visit_type ENUM('Food', 'Money', 'Voucher') NOT NULL,
    visit_date DATETIME NOT NULL,
    notes TEXT,
    is_invalid BOOLEAN DEFAULT FALSE,
    invalid_reason TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL,
    INDEX idx_customer (customer_id),
    INDEX idx_visit_type (visit_type),
    INDEX idx_visit_date (visit_date),
    INDEX idx_invalid (is_invalid),
    INDEX idx_customer_type_date (customer_id, visit_type, visit_date),
    INDEX idx_type_date_invalid (visit_type, visit_date, is_invalid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vouchers table
CREATE TABLE IF NOT EXISTS vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_id INT NOT NULL,
    customer_id INT NOT NULL,
    voucher_code VARCHAR(50) UNIQUE NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    expiration_date DATE,
    is_redeemed BOOLEAN DEFAULT FALSE,
    redeemed_at DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (visit_id) REFERENCES visits(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_voucher_code (voucher_code),
    INDEX idx_customer (customer_id),
    INDEX idx_redeemed (is_redeemed),
    INDEX idx_expiration (expiration_date),
    INDEX idx_redeemed_expiration (is_redeemed, expiration_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default settings
INSERT INTO settings (setting_key, setting_value) VALUES
    ('food_visits_per_month', '2'),
    ('food_visits_per_year', '12'),
    ('food_min_days_between', '14'),
    ('money_max_lifetime_visits', '3'),
    ('money_cooldown_years', '1'),
    ('company_name', 'St. Vincent de Paul'),
    ('partner_store_name', 'Partner Store'),
    ('db_version', '1.0')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

