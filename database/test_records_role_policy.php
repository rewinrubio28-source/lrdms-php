<?php
if (PHP_SAPI !== 'cli') exit;
session_start(['save_path'=>sys_get_temp_dir()]);
require_once __DIR__ . '/../includes/record_processing.php';
require_once __DIR__ . '/../includes/records_role_policy.php';
$pdo = get_db();
// Shadow policy tables on this connection; live accounts and permissions remain untouched.
foreach (['roles','role_permissions','offices','divisions','positions','audit_log'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl = implode("\n", array_filter(explode("\n",$ddl), static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT')));
    $ddl = preg_replace('/,\n\)/', "\n)", $ddl);
    $pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl));
}
$pdo->exec('CREATE TEMPORARY TABLE application_migrations (name VARCHAR(100) PRIMARY KEY, applied_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO roles (name) VALUES ('Super Admin'),('Unrelated custom role')");
$accountsBefore = $pdo->query('SELECT id,role_id,password_hash,is_active FROM users ORDER BY id')->fetchAll();
require __DIR__ . '/migrate_records_role_policy.php';
function policy_check($ok, $message) {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
$ids = $pdo->query('SELECT name,id FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
policy_check(isset($ids['Super Admin'], $ids['Unrelated custom role']), 'Super Admin and custom roles preserved');
policy_check($accountsBefore === $pdo->query('SELECT id,role_id,password_hash,is_active FROM users ORDER BY id')->fetchAll(), 'Account identities, assignments and credentials unchanged');
$allPermissions = (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
policy_check((int)$pdo->query('SELECT COUNT(*) FROM role_permissions WHERE role_id='.(int)$ids['Super Admin'])->fetchColumn() === $allPermissions, 'Super Admin retains all permissions');
$registered = ['id'=>999999,'verified_at'=>'2026-01-01','is_public'=>0,'status'=>'Enacted','owner_id'=>999998,'committee_id'=>null];
$incoming = array_merge($registered, ['verified_at'=>null]);
foreach (['Records Assistant','Division Viewer'] as $name) {
    $user = ['id'=>999997,'role_id'=>$ids[$name],'role_name'=>$name,'committee_id'=>null];
    policy_check(can_view_document($user,$registered) && !can_view_document($user,$incoming), "$name can read registered records but not guessed intake URLs");
    try { process_record($pdo,$user,999999,'register_private'); throw new LogicException('Unexpected registration permission'); }
    catch (RuntimeException $e) { policy_check(str_contains($e->getMessage(),'permissions are required'), "$name registration denied before mutation"); }
}
$encoder = ['id'=>999997,'role_id'=>$ids['Records Encoder'],'committee_id'=>null];
try { process_record($pdo,$encoder,999999,'register_private'); throw new LogicException('Encoder allowed registration'); }
catch (RuntimeException $e) { policy_check(str_contains($e->getMessage(),'permissions are required'),'Encoder cannot register records'); }
$validator = ['id'=>999997,'role_id'=>$ids['Records Validator'],'committee_id'=>null];
try { process_record($pdo,$validator,999999,'register_public'); throw new LogicException('Validator allowed publication'); }
catch (RuntimeException $e) { policy_check(str_contains($e->getMessage(),'Public release permission'),'Validator cannot publish records'); }
foreach (['Administrator','Auditor'] as $name) {
    $user = ['id'=>999997,'role_id'=>$ids[$name],'committee_id'=>null];
    policy_check(!can_view_document($user,$registered) && !can_view_document($user,array_merge($registered,['is_public'=>1])), "$name has no implicit repository access");
}
policy_check(_role_has_permission($ids['Records Supervisor'],'repository','review_copy_requests') && !_role_has_permission($ids['Records Supervisor'],'access','manage_users'),'Supervisor handles copy requests without managing accounts');
$roleCount = (int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
$auditCount = (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
$pdo->exec('DELETE FROM role_permissions WHERE role_id='.(int)$ids['Records Encoder']);
require __DIR__ . '/migrate_records_role_policy.php';
policy_check((int)$pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn()===$roleCount && (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()===$auditCount && !_role_has_permission($ids['Records Encoder'],'encoding','create'), 'Rerun preserves manual permissions and adds no duplicate roles or audit events');
policy_check((int)$pdo->query('SELECT COUNT(*) FROM divisions')->fetchColumn()===2,'RMPS and ITTS sections created');
echo "Role-policy checks passed using temporary fixtures.\n";
