<?php
// Read-only rollout check; never prints account identifiers or secrets.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security_policy.php';
$missing=0;
foreach (get_db()->query('SELECT role_id,email FROM users WHERE is_active=1')->fetchAll() as $account) {
    if (privileged_mfa_required($account) && !filter_var($account['email'],FILTER_VALIDATE_EMAIL)) $missing++;
}
echo "Active privileged accounts missing a valid email: $missing\n";
$smtp=env_optional('SMTP_USERNAME','')!=='' && env_optional('SMTP_PASSWORD','')!=='';
echo 'SMTP credentials configured: ' . ($smtp?'yes':'no') . "\n";
echo "This does not test email delivery. Verify a real inbox before deploying mandatory MFA.\n";
exit($missing===0 && $smtp?0:1);
