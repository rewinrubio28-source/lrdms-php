<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rbac.php';
require_login();
$user = current_user();
$id = (int)($_GET['id'] ?? 0);
if ($id !== (int)$user['id'] && !has_permission('access', 'manage_users')) {
    http_response_code(403); exit;
}
$stmt = get_db()->prepare('SELECT mime_type, image_data FROM user_profile_photos WHERE user_id = ?');
$stmt->execute([$id]);
$photo = $stmt->fetch();
if (!$photo || !in_array($photo['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404); exit;
}
header('Content-Type: ' . $photo['mime_type']);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header('Cache-Control: private, no-store');
echo $photo['image_data'];
