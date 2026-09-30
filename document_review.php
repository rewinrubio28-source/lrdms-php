<?php
// Compatibility entry point: all review screens use the document workspace.
require_once __DIR__ . '/includes/rbac.php';
require_permission('encoding', 'create');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: document.php?id=' . (int)($_GET['id'] ?? 0));
    exit;
}
// Preserve submissions from review tabs opened before the layout was unified.
$legacyActions = ['verify_release' => 'register_public', 'verify_keep_private' => 'register_private', 'run_ocr' => 'run_ocr'];
$legacyAction = $_POST['action'] ?? '';
if (is_string($legacyAction) && isset($legacyActions[$legacyAction])) {
    $_POST['review_action'] = $legacyActions[$legacyAction];
}
require __DIR__ . '/document.php';
