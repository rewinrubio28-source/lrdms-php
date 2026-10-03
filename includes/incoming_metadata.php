<?php
// Called after the incoming record has been loaded and page access checked.
$incomingMetadataErrors = [];
$incomingMetadataValues = $doc;
$canEditIncomingMetadata = has_permission('repository', 'edit_metadata') && can_view_document(current_user(), $doc);
$incomingMetadataEditing = false;
[$incomingScope, $incomingParams] = document_visibility_clause(current_user());
$previousQuery = $pdo->prepare('SELECT d.id,d.doc_number,d.title FROM documents d WHERE d.verified_at IS NOT NULL AND d.next_version_id IS NULL AND d.doc_type=? AND (' . $incomingScope . ') ORDER BY d.created_at DESC');
$previousQuery->execute(array_merge([$doc['doc_type']], $incomingParams));
$incomingPreviousOptions = $previousQuery->fetchAll();
$incomingFields = ['title','doc_number','doc_type','sponsor','classification','originating_office','originating_division','submitter_position','responsible_custodian','related_legislative_item','previous_version_id','source_record_id','source_status'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_incoming_metadata') {
    if (!$canEditIncomingMetadata) {
        $incomingMetadataErrors[] = 'Your role cannot edit this record metadata.';
    } elseif (!validate_csrf()) {
        $incomingMetadataErrors[] = 'Security token expired. Refresh the page and try again.';
    } else {
        $incomingMetadataEditing = true;
        foreach ($incomingFields as $field) {
            $incomingMetadataValues[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
        }
        foreach (['title'=>500,'doc_number'=>60,'sponsor'=>150,'originating_office'=>180,'originating_division'=>180,'submitter_position'=>180,'responsible_custodian'=>180,'related_legislative_item'=>180,'source_record_id'=>180,'source_status'=>100] as $field=>$limit) {
            if (mb_strlen($incomingMetadataValues[$field]) > $limit || (in_array($field, ['title','doc_number'], true) && $incomingMetadataValues[$field] === '')) {
                $incomingMetadataErrors[] = ucwords(str_replace('_', ' ', $field)) . ' is required where applicable and must not exceed ' . $limit . ' characters.';
            }
        }
        if (!in_array($incomingMetadataValues['doc_type'], ['Ordinance', 'Resolution', 'Committee Report', 'Minutes', 'Other'], true)) $incomingMetadataErrors[] = 'Select a valid document type.';
        if (!in_array($incomingMetadataValues['classification'], ['PUBLIC','INTERNAL','RESTRICTED','CONFIDENTIAL'], true)) $incomingMetadataErrors[] = 'Select a valid classification.';
        $previousId = $incomingMetadataValues['previous_version_id'];
        if ($previousId !== '' && (!ctype_digit($previousId) || (int)$previousId < 1)) $incomingMetadataErrors[] = 'Select a valid previous version.';
        if (!$incomingMetadataErrors) {
            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE');
                $lock->execute([$doc['id']]);
                $current = $lock->fetch();
                if (!$current || $current['verified_at'] !== null || in_array($current['records_status'], ['Duplicate','Unauthorized Submission'], true)) throw new RuntimeException('This record has already been processed. Refresh the page.');
                $duplicate = $pdo->prepare('SELECT id FROM documents WHERE doc_number=? AND id<>? LIMIT 1');
                $duplicate->execute([$incomingMetadataValues['doc_number'], $doc['id']]);
                if ($duplicate->fetchColumn()) throw new RuntimeException('Another record already uses this document number.');
                if ($previousId !== '') {
                    $lock->execute([(int)$previousId]); $previous = $lock->fetch();
                    if (!$previous || !can_view_document(current_user(), $previous) || !$previous['verified_at'] || $previous['next_version_id'] !== null || $previous['doc_type'] !== $incomingMetadataValues['doc_type']) throw new RuntimeException('Choose a current registered version of the same document type.');
                }
                $assignments = implode(',', array_map(static function ($field) { return $field . '=?'; }, $incomingFields));
                $values = array_map(static function ($field) use ($incomingMetadataValues) { return $incomingMetadataValues[$field] === '' ? null : $incomingMetadataValues[$field]; }, $incomingFields);
                $values[] = $doc['id'];
                $pdo->prepare('UPDATE documents SET ' . $assignments . ' WHERE id=?')->execute($values);
                $changes = [];
                foreach ($incomingFields as $field) {
                    if ((string)($current[$field] ?? '') !== $incomingMetadataValues[$field]) $changes[$field] = ['from' => $current[$field], 'to' => $incomingMetadataValues[$field]];
                }
                if ($changes) log_action('encoding', 'updated_metadata', 'Record #' . $doc['id'] . ' ' . $current['doc_number'] . ': ' . json_encode($changes, JSON_UNESCAPED_UNICODE));
                $pdo->commit();
                $_SESSION['flash_success'] = 'Record metadata saved.';
                header('Location: ' . $incomingMetadataPage . '?id=' . (int)$doc['id']);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Incoming metadata update: ' . $e->getMessage());
                $incomingMetadataErrors[] = $e instanceof PDOException ? 'Could not save metadata. Please try again.' : $e->getMessage();
            }
        }
    }
}
