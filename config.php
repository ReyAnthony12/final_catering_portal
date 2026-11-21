<?php
// ==============================================
// BASIC CONFIGURATION - NO SESSION START HERE
// ==============================================

require_once __DIR__ . '/includes/app_error_helper.php';
require_once __DIR__ . '/includes/db_connect_helper.php';
require_once __DIR__ . '/includes/notification_helper.php';
require_once __DIR__ . '/includes/subscription_helper.php';

if (!function_exists('loadSimpleEnvFile')) {
    function loadSimpleEnvFile(string $path): void {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }

            $parts = explode('=', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);

            if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

if (!function_exists('envConfig')) {
    function envConfig(string $key, $default = null) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('envFlag')) {
    function envFlag(string $key, bool $default = false): bool {
        $value = envConfig($key, null);
        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('detectAppEnvironment')) {
    function detectAppEnvironment(): string {
        $configured = strtolower(trim((string) envConfig('APP_ENV', '')));
        if (in_array($configured, ['production', 'staging', 'local', 'development', 'dev', 'test'], true)) {
            if ($configured === 'development' || $configured === 'dev') {
                return 'local';
            }
            return $configured;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if ($host === '' && PHP_SAPI === 'cli') {
            return 'local';
        }

        if (
            $host === 'localhost'
            || $host === '127.0.0.1'
            || substr($host, -6) === '.local'
            || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $host)
        ) {
            return 'local';
        }

        return 'production';
    }
}

if (!function_exists('detectBaseUrl')) {
    function detectBaseUrl(): string {
        $configured = trim((string) envConfig('APP_URL', ''));
        if ($configured !== '') {
            return rtrim($configured, '/') . '/';
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        $scheme = $isHttps ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $basePath = trim(str_replace(basename($scriptName), '', $scriptName), '/');
        $segments = $basePath === '' ? [] : explode('/', $basePath);
        $projectRoot = $segments[0] ?? '';

        $path = $projectRoot !== '' ? '/' . $projectRoot . '/' : '/';
        return $scheme . '://' . $host . $path;
    }
}

loadSimpleEnvFile(__DIR__ . '/.env');

define('APP_ENV', detectAppEnvironment());
define('APP_ALLOW_RUNTIME_MIGRATIONS', envFlag('APP_ALLOW_RUNTIME_MIGRATIONS', APP_ENV !== 'production'));
define('APP_BOOTSTRAP_ADMIN', envFlag('APP_BOOTSTRAP_ADMIN', false));

// Database configuration
define('DB_HOST', envConfig('DB_HOST', 'localhost'));
define('DB_USER', envConfig('DB_USER', 'root'));
define('DB_PASS', envConfig('DB_PASS', ''));
define('DB_NAME', envConfig('DB_NAME', 'catering_portal'));
$configuredDbPort = (int) envConfig('DB_PORT', 0);


// Base URL
define('BASE_URL', detectBaseUrl());

// File upload directories
define('UPLOAD_DIR', 'uploads/');
define('PROFILE_DIR', UPLOAD_DIR . 'profiles/');
define('ID_DIR', UPLOAD_DIR . 'ids/');
define('BUSINESS_DIR', UPLOAD_DIR . 'business/');
define('GCASH_DIR', UPLOAD_DIR . 'gcash/');
define('PAYMENT_DIR', UPLOAD_DIR . 'payments/');
define('FREE_TRIAL_DAYS', 7);

// Mail configuration
define('MAIL_HOST', envConfig('MAIL_HOST', 'smtp.gmail.com'));
define('MAIL_PORT', (int) envConfig('MAIL_PORT', 587));
define('MAIL_USERNAME', envConfig('MAIL_USERNAME', ''));
define('MAIL_PASSWORD', envConfig('MAIL_PASSWORD', ''));
define('MAIL_FROM_EMAIL', envConfig('MAIL_FROM_EMAIL', ''));
define('MAIL_FROM_NAME', envConfig('MAIL_FROM_NAME', 'Catering Portal'));

// Approval settings
define('APPROVAL_REQUIRED', true);
define('APPROVAL_TIME_HOURS', 24);

// Global session status variable
$session_started = false;

// ==============================================
// DATABASE CONNECTION
// ==============================================

// Create connection with error handling
try {
    $resolvedDbPort = 0;
    $resolvedDbHost = DB_HOST;
    $conn = cateringPortalConnectServer(DB_HOST, DB_USER, DB_PASS, $configuredDbPort, $resolvedDbPort, $resolvedDbHost);
    if (!defined('DB_PORT')) {
        define('DB_PORT', $resolvedDbPort > 0 ? $resolvedDbPort : $configuredDbPort);
    }
    cateringPortalSelectDatabase($conn, DB_NAME, APP_ENV !== 'production');
    mysqli_set_charset($conn, 'utf8mb4');
    // TIMESTAMP columns and NOW() must use the same Philippine clock as PHP.
    mysqli_query($conn, "SET time_zone = '+08:00'");

    // ── Session-based migration guard ──────────────────────────────────────
    // Bump this version string whenever a new migration is added so the block
    // will re-run automatically on the next request after a deployment.
    $migration_version = 'v27';

    // Start a session temporarily just to read/write the flag; we honour
    // whatever session state already exists (PHP_SESSION_ACTIVE).
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $migrationsDone = ($_SESSION['migrations_run'] ?? '') === $migration_version;

    if (APP_ALLOW_RUNTIME_MIGRATIONS && !$migrationsDone) {
        $required_tables = [
            'product_sets' => "CREATE TABLE IF NOT EXISTS product_sets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                set_name VARCHAR(100) NOT NULL,
                description TEXT DEFAULT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                pax INT DEFAULT 0,
                per_head_price DECIMAL(10,2) DEFAULT 0.00,
                addons TEXT DEFAULT NULL,
                swaps TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY product_id (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'payments' => "CREATE TABLE IF NOT EXISTS payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NOT NULL,
                customer_id INT NULL,
                store_id INT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                payment_type VARCHAR(50) DEFAULT NULL,
                payment_method VARCHAR(50) DEFAULT 'manual',
                receipt_image VARCHAR(255) DEFAULT NULL,
                reference_number VARCHAR(100) DEFAULT NULL,
                proof_image VARCHAR(255) DEFAULT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                notes TEXT DEFAULT NULL,
                reject_reason TEXT DEFAULT NULL,
                confirmed_by INT NULL,
                confirmed_at DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY order_id (order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            'service_inventory' => "CREATE TABLE IF NOT EXISTS service_inventory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                service_id INT NOT NULL,
                set_name VARCHAR(255) DEFAULT NULL,
                inventory_item_id INT NOT NULL,
                quantity_required DECIMAL(10,2) NOT NULL,
                is_pax_dependent TINYINT(1) DEFAULT 1,
                KEY service_id (service_id),
                KEY inventory_item_id (inventory_item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        ];

        foreach ($required_tables as $table_name => $create_sql) {
            $table_check = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table_name) . "'");
            if ($table_check && mysqli_num_rows($table_check) === 0) {
                mysqli_query($conn, $create_sql);
            }
        }

        // Auto-Migration: Ensure product set pricing metadata exists
        $check_pax_config = mysqli_query($conn, "SHOW COLUMNS FROM product_sets LIKE 'pax'");
        if ($check_pax_config && mysqli_num_rows($check_pax_config) == 0) {
            mysqli_query($conn, "ALTER TABLE product_sets ADD COLUMN pax INT DEFAULT 0 AFTER price");
        }

        $check_per_head_config = mysqli_query($conn, "SHOW COLUMNS FROM product_sets LIKE 'per_head_price'");
        if ($check_per_head_config && mysqli_num_rows($check_per_head_config) == 0) {
            mysqli_query($conn, "ALTER TABLE product_sets ADD COLUMN per_head_price DECIMAL(10,2) DEFAULT 0.00 AFTER pax");
        }

        $check_extra_guest_config = mysqli_query($conn, "SHOW COLUMNS FROM product_sets LIKE 'extra_guest_rate'");
        if ($check_extra_guest_config && mysqli_num_rows($check_extra_guest_config) == 0) {
            mysqli_query($conn, "ALTER TABLE product_sets ADD COLUMN extra_guest_rate DECIMAL(10,2) DEFAULT 0.00 AFTER per_head_price");
        }

        // Auto-Migration: Allow inventory mappings to target a specific set name
        $check_service_inventory_set = mysqli_query($conn, "SHOW COLUMNS FROM service_inventory LIKE 'set_name'");
        if ($check_service_inventory_set && mysqli_num_rows($check_service_inventory_set) == 0) {
            mysqli_query($conn, "ALTER TABLE service_inventory ADD COLUMN set_name VARCHAR(255) DEFAULT NULL AFTER service_id");
        }

    // Auto-Migration: Ensure 'reference_number' column exists in 'subscriptions'
    $check_ref_config = mysqli_query($conn, "SHOW COLUMNS FROM subscriptions LIKE 'reference_number'");
    if ($check_ref_config && mysqli_num_rows($check_ref_config) == 0) {
        mysqli_query($conn, "ALTER TABLE subscriptions ADD COLUMN reference_number VARCHAR(50) DEFAULT NULL AFTER payment_screenshot");
    }

    // Auto-Migration: Ensure 'verified_at' column exists in 'subscriptions'
    $check_verified_config = mysqli_query($conn, "SHOW COLUMNS FROM subscriptions LIKE 'verified_at'");
    if ($check_verified_config && mysqli_num_rows($check_verified_config) == 0) {
        mysqli_query($conn, "ALTER TABLE subscriptions ADD COLUMN verified_at DATETIME DEFAULT NULL AFTER reference_number");
    }

    // Auto-Migration: Ensure 'verified_by' column exists in 'subscriptions'
    $check_verified_by_config = mysqli_query($conn, "SHOW COLUMNS FROM subscriptions LIKE 'verified_by'");
    if ($check_verified_by_config && mysqli_num_rows($check_verified_by_config) == 0) {
        mysqli_query($conn, "ALTER TABLE subscriptions ADD COLUMN verified_by VARCHAR(100) DEFAULT NULL AFTER verified_at");
    }

    // Auto-Migration: Ensure 'reject_reason' column exists in 'subscriptions' (for portal rejection)
    $check_reason_config = mysqli_query($conn, "SHOW COLUMNS FROM subscriptions LIKE 'reject_reason'");
    if ($check_reason_config && mysqli_num_rows($check_reason_config) == 0) {
        mysqli_query($conn, "ALTER TABLE subscriptions ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER verified_at");
    }
    
    // Auto-Migration: Update payment_status enum to include 'rejected'
    $check_enum = mysqli_query($conn, "SHOW COLUMNS FROM subscriptions LIKE 'payment_status'");
    if ($check_enum) {
        $row = mysqli_fetch_assoc($check_enum);
        if (strpos($row['Type'], "'rejected'") === false) {
             mysqli_query($conn, "ALTER TABLE subscriptions MODIFY COLUMN payment_status ENUM('pending','paid','failed','rejected') DEFAULT 'pending'");
        }
    }

    $subscription_dynamic_columns = [
        'payment_method' => "ALTER TABLE subscriptions ADD COLUMN payment_method VARCHAR(50) DEFAULT 'manual'",
        'payment_date' => "ALTER TABLE subscriptions ADD COLUMN payment_date DATETIME DEFAULT NULL",
        'validated_amount' => "ALTER TABLE subscriptions ADD COLUMN validated_amount DECIMAL(10,2) DEFAULT NULL",
        'amount_detected' => "ALTER TABLE subscriptions ADD COLUMN amount_detected DECIMAL(10,2) DEFAULT NULL",
        'paid_at' => "ALTER TABLE subscriptions ADD COLUMN paid_at DATETIME DEFAULT NULL"
    ];
    foreach ($subscription_dynamic_columns as $column => $sql) {
        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM subscriptions LIKE '$column'");
        if ($column_check && mysqli_num_rows($column_check) == 0) {
            mysqli_query($conn, $sql);
        }
    }

    // Auto-Migration: Ensure payments.reject_reason exists for customer-visible rejection feedback
    $check_payment_reject_reason = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'reject_reason'");
    if ($check_payment_reject_reason && mysqli_num_rows($check_payment_reject_reason) == 0) {
        mysqli_query($conn, "ALTER TABLE payments ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER status");
    }

    $payments_dynamic_columns = [
        'customer_id' => "ALTER TABLE payments ADD COLUMN customer_id INT NULL AFTER order_id",
        'store_id' => "ALTER TABLE payments ADD COLUMN store_id INT NULL AFTER customer_id",
        'receipt_image' => "ALTER TABLE payments ADD COLUMN receipt_image VARCHAR(255) DEFAULT NULL AFTER payment_method",
        'confirmed_by' => "ALTER TABLE payments ADD COLUMN confirmed_by INT NULL AFTER notes",
        'confirmed_at' => "ALTER TABLE payments ADD COLUMN confirmed_at DATETIME DEFAULT NULL AFTER confirmed_by"
    ];
    foreach ($payments_dynamic_columns as $column => $sql) {
        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE '$column'");
        if ($column_check && mysqli_num_rows($column_check) == 0) {
            mysqli_query($conn, $sql);
        }
    }

    // Auto-Migration: Allow flexible payment channels on payments.payment_method.
    $check_payment_method_column = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'payment_method'");
    if ($check_payment_method_column && ($payment_method_column = mysqli_fetch_assoc($check_payment_method_column))) {
        $payment_method_type = strtolower((string) ($payment_method_column['Type'] ?? ''));
        if (strpos($payment_method_type, 'enum(') === 0) {
            mysqli_query($conn, "ALTER TABLE payments MODIFY COLUMN payment_method VARCHAR(50) DEFAULT 'manual'");
        }
    }

    // Auto-Migration: Ensure 'subscription_plan' column exists in 'store_details'
    $check_plan_config = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'subscription_plan'");
    if ($check_plan_config && mysqli_num_rows($check_plan_config) == 0) {
        mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN subscription_plan VARCHAR(50) DEFAULT 'Standard' AFTER subscription_expiry");
    }

    // Auto-Migration: Ensure 'payout_status' exists in 'orders' to track ledgers
    $check_payout = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE 'payout_status'");
    if ($check_payout && mysqli_num_rows($check_payout) == 0) {
        mysqli_query($conn, "ALTER TABLE bookings ADD COLUMN payout_status ENUM('pending', 'paid') DEFAULT 'pending' AFTER order_status");
    }

    // Auto-Migration: Set default portal commission rate (if not in system_settings)
    // Wait, let's just use a hardcoded 10% in the app or create a config if we build it later.
    
    // Auto-Migration: Ensure 'reference_number' exists in 'orders' (for GCash tracing in reservations)
    $check_ord_ref = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE 'reference_number'");
    if ($check_ord_ref && mysqli_num_rows($check_ord_ref) == 0) {
        mysqli_query($conn, "ALTER TABLE bookings ADD COLUMN reference_number VARCHAR(100) NULL AFTER payment_screenshot");
    }

    // Auto-Migration: Ensure 'created_at' column exists in 'orders' to track order placement time
    $check_ord_created_at = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE 'created_at'");
    if ($check_ord_created_at && mysqli_num_rows($check_ord_created_at) == 0) {
        mysqli_query($conn, "ALTER TABLE bookings ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER order_number");
    }

    // Auto-Migration: Caterer an immutable booking/package pricing snapshot for consistency across customer, caterer, and admin views.
    $order_booking_snapshot_columns = [
        'package_id' => "ALTER TABLE bookings ADD COLUMN package_id INT NULL AFTER product_id",
        'package_name' => "ALTER TABLE bookings ADD COLUMN package_name VARCHAR(255) DEFAULT NULL AFTER package_id",
        'package_price' => "ALTER TABLE bookings ADD COLUMN package_price DECIMAL(10,2) DEFAULT 0.00 AFTER package_name",
        'email' => "ALTER TABLE bookings ADD COLUMN email VARCHAR(100) DEFAULT NULL AFTER contact_number",
        'updated_at' => "ALTER TABLE bookings ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        'event_type' => "ALTER TABLE bookings ADD COLUMN event_type VARCHAR(50) DEFAULT NULL",
        'venue_contact_name' => "ALTER TABLE bookings ADD COLUMN venue_contact_name VARCHAR(100) DEFAULT NULL",
        'venue_contact_phone' => "ALTER TABLE bookings ADD COLUMN venue_contact_phone VARCHAR(30) DEFAULT NULL",
        'dietary_notes' => "ALTER TABLE bookings ADD COLUMN dietary_notes TEXT DEFAULT NULL"
    ];
    foreach ($order_booking_snapshot_columns as $column => $sql) {
        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE '$column'");
        if ($column_check && mysqli_num_rows($column_check) == 0) {
            mysqli_query($conn, $sql);
        }
    }

    // Auto-Migration: Ensure 'reference_number' exists in 'payments' table (for GCash tracing)
    $check_pay_ref = mysqli_query($conn, "SHOW COLUMNS FROM payments LIKE 'reference_number'");
    if ($check_pay_ref && mysqli_num_rows($check_pay_ref) == 0) {
        mysqli_query($conn, "ALTER TABLE payments ADD COLUMN reference_number VARCHAR(100) NULL AFTER receipt_image");
    }

    // Auto-Migration: Ensure 'business_open_time' and 'business_close_time' exist in 'store_details'
    $check_hours = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'business_open_time'");
    if ($check_hours && mysqli_num_rows($check_hours) == 0) {
        mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN business_open_time TIME NULL, ADD COLUMN business_close_time TIME NULL");
    }

    // Auto-Migration: Allow stores to choose whether status follows configured business hours.
    $check_auto_schedule = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'auto_schedule_enabled'");
    if ($check_auto_schedule && mysqli_num_rows($check_auto_schedule) == 0) {
        mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN auto_schedule_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER business_close_time");
    }

    // Auto-Migration: Ensure each caterer can manage its daily booking capacity
    $check_capacity_config = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'max_bookings_per_day'");
    if ($check_capacity_config && mysqli_num_rows($check_capacity_config) == 0) {
        mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN max_bookings_per_day INT DEFAULT 5 AFTER subscription_plan");
    }

    // Auto-Migration: Ensure booking_gap_days column exists for preparation advance-days setting
    $check_gap_days = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'booking_gap_days'");
    if ($check_gap_days && mysqli_num_rows($check_gap_days) == 0) {
        mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN booking_gap_days INT DEFAULT 2 AFTER max_bookings_per_day");
    }

    $check_minimum_guests = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'minimum_booking_guests'");
    if ($check_minimum_guests && mysqli_num_rows($check_minimum_guests) === 0) {
        mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN minimum_booking_guests INT UNSIGNED NOT NULL DEFAULT 1");
    }

    $check_maximum_guests = mysqli_query($conn, "SHOW COLUMNS FROM store_details LIKE 'maximum_booking_guests'");
    if ($check_maximum_guests && mysqli_num_rows($check_maximum_guests) === 0) {
        mysqli_query($conn, "ALTER TABLE store_details ADD COLUMN maximum_booking_guests INT UNSIGNED NOT NULL DEFAULT 100000");
    }

    // Auto-Migration: Create 'reviews' table if it doesn't exist
    $check_reviews = mysqli_query($conn, "SHOW TABLES LIKE 'reviews'");
    if ($check_reviews && mysqli_num_rows($check_reviews) == 0) {
        $create_reviews_sql = "CREATE TABLE IF NOT EXISTS reviews (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            store_id INT NOT NULL,
            customer_id INT NOT NULL,
            overall_rating DECIMAL(2,1) NOT NULL,
            service_rating INT NOT NULL,
            food_rating INT NOT NULL,
            delivery_rating INT NOT NULL,
            comment TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (order_id),
            INDEX (store_id),
            INDEX (customer_id)
        )";
        mysqli_query($conn, $create_reviews_sql);
    }

    // Auto-Migration: Create 'notifications' table if it doesn't exist
    $check_notifications = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
    if ($check_notifications && mysqli_num_rows($check_notifications) == 0) {
        $create_notifications_sql = "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (is_read)
        )";
        mysqli_query($conn, $create_notifications_sql);
    }

    // Auto-Migration: Create 'password_resets' table if it doesn't exist
    $check_password_resets = mysqli_query($conn, "SHOW TABLES LIKE 'password_resets'");
    if ($check_password_resets && mysqli_num_rows($check_password_resets) == 0) {
        $create_password_resets_sql = "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            email VARCHAR(100) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            attempts INT DEFAULT 0,
            expires_at DATETIME NOT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (email),
            INDEX (expires_at)
        )";
        mysqli_query($conn, $create_password_resets_sql);
    }

    // Auto-Migration: Ensure messaging columns exist for replies, message status, and order linking
    $check_messages = mysqli_query($conn, "SHOW TABLES LIKE 'messages'");
    if ($check_messages && mysqli_num_rows($check_messages) > 0) {
        $message_columns = [
            'order_id' => "ALTER TABLE messages ADD COLUMN order_id INT NULL AFTER receiver_id",
            'original_message_id' => "ALTER TABLE messages ADD COLUMN original_message_id INT DEFAULT NULL AFTER message",
            'is_read' => "ALTER TABLE messages ADD COLUMN is_read TINYINT(1) DEFAULT 0 AFTER created_at",
            'is_deleted' => "ALTER TABLE messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0 AFTER is_read"
        ];

        foreach ($message_columns as $column => $sql) {
            $column_check = mysqli_query($conn, "SHOW COLUMNS FROM messages LIKE '$column'");
            if ($column_check && mysqli_num_rows($column_check) == 0) {
                mysqli_query($conn, $sql);
            }
        }

        $index_check = mysqli_query($conn, "SHOW INDEX FROM messages WHERE Key_name = 'idx_original_message_id'");
        if ($index_check && mysqli_num_rows($index_check) == 0) {
            mysqli_query($conn, "CREATE INDEX idx_original_message_id ON messages(original_message_id)");
        }
    }

    // Auto-Migration: Ensure 'is_deleted_by_customer' column exists in 'orders'
    $check_deleted_config = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE 'is_deleted_by_customer'");
    if ($check_deleted_config && mysqli_num_rows($check_deleted_config) == 0) {
        mysqli_query($conn, "ALTER TABLE bookings ADD COLUMN is_deleted_by_customer TINYINT(1) DEFAULT 0");
    }

    // Auto-Migration: Create 'customer_favorites' table if it doesn't exist
    $check_favorites = mysqli_query($conn, "SHOW TABLES LIKE 'customer_favorites'");
    if ($check_favorites && mysqli_num_rows($check_favorites) == 0) {
        $create_favorites_sql = "CREATE TABLE IF NOT EXISTS customer_favorites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            store_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_favorite (customer_id, store_id),
            INDEX (customer_id),
            INDEX (store_id)
        )";
        mysqli_query($conn, $create_favorites_sql);
    }
    
    // Auto-Migration: Create 'user_reports' table
    $check_reports = mysqli_query($conn, "SHOW TABLES LIKE 'user_reports'");
    if ($check_reports && mysqli_num_rows($check_reports) == 0) {
        $create_reports_sql = "CREATE TABLE IF NOT EXISTS user_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT NOT NULL,
            reported_user_id INT NOT NULL,
            order_id INT NULL,
            reason TEXT,
            status ENUM('pending', 'resolved', 'rejected') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL
        )";
        mysqli_query($conn, $create_reports_sql);
    }

    // Auto-Migration: Ensure 'is_archived_by_store' column exists in 'orders' (for payment dashboard)
    $check_archived_config = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE 'is_archived_by_store'");
    if ($check_archived_config && mysqli_num_rows($check_archived_config) == 0) {
        mysqli_query($conn, "ALTER TABLE bookings ADD COLUMN is_archived_by_store TINYINT(1) DEFAULT 0");
    }

    // Auto-Migration: Ensure payment tracking columns exist in 'orders'
    $order_payment_columns = [
        'paid_at' => "ALTER TABLE bookings ADD COLUMN paid_at DATETIME DEFAULT NULL"
    ];
    foreach ($order_payment_columns as $column => $sql) {
        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE '$column'");
        if ($column_check && mysqli_num_rows($column_check) == 0) {
            mysqli_query($conn, $sql);
        }
    }

    // Auto-Migration: Ensure orders supports customer reschedule requests
    $reschedule_columns = [
        'reschedule_status' => "ALTER TABLE bookings ADD COLUMN reschedule_status VARCHAR(20) DEFAULT NULL AFTER is_archived_by_store",
        'requested_order_date' => "ALTER TABLE bookings ADD COLUMN requested_order_date DATE DEFAULT NULL AFTER reschedule_status",
        'requested_order_time' => "ALTER TABLE bookings ADD COLUMN requested_order_time TIME DEFAULT NULL AFTER requested_order_date",
        'reschedule_reason' => "ALTER TABLE bookings ADD COLUMN reschedule_reason TEXT DEFAULT NULL AFTER requested_order_time",
        'reschedule_response' => "ALTER TABLE bookings ADD COLUMN reschedule_response TEXT DEFAULT NULL AFTER reschedule_reason",
        'reschedule_requested_at' => "ALTER TABLE bookings ADD COLUMN reschedule_requested_at DATETIME DEFAULT NULL AFTER reschedule_response",
        'reschedule_resolved_at' => "ALTER TABLE bookings ADD COLUMN reschedule_resolved_at DATETIME DEFAULT NULL AFTER reschedule_requested_at"
    ];

    foreach ($reschedule_columns as $column => $sql) {
        $column_check = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE '$column'");
        if ($column_check && mysqli_num_rows($column_check) == 0) {
            mysqli_query($conn, $sql);
        }
    }

    // Auto-Migration: Ensure order_logs supports detailed workflow history
    $check_order_logs = mysqli_query($conn, "SHOW TABLES LIKE 'booking_logs'");
    if ($check_order_logs && mysqli_num_rows($check_order_logs) > 0) {
        $order_log_columns = [
            'details' => "ALTER TABLE booking_logs ADD COLUMN details TEXT DEFAULT NULL AFTER action",
            'user_role' => "ALTER TABLE booking_logs ADD COLUMN user_role VARCHAR(50) DEFAULT NULL AFTER user_id",
            'old_value' => "ALTER TABLE booking_logs ADD COLUMN old_value TEXT DEFAULT NULL AFTER details",
            'new_value' => "ALTER TABLE booking_logs ADD COLUMN new_value TEXT DEFAULT NULL AFTER old_value"
        ];

        foreach ($order_log_columns as $column => $sql) {
            $column_check = mysqli_query($conn, "SHOW COLUMNS FROM booking_logs LIKE '$column'");
            if ($column_check && mysqli_num_rows($column_check) == 0) {
                mysqli_query($conn, $sql);
            }
        }
    }

    // Auto-Migration: Expand bookings.payment_status enum to match actual app statuses
    $check_order_payment_enum = mysqli_query($conn, "SHOW COLUMNS FROM bookings LIKE 'payment_status'");
    if ($check_order_payment_enum) {
        $row = mysqli_fetch_assoc($check_order_payment_enum);
        $type = $row['Type'] ?? '';
        if (strpos($type, "'unpaid'") === false || strpos($type, "'partial'") === false) {
            mysqli_query($conn, "ALTER TABLE bookings MODIFY COLUMN payment_status ENUM('unpaid','partial','pending','paid','failed') DEFAULT 'unpaid'");
        }
    }

        // Data Repair: Backfill users.email from username when older records stored the email there
        mysqli_query(
            $conn,
            "UPDATE users
             SET email = username
             WHERE (email IS NULL OR email = '')
             AND username LIKE '%@%'"
        );

        // Mark migrations as complete for this session version
        $_SESSION['migrations_run'] = $migration_version;
    }

} catch (Exception $e) {
    // Show a user-friendly error page if database is down
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Maintenance - Catering Portal</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .error-container { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); max-width: 500px; text-align: center; border-left: 5px solid #dc3545; }
            h1 { color: #dc3545; margin-top: 0; }
            p { color: #6c757d; line-height: 1.6; }
            .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 20px; transition: background 0.3s; }
            .btn:hover { background: #0056b3; }
            .tech-details { margin-top: 20px; padding: 10px; background: #f8d7da; color: #721c24; border-radius: 5px; font-size: 0.9em; display: none; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1>System Temporarily Unavailable</h1>
            <p>We're having trouble connecting to the service right now. Please try again in a few moments.</p>
            <p><strong>Administrator Action:</strong> Check the database server and deployment environment settings.</p>
            <?php if (defined('APP_ENV') && APP_ENV !== 'production'): ?>
            <p class="tech-details" style="display:block;"><?php echo htmlspecialchars($e->getMessage()); ?></p>
            <?php endif; ?>
            <a href="javascript:location.reload()" class="btn">Try Again</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Create directories if they don't exist
$directories = [UPLOAD_DIR, PROFILE_DIR, ID_DIR, BUSINESS_DIR, GCASH_DIR, PAYMENT_DIR];
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// ==============================================
// OPTIONAL ADMIN BOOTSTRAP
// ==============================================
function bootstrapDefaultAdminIfEnabled($conn) {
    if (!APP_BOOTSTRAP_ADMIN) {
        return;
    }

    $admin_username = envConfig('BOOTSTRAP_ADMIN_USERNAME', 'admin');
    $admin_password = envConfig('BOOTSTRAP_ADMIN_PASSWORD', 'admin123');
    $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
    
    // Check if admin already exists
    $check_query = "SELECT id FROM users WHERE username = '$admin_username' AND role = 'portal'";
    $check_result = mysqli_query($conn, $check_query);
    
    if (mysqli_num_rows($check_result) == 0) {
        // Create admin user
        $insert_query = "INSERT INTO users (
            username, 
            password, 
            first_name, 
            last_name, 
            role, 
            status,
            created_at
        ) VALUES (
            '$admin_username',
            '$hashed_password',
            'Super',
            'Admin',
            'portal',
            'active',
            NOW()
        )";
        
        if (mysqli_query($conn, $insert_query)) {
            error_log("Default admin bootstrap account created successfully.");
        } else {
            error_log("Failed to create bootstrap admin account: " . mysqli_error($conn));
        }
    }
}

// Bootstrap admin only when explicitly enabled through environment settings.
bootstrapDefaultAdminIfEnabled($conn);

// ==============================================
// FUNCTION DEFINITIONS
// ==============================================

// Function to start session safely
function startSession() {
    global $session_started;
    
    if (!$session_started && session_status() == PHP_SESSION_NONE) {
        session_start();
        $session_started = true;
    }
}

// Check if user is logged in
function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

// Get user role
function getUserRole() {
    startSession();
    return $_SESSION['role'] ?? null;
}

// Check if user is approved (for caterer owners)
function isStoreApproved($user_id = null) {
    global $conn;
    startSession();
    
    if (!$user_id && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    if ($user_id) {
        $query = "SELECT u.status, sd.approval_status 
                  FROM users u 
                  LEFT JOIN store_details sd ON u.id = sd.user_id 
                  WHERE u.id = '$user_id' AND u.role = 'caterer'";
        $result = mysqli_query($conn, $query);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);
            return $user['status'] === 'active' && ($user['approval_status'] === 'approved' || $user['approval_status'] === null);
        }
    }
    
    return false;
}

// Redirect if not logged in
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit();
    }
}

// Check if the current time falls within the caterer's configured business hours.
function isWithinBusinessHours($open_time = null, $close_time = null, $current_time = null) {
    $open_time = trim((string) ($open_time ?? ''));
    $close_time = trim((string) ($close_time ?? ''));

    if ($open_time === '' || $close_time === '') {
        return true;
    }

    $current_time = $current_time ?: date('H:i:s');

    if ($open_time <= $close_time) {
        return $current_time >= $open_time && $current_time <= $close_time;
    }

    return $current_time >= $open_time || $current_time <= $close_time;
}

// Caterer availability can follow either the manual button or automatic business hours.
function isStoreAvailableNow($is_open, $open_time = null, $close_time = null, $auto_schedule_enabled = 0) {
    if (!empty($auto_schedule_enabled)) {
        return isWithinBusinessHours($open_time, $close_time);
    }

    return !empty($is_open);
}

// Build a reusable SQL expression for effective caterer availability.
function getStoreAvailabilitySql($isOpenColumn, $openTimeColumn, $closeTimeColumn, $autoScheduleColumn = '0') {
    $isOpenColumn = trim((string) $isOpenColumn);
    $openTimeColumn = trim((string) $openTimeColumn);
    $closeTimeColumn = trim((string) $closeTimeColumn);
    $autoScheduleColumn = trim((string) $autoScheduleColumn);

    return "(
        CASE
            WHEN COALESCE($autoScheduleColumn, 0) = 1 THEN
                CASE
                    WHEN $openTimeColumn IS NULL OR $closeTimeColumn IS NULL THEN COALESCE($isOpenColumn, 0) = 1
                    WHEN $openTimeColumn <= $closeTimeColumn THEN (CURRENT_TIME() >= $openTimeColumn AND CURRENT_TIME() <= $closeTimeColumn)
                    ELSE (CURRENT_TIME() >= $openTimeColumn OR CURRENT_TIME() <= $closeTimeColumn)
                END
            ELSE COALESCE($isOpenColumn, 0) = 1
        END
    )";
}

// Redirect based on role
function redirectBasedOnRole() {
    startSession();
    
    if (isLoggedIn()) {
        $role = getUserRole();
        $user_id = $_SESSION['user_id'];
        
        switch ($role) {
            case 'portal':
                // Admin users go to portal dashboard
                header('Location: portal123/dashboard.php');
                exit();
                break;
                
            case 'store':
            case 'caterer':
                // For caterer users, check approval status first
                $user_query = "SELECT status FROM users WHERE id = '$user_id'";
                $user_result = mysqli_query($GLOBALS['conn'], $user_query);
                $user = mysqli_fetch_assoc($user_result);
                
                if ($user['status'] === 'pending' || $user['status'] === 'inactive') {
                    header('Location: caterer/subscription.php');
                    exit();
                } else {
                    header('Location: caterer/dashboard.php');
                    exit();
                }
                break;
                
            case 'customer':
                header('Location: customer/dashboard.php');
                exit();
                break;
                
            default:
                // If role not recognized, logout
                session_destroy();
                header('Location: ../login.php');
                exit();
        }
    }
}

// ==============================================
// NOTIFICATION HELPER FUNCTIONS
// ==============================================

/**
 * Get unread notification count for a user
 * @param int $user_id
 * @return int
 */
function getUnreadCount($user_id) {
    global $conn;
    $user_id = intval($user_id);
    $sql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0";
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    return intval($row['count']);
}

/**
 * Get notifications for a user
 * @param int $user_id
 * @param int $limit
 * @return array
 */
function getNotifications($user_id, $limit = 10) {
    global $conn;
    $user_id = intval($user_id);
    $limit = intval($limit);
    $sql = "SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT $limit";
    $result = mysqli_query($conn, $sql);
    $notifications = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = $row;
    }
    return $notifications;
}

/**
 * Mark a notification as read
 * @param int $notification_id
 * @param int $user_id - For security verification
 * @return bool
 */
function markAsRead($notification_id, $user_id) {
    global $conn;
    $notification_id = intval($notification_id);
    $user_id = intval($user_id);
    $sql = "UPDATE notifications SET is_read = 1 WHERE id = $notification_id AND user_id = $user_id";
    return mysqli_query($conn, $sql);
}

/**
 * Mark all notifications as read for a user
 * @param int $user_id
 * @return bool
 */
function markAllAsRead($user_id) {
    global $conn;
    $user_id = intval($user_id);
    $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = $user_id AND is_read = 0";
    return mysqli_query($conn, $sql);
}

/**
 * Get notification icon based on type
 * @param string $type
 * @return string - Font Awesome icon class
 */
function getNotificationIcon($type) {
    $icons = [
        'new_order' => 'fa-shopping-cart',
        'order_status' => 'fa-sync-alt',
        'payment_received' => 'fa-money-bill-wave',
        'payment_confirmed' => 'fa-check-double',
        'payment_verified' => 'fa-check-circle',
        'new_message' => 'fa-comment-dots',
        'account_approved' => 'fa-user-check',
        'account_rejected' => 'fa-user-times',
        'store_registration' => 'fa-caterer',
        'customer_registration' => 'fa-user-plus',
        'subscription_expiring' => 'fa-exclamation-triangle',
        'reschedule_request' => 'fa-calendar-day',
        'reschedule_approved' => 'fa-calendar-check',
        'reschedule_rejected' => 'fa-calendar-xmark',
        'menu_change_request' => 'fa-utensils',
        'menu_change_approved' => 'fa-thumbs-up',
        'menu_change_rejected' => 'fa-thumbs-down',
        'booking_reminder' => 'fa-calendar-alt',
        'system' => 'fa-bell'
    ];
    return $icons[$type] ?? 'fa-bell';
}

syncStoreSubscriptions($conn);

require_once __DIR__ . '/includes/booking_expiry_helper.php';
cancelExpiredUnacceptedBookings($conn);

// ==============================================
// SECURITY FUNCTIONS
// ==============================================

/**
 * Generate CSRF token
 * @return string CSRF token
 */
function generateCSRFToken() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        }
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 * @param string $token
 * @return bool
 */
function validateCSRFToken($token) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Claims a booking request once per customer session. This prevents a rapid
 * double-click or repeated POST from creating more than one order.
 */
function claimBookingSubmission(string $scope, int $userId, array $details): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $now = time();
    $claims = $_SESSION['booking_submission_claims'] ?? [];
    foreach ($claims as $key => $createdAt) {
        if (!is_int($createdAt) || $createdAt < ($now - 600)) {
            unset($claims[$key]);
        }
    }

    ksort($details);
    $key = hash('sha256', $scope . '|' . $userId . '|' . json_encode($details));
    if (isset($claims[$key])) {
        $_SESSION['booking_submission_claims'] = $claims;
        return false;
    }

    $claims[$key] = $now;
    $_SESSION['booking_submission_claims'] = $claims;
    return true;
}
?>
