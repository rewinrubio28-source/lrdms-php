<?php
/**
 * Database connection settings.
 *
 * Defaults match a stock XAMPP install (MySQL running on localhost,
 * user "root" with no password). Change these if your setup differs.
 */

// Pin all PHP date/time output to Philippine time (the server's own
// timezone may be UTC/Berlin/etc., which shifts greetings & timestamps).
date_default_timezone_set('Asia/Manila');

define('DB_HOST', 'localhost');
define('DB_NAME', 'lrdms_db');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

/**
 * Returns a shared PDO connection. Using a single static instance avoids
 * reconnecting on every function call within one request.
 */
function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die('Database connection failed. Check config/database.php and confirm MySQL is running in XAMPP. (' . htmlspecialchars($e->getMessage()) . ')');
        }
    }
    return $pdo;
}
