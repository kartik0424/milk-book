<?php
/**
 * MilkBook - Configuration
 * Environment settings, database credentials, and business constants.
 */

// Timezone requirement: Asia/Kolkata
date_default_timezone_set('Asia/Kolkata');

// Database configuration (defaults to standard XAMPP setup, can be overridden via environment variables for cPanel)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'milkbook');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

// Business constants
define('QTY_STEP', 0.25);
define('CURRENCY_SYMBOL', '₹');
define('APP_NAME', 'MilkBook');

// Optional Single-Admin Authentication
// Default is false for instant local use; set to true in production or for testing admin login
define('AUTH_ENABLED', getenv('AUTH_ENABLED') === 'true' ? true : false);
define('ADMIN_USER', getenv('ADMIN_USER') ?: 'admin');
// Default password: milkman123
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '$2y$10$Xdwh6MXWzB4LoItr4tutfeQyqEPneODVyiZhCGjiOEQO6YtykT9om');

// Session security defaults (HttpOnly, SameSite)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
}
