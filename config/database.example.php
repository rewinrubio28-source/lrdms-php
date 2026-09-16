<?php
/**
 * Database connection settings.
 *
 * EXAMPLE FILE — copy to database.php (git-ignored) if you need a
 * committed reference. Reads from environment variables (DB_HOST, DB_NAME, DB_USER, DB_PASS).
 * Locally these come from a project-root .env file (see .env.example);
 * in production they're set in HostForge's Environment Variables tab —
 * no secrets are hardcoded here or committed to git.
 */
require_once __DIR__ . '/env.php';
load_env_file();

// Pin all PHP date/time output to Philippine time (the server's own
// timezone may be UTC/Berlin/etc., which shifts greetings & timestamps).
date_default_timezone_set('Asia/Manila');

// Defaults below match a stock XAMPP install — used only when the env
// var isn't set at all (i.e. local dev with no .env yet).
define('DB_HOST', env_optional('DB_HOST', 'localhost'));
define('DB_NAME', env_optional('DB_NAME', 'lrdms_db'));
define('DB_USER', env_optional('DB_USER', 'root'));
define('DB_PASS', env_optional('DB_PASS', ''));

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
            die('Database connection failed. Check your DB_* environment variables and confirm the database is reachable. (' . htmlspecialchars($e->getMessage()) . ')');
        }
    }
    return $pdo;
}
