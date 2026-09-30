<?php
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../includes/audit.php';
require_permission('access', 'manage_roles');
header('Content-Type: application/json');
function role_error(string $message, int $status = 422): void {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') role_error('Use POST to create a role.', 405);
if (!validate_csrf()) role_error('Security token expired. Refresh the page and try again.', 403);
$name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
$description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
if ($name === '' || mb_strlen($name) > 60) role_error('Enter a role name of up to 60 characters.');
if (mb_strlen($description) > 255) role_error('Description must be 255 characters or fewer.');
if (in_array(strtolower($name), ['super admin', 'administrator'], true)) role_error('Choose a custom role name.');
$raw = $_POST['permission_ids'] ?? [];
if (!is_array($raw)) role_error('Invalid permission selection.');
$pdo = get_db();
$valid = array_map('intval', $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN));
$ids = [];
foreach ($raw as $value) {
    if (!is_scalar($value) || !ctype_digit((string)$value) || !in_array((int)$value, $valid, true)) role_error('Select valid permissions.');
    $ids[] = (int)$value;
}
$ids = array_unique($ids);
try {
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO roles (name, description) VALUES (?, ?)')->execute([$name, $description ?: null]);
    $id = (int)$pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
    foreach ($ids as $permissionId) $stmt->execute([$id, $permissionId]);
    log_action('access', 'created_role', $name . ' (permissions=' . count($ids) . ')');
    $pdo->commit();
    echo json_encode(['id' => $id, 'name' => $name, 'assignable' => my_role_rank() > 1]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) role_error('A role with that name already exists.');
    error_log('Role creation failed: ' . $e->getMessage());
    role_error('Unable to create the role. Please try again.', 500);
}
