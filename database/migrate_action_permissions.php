<?php
require_once __DIR__ . '/../config/database.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this migration with the local PHP CLI.'); }
$pdo = get_db();
$definitions = [
 ['encoding', 'register_record', 'Validate and register incoming records. Requires Encoding access and access to the record.'],
 ['access', 'manage_organization', 'Add offices, divisions, positions, and committee references.'],
 ['repository', 'manage_visibility', 'Change public visibility or release a public copy. Registration also requires Register Record.'],
 ['repository', 'print_record', 'Use the record summary Print tool. Does not prevent browser printing or saving files already visible.'],
 ['audit', 'export', 'Download the security report CSV. Requires Audit Trail view access.'],
];
$pdo->beginTransaction();
try {
 $stmt = $pdo->prepare('INSERT INTO permissions (module, action, description) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE module=? AND action=?)');
 foreach ($definitions as [$module, $action, $description]) $stmt->execute([$module, $action, $description, $module, $action]);
 $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
echo "Action permissions added. Enable them explicitly for each role.\n";
