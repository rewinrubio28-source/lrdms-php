<?php
require_once __DIR__ . '/rbac.php';
function can_download_record(array $user, array $doc): bool {
    if (empty($doc['verified_at']) || !can_view_document($user, $doc)) return false;
    if (_role_has_permission($user['role_id'], 'repository', 'download')) return true;
    $stmt = get_db()->prepare("SELECT 1 FROM document_copy_requests WHERE requester_id=? AND document_id=? AND status='Approved' LIMIT 1");
    $stmt->execute([$user['id'], $doc['id']]);
    return (bool)$stmt->fetchColumn();
}
function retrieval_attachments(PDO $pdo, array $doc): array {
    $stmt = $pdo->prepare('SELECT id, file_path FROM document_attachments WHERE document_id=? ORDER BY sort_order,id');
    $stmt->execute([$doc['id']]);
    $files = $stmt->fetchAll();
    if (!$files && !empty($doc['file_path'])) $files[] = ['id' => 0, 'file_path' => $doc['file_path']];
    return $files;
}
