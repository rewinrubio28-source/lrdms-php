<?php
require_once __DIR__ . '/../config/database.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../includes/rbac.php';
    require_permission('access', 'manage_roles');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validate_csrf()) {
        http_response_code(403);
        exit('Use the local PHP CLI to apply this migration.');
    }
}
$pdo = get_db();
$definitions = [
    'view_memberships' => 'View verified records belonging to any assigned committee, including the primary committee. Does not grant editing or intake access.',
    'view_office' => 'View verified records whose originating office matches the user office name. Missing or unmatched origin grants no access.',
    'view_division' => 'View verified records whose originating office AND division match the user assignments. Does not grant editing or intake access.',
];
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO permissions (module, action, description) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE module = ? AND action = ?)');
    foreach ($definitions as $action => $description) {
        $stmt->execute(['repository', $action, $description, 'repository', $action]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
echo 'Organizational permission options added. No role grants were changed.';
