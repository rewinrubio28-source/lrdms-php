<?php
require_once __DIR__ . '/council_terms.php';
// document.php has already checked visibility for this exact record.
$councilTermError = '';
$canAssignCouncilTerm = has_permission('repository', 'edit_metadata');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_council_term') {
    $term = is_string($_POST['council_term'] ?? null) ? trim($_POST['council_term']) : '';
    if (!$canAssignCouncilTerm) $councilTermError = 'Your role cannot assign council terms.';
    elseif (!validate_csrf()) $councilTermError = 'Session token expired. Refresh and try again.';
    elseif ($term !== '' && (!ctype_digit($term) || (int)$term < 1 || (int)$term > 999)) $councilTermError = 'Enter a council term number from 1 to 999, or leave it unassigned.';
    else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT council_term FROM documents WHERE id=? FOR UPDATE');
            $lock->execute([(int)$doc['id']]);
            $previousTerm = $lock->fetchColumn();
            $value = $term === '' ? null : (int)$term;
            $pdo->prepare('UPDATE documents SET council_term=? WHERE id=?')->execute([$value, (int)$doc['id']]);
            log_action('repository', 'assigned_council_term', 'Record #' . (int)$doc['id'] . ': council term ' . ($previousTerm ?: 'unassigned') . ' -> ' . ($value ?? 'unassigned'));
            $pdo->commit();
            header('Location: document.php?id=' . (int)$doc['id'] . $documentReturnSuffix);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Council term assignment: ' . $e->getMessage());
            $councilTermError = 'Could not save the council term. Please try again.';
        }
    }
}
