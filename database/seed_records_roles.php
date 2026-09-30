<?php
// Initial role templates. Reruns preserve existing roles and manual permissions.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run through the local PHP CLI.'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
$definitions = [
    'Records Encoder' => ['Reviews incoming metadata and runs OCR.', ['encoding.create', 'repository.view_all', 'repository.edit_metadata', 'search.run']],
    'Records Validator' => ['Reviews incoming records and registers them.', ['encoding.create', 'encoding.register_record', 'repository.view_all', 'repository.print_record', 'search.run']],
    'Records Custodian' => ['Manages registered records, versions, and public visibility.', ['repository.view_all', 'repository.edit_metadata', 'repository.manage_visibility', 'repository.print_record', 'version.amend', 'search.run']],
    'Records Viewer' => ['Views public records and verified records from the assigned office.', ['repository.view_public', 'repository.view_office', 'search.run']],
    'Auditor' => ['Reviews audit logs and exports security reports.', ['audit.view', 'audit.export']],
];
$pdo = get_db();
$pdo->beginTransaction();
$messages = [];
try {
    $permissionIds = [];
    foreach ($pdo->query('SELECT id, module, action FROM permissions')->fetchAll() as $permission) {
        $permissionIds[$permission['module'] . '.' . $permission['action']] = (int)$permission['id'];
    }
    $find = $pdo->prepare('SELECT id FROM roles WHERE name = ?');
    $insert = $pdo->prepare('INSERT INTO roles (name, description) VALUES (?, ?)');
    $grant = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
    foreach ($definitions as $name => [$description, $permissions]) {
        $find->execute([$name]);
        if ($find->fetchColumn()) { $messages[] = "Preserved existing role: $name"; continue; }
        foreach ($permissions as $permission) {
            if (!isset($permissionIds[$permission])) throw new RuntimeException('Missing permission: ' . $permission);
        }
        $insert->execute([$name, $description]);
        $roleId = (int)$pdo->lastInsertId();
        foreach ($permissions as $permission) $grant->execute([$roleId, $permissionIds[$permission]]);
        log_action('access', 'created_role', $name . '; permissions=' . implode(', ', $permissions));
        $messages[] = 'Created: ' . $name . ' (' . count($permissions) . ' permissions)';
    }
    $pdo->commit();
    echo implode(PHP_EOL, $messages) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
