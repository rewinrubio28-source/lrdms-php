<?php
/**
 * Migration — Users & Roles upgrade.
 *
 * Adds the second-generation user/role/security features to an EXISTING
 * database that was imported from the original sql/schema.sql:
 *
 *   • users:  must_change_password, failed_attempts, locked_until,
 *             last_login_at, totp_secret, totp_enabled
 *   • new table: user_sessions (active-session tracking / "sign out all devices")
 *   • new permissions: access.manage_roles, access.reset_password,
 *                      access.force_logout, access.view_user_activity
 *             (auto-granted to the Administrator role)
 *
 * Safe to re-run: every step checks what already exists before changing it.
 *
 * Run in your browser (or via CLI: `php database/migrate.php`) AFTER this
 * upgrade is deployed. Fresh installs get everything from sql/schema.sql
 * and do not need this file.
 */
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();

$ran   = [];   // messages describing each change actually applied
$skips = [];   // messages describing each no-op (already present)

function column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function add_column($pdo, $table, $column, $definition, &$ran, &$skips) {
    if (column_exists($pdo, $table, $column)) {
        $skips[] = "users.$column already exists";
        return;
    }
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    $ran[] = "Added users.$column";
}

$usersColumns = [
    'must_change_password' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'failed_attempts'      => 'INT NOT NULL DEFAULT 0',
    'locked_until'         => 'DATETIME NULL',
    'last_login_at'        => 'DATETIME NULL',
    'totp_secret'          => 'VARCHAR(64) NULL',
    'totp_enabled'         => 'TINYINT(1) NOT NULL DEFAULT 0',
];
foreach ($usersColumns as $col => $def) {
    add_column($pdo, 'users', $col, $def, $ran, $skips);
}

// Active sessions (login-token store + "sign out all devices")
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS user_sessions (
      id            INT AUTO_INCREMENT PRIMARY KEY,
      user_id       INT NOT NULL,
      session_token VARCHAR(64) NOT NULL UNIQUE,
      ip_address    VARCHAR(45),
      user_agent    VARCHAR(255),
      created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      last_seen     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      is_active     TINYINT(1) NOT NULL DEFAULT 1,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB'
);
if (column_exists($pdo, 'user_sessions', 'session_token')) {
    $skips[] = 'user_sessions table already exists';
} else {
    $ran[] = 'Created user_sessions table';
}

// New access permissions
$newPermissions = [
    ['access', 'manage_roles',       'Create/edit roles and assign permissions'],
    ['access', 'reset_password',     'Reset another user\'s password'],
    ['access', 'force_logout',       'Revoke a user\'s sessions / sign out devices'],
    ['access', 'view_user_activity', 'View another user\'s audit activity'],
];
$addedPermIds = [];
foreach ($newPermissions as [$module, $action, $description]) {
    $stmt = $pdo->prepare('SELECT id FROM permissions WHERE module = ? AND action = ?');
    $stmt->execute([$module, $action]);
    $pid = $stmt->fetchColumn();
    if ($pid) {
        $skips[] = "permission $module.$action already exists";
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO permissions (module, action, description) VALUES (?,?,?)'
        );
        $stmt->execute([$module, $action, $description]);
        $pid = $pdo->lastInsertId();
        $ran[] = "Added permission $module.$action";
    }
    $addedPermIds[] = (int)$pid;
}

// Grant the new permissions to Super Admin (full access) and Administrator (day-to-day admin).
// Super Admin gets ALL new permissions including manage_roles.
// Administrator gets manage_users, reset_password, force_logout, view_user_activity (NOT manage_roles).
$roleStmt = $pdo->prepare('SELECT id, name FROM roles WHERE name IN (?, ?)');
$roleStmt->execute(['Super Admin', 'Administrator']);
$roles = $roleStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$superAdminPerms = ['manage_roles', 'manage_users', 'reset_password', 'force_logout', 'view_user_activity'];
$adminPerms = ['manage_users', 'reset_password', 'force_logout', 'view_user_activity'];

foreach ($roles as $roleId => $roleName) {
    $permsToGrant = ($roleName === 'Super Admin') ? $superAdminPerms : $adminPerms;

    // Find the permission IDs for the permissions this role should get
    $permIds = [];
    foreach ($newPermissions as [$module, $action, $description]) {
        if (in_array($action, $permsToGrant)) {
            $stmt = $pdo->prepare('SELECT id FROM permissions WHERE module = ? AND action = ?');
            $stmt->execute([$module, $action]);
            $pid = $stmt->fetchColumn();
            if ($pid) $permIds[] = (int)$pid;
        }
    }

    foreach ($permIds as $pid) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?'
        );
        $stmt->execute([$roleId, $pid]);
        if ((int)$stmt->fetchColumn() > 0) {
            $skips[] = "$roleName already has permission_id=$pid";
        } else {
            $pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)'
            )->execute([$roleId, $pid]);
            $ran[] = "Granted permission_id=$pid to $roleName";
        }
    }
}

if (!isset($roles['Super Admin'])) {
    $ran[] = 'NOTE: Super Admin role not found (expected for fresh installs with new schema)';
}
if (!isset($roles['Administrator'])) {
    $ran[] = 'NOTE: Administrator role not found — new permissions left unassigned';
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>LRDMS — Migration result</title>
<style>
  body{font-family:sans-serif; max-width:620px; margin:60px auto; color:#333;}
  code{background:#eee; padding:1px 5px; border-radius:3px;}
  .ok{color:#1e7a3d;} .skip{color:#8a94a6;} ul{margin:6px 0 0;} li{margin-bottom:3px;}
</style></head><body>
<h2>Migration complete ✅</h2>
<?php if ($ran): ?>
  <p class="ok"><strong>Applied:</strong></p>
  <ul class="ok"><?php foreach ($ran as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php if ($skips): ?>
  <p class="skip"><strong>Already present (skipped):</strong></p>
  <ul class="skip"><?php foreach ($skips as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<p>You can keep this file for re-runs (it is idempotent), or delete it once
you are done. Existing logins stay valid.</p>
</body></html>
