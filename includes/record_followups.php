<?php
// Encoding permission and session are checked by encoding.php before inclusion.
$followupErrors = [];
$followupMethods = ['Email', 'Phone', 'In person', 'Official letter', 'Other'];
$followupNow = new DateTimeImmutable();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_followup') {
    $followupId = (int)($_POST['document_id'] ?? 0);
    $contact = is_string($_POST['contact_person'] ?? null) ? trim($_POST['contact_person']) : '';
    $method = is_string($_POST['contact_method'] ?? null) ? $_POST['contact_method'] : '';
    $note = is_string($_POST['followup_note'] ?? null) ? trim($_POST['followup_note']) : '';
    $nextDate = is_string($_POST['next_due_date'] ?? null) ? $_POST['next_due_date'] : '';
    $next = DateTimeImmutable::createFromFormat('!Y-m-d', $nextDate);
    if (!validate_csrf()) $followupErrors[] = 'Your session token expired. Refresh the page and try again.';
    if ($contact === '' || mb_strlen($contact) > 180) $followupErrors[] = 'Enter a contact or office name, up to 180 characters.';
    if (!in_array($method, $followupMethods, true)) $followupErrors[] = 'Select a contact method.';
    if ($note === '' || mb_strlen($note) > 4000) $followupErrors[] = 'Enter follow-up notes, up to 4,000 characters.';
    if (!$next || $next->format('Y-m-d') !== $nextDate || $next <= $followupNow->setTime(0, 0)) $followupErrors[] = 'Choose a next follow-up date after today.';
    if (!$followupErrors) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE');
            $lock->execute([$followupId]);
            $target = $lock->fetch();
            if (!$target || !can_view_document($user, $target)) throw new RuntimeException('Record not found or access denied.');
            if ($target['verified_at'] !== null || in_array($target['records_status'], ['Duplicate','Unauthorized Submission'], true) || $target['source_system'] === 'Manual Encoding') throw new RuntimeException('This record is no longer in the incoming queue.');
            $pdo->prepare('INSERT INTO record_followups (document_id,recorded_by,contact_person,contact_method,note,next_due_at) VALUES (?,?,?,?,?,?)')->execute([$followupId, $user['id'], $contact, $method, $note, $next->format('Y-m-d H:i:s')]);
            $pdo->prepare('UPDATE documents SET follow_up_due_at=? WHERE id=?')->execute([$next->format('Y-m-d H:i:s'), $followupId]);
            log_action('encoding', 'recorded_followup', $target['doc_number'] . ': ' . $method . ' with ' . $contact . '; next follow-up ' . $nextDate . '; ' . $note);
            $pdo->commit();
            $_SESSION['flash_success'] = 'Follow-up recorded. Next reminder: ' . $next->format('M j, Y') . '.';
            header('Location: encoding.php?tab=followups');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Follow-up save: ' . $e->getMessage());
            $followupErrors[] = $e instanceof PDOException ? 'Could not save the follow-up. Please try again.' : $e->getMessage();
        }
    }
}

$pendingRows = $pdo->query("SELECT d.*, c.name AS committee_name,
    (SELECT COUNT(*) FROM record_followups f WHERE f.document_id=d.id) AS followup_count,
    (SELECT MAX(f.created_at) FROM record_followups f WHERE f.document_id=d.id) AS last_followup_at
    FROM documents d LEFT JOIN committees c ON c.id=d.committee_id
    WHERE d.verified_at IS NULL AND d.source_system <> 'Manual Encoding' AND d.records_status NOT IN ('Duplicate','Unauthorized Submission')
    ORDER BY COALESCE(d.follow_up_due_at, DATE_ADD(COALESCE(d.pending_since,d.received_at,d.created_at),INTERVAL 15 DAY)), d.id")->fetchAll();
$pendingRows = array_values(array_filter($pendingRows, function ($row) use ($user) { return can_view_document($user, $row); }));
$followupDueCount = 0;
$pendingDaysTotal = 0;
foreach ($pendingRows as &$row) {
    $start = new DateTimeImmutable($row['pending_since'] ?: ($row['received_at'] ?: $row['created_at']));
    $due = !empty($row['follow_up_due_at']) ? new DateTimeImmutable($row['follow_up_due_at']) : $start->modify('+15 days');
    $row['pending_days'] = max(0, (int)$start->diff($followupNow)->format('%r%a'));
    $row['due_date'] = $due;
    $row['is_due'] = $due <= $followupNow;
    $followupDueCount += $row['is_due'] ? 1 : 0;
    $pendingDaysTotal += $row['pending_days'];
}
unset($row);
$followupQuery = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$followupFilter = in_array($_GET['filter'] ?? '', ['due', 'scheduled'], true) ? $_GET['filter'] : 'all';
$visibleFollowups = array_values(array_filter($pendingRows, function ($row) use ($followupQuery, $followupFilter) {
    if ($followupFilter === 'due' && !$row['is_due']) return false;
    if ($followupFilter === 'scheduled' && $row['is_due']) return false;
    return $followupQuery === '' || mb_stripos(implode(' ', [$row['doc_number'], $row['title'], $row['sponsor'], $row['committee_name'], $row['source_system']]), $followupQuery) !== false;
}));
$followupPage = max(1, (int)($_GET['page'] ?? 1));
$followupPages = max(1, (int)ceil(count($visibleFollowups) / 20));
$followupPage = min($followupPage, $followupPages);
$visibleFollowups = array_slice($visibleFollowups, ($followupPage - 1) * 20, 20);
$followupHistory = [];
if ($visibleFollowups) {
    $ids = array_column($visibleFollowups, 'id');
    $historyStmt = $pdo->prepare('SELECT f.*, u.full_name FROM record_followups f JOIN users u ON u.id=f.recorded_by WHERE f.document_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY f.created_at DESC, f.id DESC');
    $historyStmt->execute($ids);
    foreach ($historyStmt->fetchAll() as $event) $followupHistory[$event['document_id']][] = $event;
}
