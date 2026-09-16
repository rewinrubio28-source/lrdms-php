<?php
/**
 * One-time schema import for production (HostForge).
 *
 * sql/schema.sql hardcodes `CREATE DATABASE lrdms_db` / `USE lrdms_db`,
 * but on HostForge the database already exists under a different name
 * (whatever DB_NAME is set to) and the DB user typically only has
 * privileges on that one database. This script strips those two lines
 * and imports everything else into the database the app already
 * connects to.
 *
 * Run this once in your browser after deployment, then delete this
 * file (or move it out of the web root) — same rule as
 * database/seed.php, and for the same reason: it's not something you
 * want reachable in a real deployment.
 */

require_once __DIR__ . '/../config/env.php';
load_env_file();

$host = env_optional('DB_HOST', 'localhost');
$name = env_optional('DB_NAME', 'lrdms_db');
$user = env_optional('DB_USER', 'root');
$pass = env_optional('DB_PASS', '');

$sqlPath = __DIR__ . '/../sql/schema.sql';
if (!is_file($sqlPath)) {
    http_response_code(500);
    die('sql/schema.sql not found.');
}

$sql = file_get_contents($sqlPath);

// Strip the CREATE DATABASE / USE lines — the target database already
// exists (DB_NAME) and the DB user may not have privileges to create
// a new one.
$sql = preg_replace('/^CREATE DATABASE.*?;\s*$/mi', '', $sql);
$sql = preg_replace('/^USE\s+\S+;\s*$/mi', '', $sql);

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
    $pdo->exec($sql);
    echo "Schema imported successfully into '" . htmlspecialchars($name) . "'.\n\n";
    echo "Next: visit database/seed.php once to create demo accounts, ";
    echo "then delete BOTH this file and seed.php from the repo (and redeploy).";
} catch (PDOException $e) {
    http_response_code(500);
    echo "Import failed: " . htmlspecialchars($e->getMessage());
}
