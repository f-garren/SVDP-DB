<?php
/**
 * SVDP Configuration File
 * 
 * IMPORTANT: Update database credentials and admin accounts before deployment
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'svdp_db');
define('DB_USER', 'svdp_user');
define('DB_PASS', 'change_this_password');
define('DB_CHARSET', 'utf8mb4');

// Company Information
define('COMPANY_NAME', 'St. Vincent de Paul');
define('PARTNER_STORE_NAME', 'Partner Store');

// Admin Accounts (comma-separated usernames)
// These accounts will have all permissions by default
define('ADMIN_ACCOUNTS', 'admin');

// Session Configuration
define('SESSION_LIFETIME', 3600); // 1 hour

// Application Paths
define('BASE_PATH', dirname(__FILE__));
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('PAGES_PATH', BASE_PATH . '/pages');

// Security
define('BCRYPT_COST', 12);

// Timezone
date_default_timezone_set('America/New_York');

// Error Reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable error display in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// Security Headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

