<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/organization.php';
$pdo = get_db();
function account_org_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS: $message\n";
}
// Connection-local copies keep all live staff assignments and memberships untouched.
foreach (['users', 'user_committees'] as $table) {
    $pdo->exec("CREATE TEMPORARY TABLE org_test_$table AS SELECT * FROM $table");
    $pdo->exec("CREATE TEMPORARY TABLE $table LIKE org_test_$table");
    $pdo->exec("INSERT INTO $table SELECT * FROM org_test_$table");
}
$lists = organization_lists($pdo);
$positions = array_column($lists['positions'], 'id', 'name');
foreach (organization_chart_positions() as $name => $metadata) {
    account_org_check(isset($positions[$name]), 'Chart position available: ' . $name);
}
$office = array_column($lists['offices'], 'id', 'name')['Information and Communication Division'];
$section = array_column($lists['divisions'], 'id', 'name')['Records Management and Publication Section (RMPS)'];
$user = $pdo->query('SELECT id, role_id FROM users LIMIT 1')->fetch();
account_org_check((bool)$user, 'An account fixture is available');
$errors = [];
$values = organization_input($pdo, ['office_id' => $office, 'division_id' => $section, 'position_id' => $positions['Administrative Officer V']], $errors);
account_org_check(!$errors, 'Valid chart assignment accepted');
organization_save($pdo, (int)$user['id'], $values);
$saved = organization_user($pdo, (int)$user['id']);
account_org_check($saved['position_name'] === 'Administrative Officer V' && (int)$saved['division_id'] === (int)$section, 'Position and section persist together');
account_org_check((int)$pdo->query('SELECT role_id FROM users WHERE id='.(int)$user['id'])->fetchColumn() === (int)$user['role_id'], 'Assignment preserves access role');
$errors = [];
organization_input($pdo, ['division_id' => $section], $errors);
account_org_check((bool)$errors, 'Section without its parent division is rejected');
$errors = [];
organization_input($pdo, ['office_id' => $office, 'position_id' => $positions['Chief Administrative Officer']], $errors);
account_org_check(!$errors, 'Division leadership can have no section');
echo "Account organization checks passed; live accounts unchanged.\n";
