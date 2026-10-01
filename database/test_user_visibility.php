<?php
// Connection-local fixtures: no persistent accounts or audit rows are changed.
if (PHP_SAPI !== 'cli') exit;
session_start(['save_path' => sys_get_temp_dir()]);
require_once __DIR__ . '/../includes/rbac.php';
$pdo = get_db();
$pdo->exec('CREATE TEMPORARY TABLE roles (id INT PRIMARY KEY, name VARCHAR(100))');
$pdo->exec('CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, role_id INT, username VARCHAR(100), is_active INT)');
$pdo->exec('CREATE TEMPORARY TABLE audit_log (user_id INT, username_snapshot VARCHAR(100), module VARCHAR(100), action VARCHAR(100), detail TEXT, ip_address VARCHAR(100))');
$pdo->exec("INSERT INTO roles VALUES (1, 'Super Admin'), (2, 'Administrator'), (3, 'Records Officer')");
$pdo->exec("INSERT INTO users VALUES (1,1,'hidden-admin',1), (2,2,'admin',1), (3,3,'officer',0), (4,1,'hidden-disabled',0)");
function visibility_check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$visible = $pdo->query('SELECT u.id FROM users u WHERE ' . user_directory_clause() . ' ORDER BY u.id')->fetchAll(PDO::FETCH_COLUMN);
visibility_check(array_map('intval', $visible) === [2, 3], 'Directory excludes active and disabled superadmins');
$stats = $pdo->query('SELECT COUNT(*) AS total, SUM(u.is_active=1) AS active FROM users u WHERE ' . user_directory_clause())->fetch();
visibility_check((int)$stats['total'] === 2 && (int)$stats['active'] === 1, 'Counts exclude hidden accounts');
$hidden = ['id'=>1, 'role_name'=>'Super Admin', 'username'=>'hidden-admin'];
visibility_check(!can_view_user_account($hidden, ['id'=>2]), 'Direct account access denied to other users');
visibility_check(!can_view_user_account($hidden, ['id'=>4]), 'Other superadmins cannot open the hidden account');
visibility_check(can_view_user_account($hidden, ['id'=>1]), 'Own profile remains accessible');
$GLOBALS['__lrdms_current_user'] = $hidden;
log_action('access', 'updated_user', 'visibility regression test');
$row = $pdo->query('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id=a.user_id')->fetch();
visibility_check((int)$row['user_id'] === 1 && $row['username_snapshot'] === 'hidden-admin' && $row['username'] === 'hidden-admin' && $row['action'] === 'updated_user', 'Hidden actor identity and action remain in audit queries');
