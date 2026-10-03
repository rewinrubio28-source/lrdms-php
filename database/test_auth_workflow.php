<?php
// All mutations use connection-local temporary tables, never real accounts.
if (PHP_SAPI !== 'cli') exit;
session_start(['save_path'=>sys_get_temp_dir()]);
require_once __DIR__ . '/../includes/auth.php';
if (!in_array(DB_HOST, ['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Local test only.');
$pdo=get_db();
foreach (['users','user_sessions','audit_log','password_reset_tokens','password_reset_codes'] as $table) {
    $ddl=$pdo->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_NUM)[1];
    $ddl=implode("\n",array_filter(explode("\n",$ddl),static fn($line)=>!str_starts_with(trim($line),'CONSTRAINT')));
    $ddl=preg_replace('/,\n\)/',"\n)",$ddl);
    $pdo->exec(str_replace('CREATE TABLE','CREATE TEMPORARY TABLE',$ddl));
}
function auth_check(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['auth_checks'][]=$message; // Defer output until session/header operations finish.
}
$role=null;
foreach ($pdo->query('SELECT id AS role_id, name AS role_name FROM roles')->fetchAll() as $candidate) {
    if (!privileged_mfa_required($candidate)) { $role=$candidate['role_id']; break; }
}
if (!$role) throw new RuntimeException('At least one role is required.');
$pdo->prepare('INSERT INTO users (id,full_name,username,email,password_hash,role_id) VALUES (1,?,?,?,?,?)')
    ->execute(['Test User','auth-test','auth-test@example.invalid',password_hash('Test-password-123',PASSWORD_DEFAULT),$role]);
auth_check(attempt_login('missing','wrong')===false,'Unknown user rejected');
for ($i=0;$i<LOGIN_MAX_ATTEMPTS;$i++) attempt_login('auth-test','wrong');
auth_check(attempt_login('auth-test','Test-password-123')==='locked','Lockout blocks even a correct password');
$pdo->exec('UPDATE users SET locked_until=DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
auth_check(attempt_login('auth-test','Test-password-123')==='success','Login succeeds after lockout expires');
auth_check(current_user()['id']==1,'Issued session resolves user');
$pdo->exec('UPDATE user_sessions SET is_active=0');
$GLOBALS['__lrdms_current_user']=false;
auth_check(current_user()===null,'Revoked session rejected');
$pdo->exec('UPDATE users SET totp_enabled=1');
auth_check(attempt_login('auth-test','Test-password-123')==='2fa' && !isset($_SESSION['session_token']),'MFA challenge does not create authenticated session');
$pdo->exec("INSERT INTO password_reset_codes (user_id,code,expires_at) VALUES (1,'123456',DATE_ADD(NOW(), INTERVAL 5 MINUTE)),(1,'654321',DATE_SUB(NOW(), INTERVAL 1 MINUTE))");
auth_check(validate_password_reset_code('auth-test@example.invalid','654321')===null,'Expired reset code rejected');
auth_check(reset_password_with_code('auth-test@example.invalid','123456','Changed-password-123'),'Valid reset code changes password');
auth_check(!reset_password_with_code('auth-test@example.invalid','123456','Another-password'),'Reset code cannot be reused');
$pdo->exec("INSERT INTO password_reset_tokens (user_id,token,expires_at) VALUES (1,'test-token',DATE_ADD(NOW(), INTERVAL 5 MINUTE))");
auth_check(reset_password('test-token','Final-password-123') && !reset_password('test-token','again'),'Reset link succeeds once only');
$pdo->exec('UPDATE users SET totp_enabled=0');
auth_check(attempt_login('auth-test','Final-password-123')==='success','New password works');
$pdo->exec('UPDATE user_sessions SET last_seen=DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE is_active=1');
$GLOBALS['__lrdms_current_user']=false;
auth_check(current_user()===null,'Inactive session is rejected and revoked');
attempt_login('auth-test','Final-password-123');
$pdo->exec('UPDATE user_sessions SET created_at=DATE_SUB(NOW(), INTERVAL 13 HOUR) WHERE is_active=1');
$GLOBALS['__lrdms_current_user']=false;
auth_check(current_user()===null,'Absolute session expiry is enforced after recent activity');
attempt_login('auth-test','Final-password-123');
$_POST['csrf_token']=['malformed'];
auth_check(validate_csrf()===false,'Array-shaped CSRF token rejected without exception');
$token=$_SESSION['session_token'];
do_logout();
$stmt=$pdo->prepare('SELECT is_active FROM user_sessions WHERE session_token=?'); $stmt->execute([$token]);
auth_check((int)$stmt->fetchColumn()===0 && current_user()===null,'Logout revokes session');
foreach ($GLOBALS['auth_checks'] as $message) echo "PASS: $message\n";
echo "Existing accounts untouched; email delivery was not exercised.\n";
