<?php
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';

function process_record(PDO $pdo, array $user, int $id, string $action, string $note = ''): void {
    if (!_role_has_permission($user['role_id'], 'encoding', 'create') || !_role_has_permission($user['role_id'], 'encoding', 'register_record')) throw new RuntimeException('Encoding and Validate / Register Record permissions are required.');
    $register = in_array($action, ['register_private', 'register_public'], true);
    if (!$register && !in_array($action, ['Validated', 'Returned for Correction', 'Duplicate', 'Unauthorized Submission'], true)) throw new RuntimeException('Invalid review action.');
    if ($action === 'register_public' && !_role_has_permission($user['role_id'], 'repository', 'manage_visibility')) throw new RuntimeException('Public release permission is required.');
    if (!$register && (trim($note) === '' || mb_strlen($note) > 4000)) throw new RuntimeException('Enter a review note of up to 4,000 characters.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE');
        $stmt->execute([$id]); $doc = $stmt->fetch();
        if (!$doc || !can_view_document($user, $doc)) throw new RuntimeException('Record unavailable or access denied.');
        if ($doc['verified_at'] !== null || $doc['registered_at'] !== null || $doc['records_status'] === 'Registered') throw new RuntimeException('This record is already registered.');
        if (in_array($doc['records_status'], ['Duplicate', 'Unauthorized Submission'], true)) throw new RuntimeException('This submission is closed and cannot be registered.');
        if ($register && !in_array($doc['records_status'], ['Submitted', 'Pending Validation', 'Validated'], true)) throw new RuntimeException('Complete the correction review and validate this record before registration.');
        if ($register || $action === 'Validated') {
            foreach (['doc_number', 'title', 'doc_type', 'source_system'] as $field) if (trim((string)$doc[$field]) === '') throw new RuntimeException('Complete the required record metadata before validation.');
        }
        if ($register && !empty($doc['previous_version_id'])) {
            if ((int)$doc['previous_version_id'] === $id) throw new RuntimeException('A record cannot be its own previous version.');
            $stmt->execute([$doc['previous_version_id']]); $previous = $stmt->fetch();
            if (!$previous || !can_view_document($user, $previous) || !$previous['verified_at'] || $previous['next_version_id'] !== null || $previous['doc_type'] !== $doc['doc_type']) throw new RuntimeException('The previous version is unavailable, unregistered, or no longer current.');
            $pdo->prepare('UPDATE documents SET next_version_id=? WHERE id=?')->execute([$id, $previous['id']]);
        }
        $state = $register ? 'Registered' : $action;
        if ($register) {
            $public = $action === 'register_public';
            $pdo->prepare("UPDATE documents SET records_status='Registered', verified_at=NOW(), registered_at=NOW(), is_public=?, classification=?, validation_note=NULL, pending_since=NULL, follow_up_due_at=NULL WHERE id=?")
                ->execute([(int)$public, $public ? 'PUBLIC' : $doc['classification'], $id]);
        } else {
            $pdo->prepare('UPDATE documents SET records_status=?,validation_note=? WHERE id=?')->execute([$state, $note, $id]);
        }
        $history = $pdo->prepare('INSERT INTO record_validation_history (document_id,actor_id,action,note) VALUES (?,?,?,?)');
        if ($register && $doc['records_status'] !== 'Validated') $history->execute([$id, $user['id'], 'Validated', 'Required metadata checked during registration.']);
        $history->execute([$id, $user['id'], $state, $note ?: null]);
        $pdo->prepare('UPDATE integration_receipts SET processing_status=?,error_message=? WHERE lrdms_record_id=?')->execute([$state, $register ? null : $note, $id]);
        log_action('encoding', 'record_reviewed', $doc['doc_number'] . ': ' . $state);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
