<?php
/**
 * One-time seed script.
 *
 * Run this once in your browser AFTER importing sql/schema.sql, e.g.:
 *   http://localhost/lrdms-php/database/seed.php
 *
 * It creates demo accounts with properly hashed passwords (PHP's
 * password_hash() has to run in PHP — it can't be pre-written into the
 * .sql file) and a few sample documents so every module has something
 * to show on first run.
 *
 * Delete this file, or move it outside the web root, once you're done.
 */
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();

function role_id($pdo, $name) {
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
    $stmt->execute([$name]);
    return $stmt->fetchColumn();
}
function committee_id($pdo, $name) {
    $stmt = $pdo->prepare('SELECT id FROM committees WHERE name = ?');
    $stmt->execute([$name]);
    return $stmt->fetchColumn();
}

// Verify the expected roles exist (schema.sql must be imported first)
$expectedRoles = ['Super Admin', 'Administrator', 'Records Officer', 'Legislative Staff', 'Committee Secretary'];
$missingRoles = [];
foreach ($expectedRoles as $roleName) {
    if (!role_id($pdo, $roleName)) {
        $missingRoles[] = $roleName;
    }
}
if ($missingRoles) {
    http_response_code(500);
    echo '<h2>Missing roles — import schema.sql first</h2>';
    echo '<p>The following roles do not exist in the database. You need to (re)import <code>sql/schema.sql</code> via phpMyAdmin before running this seed script:</p>';
    echo '<ul>';
    foreach ($missingRoles as $r) echo '<li><code>' . htmlspecialchars($r) . '</code></li>';
    echo '</ul>';
    echo '<p>If you just updated the codebase, the schema has changed — drop the database and re-import schema.sql, or run the migration if upgrading.</p>';
    exit;
}

// [username, full name, password, role name, committee name or null]
$demoUsers = [
    ['superadmin',         'Juan Dela Cruz (Super Admin)',       'superadmin123', 'Super Admin',         null],
    ['admin',              'Ana Dela Cruz (Administrator)',      'admin123',      'Administrator',       null],
    ['rofficer',           'Ramon Santos (Records Officer)',     'password123',   'Records Officer',     null],
    ['staff',              'Liza Dizon (Legislative Staff)',     'password123',   'Legislative Staff',   null],
    ['secretary',          'Mark Cruz (Committee Secretary)',    'password123',   'Committee Secretary', 'Committee on Transportation'],
];

$createdIds = [];
foreach ($demoUsers as [$username, $fullName, $password, $roleName, $committeeName]) {
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $check->execute([$username]);
    $existingId = $check->fetchColumn();

    if ($existingId) {
        $createdIds[$username] = $existingId;
        continue;
    }

    $rid = role_id($pdo, $roleName);
    if (!$rid) {
        // Should not happen since we checked above, but just in case
        continue;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, username, password_hash, role_id, committee_id) VALUES (?,?,?,?,?)'
    );
    $stmt->execute([
        $fullName,
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $rid,
        $committeeName ? committee_id($pdo, $committeeName) : null,
    ]);
    $createdIds[$username] = $pdo->lastInsertId();
}

// Sample documents — only inserted if the repository is currently empty,
// so re-running this script is safe and won't duplicate data.
$count = $pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();
if ($count == 0) {
    $officerId = $createdIds['rofficer'];
    $staffId = $createdIds['staff'];

    $samples = [
        ['2024-077', 'Ordinance No. 2024-077 — An Ordinance Establishing a Local Traffic Management Scheme', 'Ordinance', 'Councilor R. Santos', 'Amended', 1, '2024-11-08'],
        ['2026-014', 'Ordinance No. 2026-014 — An Ordinance Amending the Traffic Management Scheme', 'Ordinance', 'Councilor R. Santos', 'Enacted', 1, '2026-02-20'],
        ['2026-045', 'Ordinance No. 2026-045 — An Ordinance Regulating Fare Adjustments for Public Utility Vehicles', 'Ordinance', 'Councilor A. Reyes', 'Enacted', 1, '2026-05-04'],
        ['2026-021', "Resolution No. 2026-021 — A Resolution Expressing Support for the City's Urban Greening Program", 'Resolution', 'Councilor L. Dizon', 'Enacted', 1, '2026-04-02'],
        ['2026-090', 'Resolution No. 2026-090 — A Resolution on Youth Development Programs (Draft)', 'Resolution', 'Councilor L. Dizon', 'Draft', 0, null],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO documents (doc_number, title, doc_type, sponsor, owner_id, status, is_public, source_system, enactment_date)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    foreach ($samples as $s) {
        [$num, $title, $type, $sponsor, $status, $public, $date] = $s;
        $owner = $status === 'Draft' ? $staffId : $officerId;
        $stmt->execute([$num, $title, $type, $sponsor, $owner, $status, $public, 'Manual Encoding', $date]);
    }

    // Link the amendment chain: 2024-077 was amended into 2026-014.
    $oldId = $pdo->query("SELECT id FROM documents WHERE doc_number = '2024-077'")->fetchColumn();
    $newId = $pdo->query("SELECT id FROM documents WHERE doc_number = '2026-014'")->fetchColumn();
    $pdo->prepare('UPDATE documents SET next_version_id = ? WHERE id = ?')->execute([$newId, $oldId]);
    $pdo->prepare('UPDATE documents SET previous_version_id = ? WHERE id = ?')->execute([$oldId, $newId]);
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Seed complete</title>
<style>body{font-family:sans-serif; max-width:520px; margin:60px auto; color:#333;} code{background:#eee; padding:1px 5px; border-radius:3px;} li{margin-bottom:4px;}</style>
</head><body>
<h2>Seed complete ✅</h2>
<p>Demo accounts (username / password):</p>
<ul>
<?php foreach ($demoUsers as [$username, $fullName, $password, $roleName, $committeeName]): ?>
  <li><strong><?= htmlspecialchars($username) ?></strong> / <?= htmlspecialchars($password) ?> — <?= htmlspecialchars($roleName) ?></li>
<?php endforeach; ?>
</ul>
<p><a href="../login.php">Go to login →</a></p>
<p style="color:#C62828;"><strong>Delete this file (database/seed.php), or move it outside the web root, once you're done with it.</strong></p>
</body></html>
