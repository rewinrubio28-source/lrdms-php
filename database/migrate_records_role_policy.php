<?php
/** One-time transactional policy rollout; subsequent upgrades preserve manual changes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/records_role_policy.php';
$pdo = get_db();
$pdo->exec('CREATE TABLE IF NOT EXISTS application_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
$migration = 'records_role_policy_v1';
$pdo->beginTransaction();
try {
    $mark = $pdo->prepare('INSERT IGNORE INTO application_migrations (name) VALUES (?)');
    $mark->execute([$migration]);
    if (!$mark->rowCount()) { $pdo->rollBack(); echo "Records role policy already applied; manual changes preserved.\n"; return; }
    $permissions = [];
    foreach ($pdo->query('SELECT * FROM permissions')->fetchAll() as $permission) $permissions[$permission['module'].'.'.$permission['action']] = (int)$permission['id'];
    $find = $pdo->prepare('SELECT id FROM roles WHERE name=?');
    $grant = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id,permission_id) VALUES (?,?)');
    foreach (records_role_definitions() as $name => [$description, $keys]) {
        foreach ($keys as $key) if (!isset($permissions[$key])) throw new RuntimeException('Run deployment upgrade first: missing ' . $key);
        $find->execute([$name]);
        $id = $find->fetchColumn();
        if (!$id) {
            $pdo->prepare('INSERT INTO roles (name,description) VALUES (?,?)')->execute([$name,$description]);
            $id = $pdo->lastInsertId();
        }
        $before = $pdo->prepare('SELECT permission_id FROM role_permissions WHERE role_id=? ORDER BY permission_id');
        $before->execute([$id]);
        $old = $before->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('UPDATE roles SET description=? WHERE id=?')->execute([$description,$id]);
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$id]);
        foreach ($keys as $key) $grant->execute([$id,$permissions[$key]]);
        log_action('access','applied_role_policy',json_encode(['role'=>$name,'previous_permission_ids'=>$old,'permissions'=>$keys]));
    }
    // Preserve the existing Super Admin role and accounts; grant every available permission.
    $find->execute(['Super Admin']);
    $superId = $find->fetchColumn();
    if (!$superId) throw new RuntimeException('Super Admin role is missing; rollout cancelled.');
    foreach ($permissions as $permissionId) $grant->execute([$superId,$permissionId]);
    log_action('access','applied_role_policy','Super Admin preserved with all available permissions; accounts unchanged.');

    // Use the existing Office > Division fields as the parent unit > section hierarchy.
    $officeName = 'Information and Communication Division';
    $pdo->prepare('INSERT IGNORE INTO offices (name) VALUES (?)')->execute([$officeName]);
    $stmt = $pdo->prepare('SELECT id FROM offices WHERE name=?');
    $stmt->execute([$officeName]);
    $officeId = $stmt->fetchColumn();
    foreach (['Records Management and Publication Section (RMPS)', 'Information Technology and Telecommunication Section (ITTS)'] as $section) {
        $pdo->prepare('INSERT IGNORE INTO divisions (office_id,name) VALUES (?,?)')->execute([$officeId,$section]);
    }
    foreach (['Chief Administrative Officer','Supervising Administrative Officer','Administrative Officer V','Senior Administrative Assistant IV','Administrative Officer III','Administrative Assistant II','Administrative Aide VI','Administrative Aide IV','Administrative Aide II','Senior Administrative Assistant II (Computer Operator II)','Administrative Assistant IV (Videographer/Photographer III)','Administrative Assistant I (Videographer/Photographer II)','Administrative Assistant I (Audio-Visual Equipment Operator III)','Administrative Assistant IV (Communication Equipment Operator III)','Administrative Assistant I (Computer Operator I)'] as $position) {
        $pdo->prepare('INSERT IGNORE INTO positions (name) VALUES (?)')->execute([$position]);
    }
    log_action('access','configured_organization','Draft chart: Information and Communication Division; RMPS and ITTS; position references added. No employees auto-assigned.');
    $pdo->commit();
    echo "Records role policy applied. Super Admin and all existing accounts preserved. Organization references added.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
