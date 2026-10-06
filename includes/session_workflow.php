<?php
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/storage.php';

function session_tracking_available(PDO $pdo): bool {
    static $ready;
    return $ready ??= (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_sessions'")->fetchColumn();
}
function session_stage_labels(): array {
    return ['agenda_pending'=>'For Agenda — Pending External Delivery', 'agenda_sent'=>'Agenda — Manually Sent',
        'first_session'=>'1st Session — Documents Received', 'second_session'=>'2nd Session',
        'amendment'=>'2nd Session — Documents for Amendment', 'amendment_received'=>'2nd Session — Amendment Received',
        'third_session'=>'3rd Session — Final Document', 'signed_pending'=>'Signed Copy — Pending Check', 'signed'=>'Signed Copy on File'];
}
function session_action_labels(): array {
    return ['send_agenda'=>'Send to Agenda for Session', 'confirm_delivery'=>'Record Manual Agenda Delivery',
        'receive_session'=>'Receive Session Documents', 'request_amendment'=>'Committee Request for Amendment',
        'second_session'=>'Proceed to 2nd Session', 'receive_amendment'=>'Receive Amended Document',
        'follow_up'=>'Record Committee Follow-up', 'third_session'=>'Proceed to 3rd Session',
        'upload_final'=>'Upload Final PDF', 'upload_signed'=>'Upload Signed Copy', 'verify_signed'=>'Confirm Signed Copy Checked'];
}
function session_actions(string $stage): array {
    return [
        ''=>['send_agenda'], 'agenda_pending'=>['confirm_delivery'], 'agenda_sent'=>['receive_session'],
        'first_session'=>['request_amendment','second_session'], 'second_session'=>['request_amendment','third_session'],
        'amendment'=>['receive_amendment','follow_up'], 'amendment_received'=>['request_amendment','third_session'],
        'third_session'=>['upload_final','upload_signed'], 'signed_pending'=>['upload_signed','verify_signed'], 'signed'=>[]
    ][$stage] ?? [];
}
function session_can_manage(array $user): bool {
    return _role_has_permission($user['role_id'], 'encoding', 'create')
        && _role_has_permission($user['role_id'], 'encoding', 'register_record');
}
function session_state(PDO $pdo, int $id): ?array {
    $stmt=$pdo->prepare('SELECT * FROM document_sessions WHERE document_id=?');
    $stmt->execute([$id]); return $stmt->fetch() ?: null;
}
function session_event(PDO $pdo, int $id, ?int $actor, string $action, string $stage, string $note, ?int $attachment=null): void {
    $pdo->prepare('INSERT INTO document_session_events (document_id,actor_id,action,stage,note,attachment_id) VALUES (?,?,?,?,?,?)')
        ->execute([$id,$actor,$action,$stage,$note,$attachment]);
}
// Notification writes share the workflow transaction: failed delivery can be retried without losing the reminder.
function session_notify(PDO $pdo, array $doc, string $type, string $message): void {
    $stmt=$pdo->prepare("SELECT DISTINCT u.* FROM users u JOIN roles r ON r.id=u.role_id
        WHERE u.is_active=1 AND (r.name IN ('Records Officer','Records Supervisor','Records Validator','Super Admin') OR
        (r.name='Committee Secretary' AND (u.committee_id=? OR EXISTS
        (SELECT 1 FROM user_committees uc WHERE uc.user_id=u.id AND uc.committee_id=?))))");
    $stmt->execute([$doc['committee_id'],$doc['committee_id']]);
    $insert=$pdo->prepare('INSERT INTO notifications (user_id,type,document_id,message) VALUES (?,?,?,?)');
    foreach ($stmt->fetchAll() as $recipient) {
        if (can_view_document($recipient,$doc)) $insert->execute([$recipient['id'],$type,$doc['id'],mb_substr($message,0,500)]);
    }
}
// 24-hour Agenda rule: an intake receipt must reach Send to Agenda within 24
// hours of receipt. The countdown starts at pending_since, falling back to
// received_at, then created_at; an explicit agenda_monitoring_due_at wins.
function session_agenda_due(array $doc, ?array $sessionState): ?DateTimeImmutable {
    if (!empty($sessionState)) return null;
    if (!empty($doc['verified_at'])) return null;
    if (($doc['source_system'] ?? '') === 'Manual Encoding') return null;
    if (in_array($doc['records_status'] ?? '', ['Duplicate','Unauthorized Submission'], true)) return null;
    try {
        if (!empty($doc['agenda_monitoring_due_at'])) return new DateTimeImmutable($doc['agenda_monitoring_due_at']);
        $start = $doc['pending_since'] ?? $doc['received_at'] ?? $doc['created_at'] ?? null;
        if (!$start) return null;
        return (new DateTimeImmutable($start))->modify('+24 hours');
    } catch (Throwable $e) { return null; }
}
// Fires once per overdue receipt, same pattern as the amendment reminders:
// a bell notification plus an immutable history event. Sending to Agenda
// marks the notification read, which stops further reminders.
function session_send_agenda_reminders(PDO $pdo): int {
    if (!session_tracking_available($pdo)) return 0;
    $ids=$pdo->query("SELECT d.id FROM documents d LEFT JOIN document_sessions s ON s.document_id=d.id WHERE s.document_id IS NULL AND d.verified_at IS NULL AND d.source_system<>'Manual Encoding' AND d.records_status NOT IN ('Duplicate','Unauthorized Submission') AND COALESCE(d.agenda_monitoring_due_at,DATE_ADD(COALESCE(d.pending_since,d.received_at,d.created_at),INTERVAL 24 HOUR))<NOW() AND NOT EXISTS (SELECT 1 FROM notifications n WHERE n.document_id=d.id AND n.type='agenda_overdue') LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    $count=0;
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $doc=$stmt->fetch();
            $state=session_state($pdo,(int)$id);
            $due=$doc ? session_agenda_due($doc,$state) : null;
            $nochance=$pdo->prepare("SELECT COUNT(*) FROM notifications WHERE document_id=? AND type='agenda_overdue'"); $nochance->execute([$id]);
            if ($doc && $due && $due<new DateTimeImmutable() && (int)$nochance->fetchColumn()===0) {
                session_notify($pdo,$doc,'agenda_overdue',$doc['doc_number'].': Not sent to Agenda within 24 hours of receipt. Send it to Agenda for Session now.');
                session_event($pdo,(int)$id,null,'agenda_overdue','','24-hour Agenda deadline elapsed; Send to Agenda for Session is overdue.');
                $count++;
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Agenda reminder: '.$e->getMessage()); }
    }
    return $count;
}
function session_process(PDO $pdo, array $user, int $id, string $action, int $revision, string $note, ?array $upload=null, int $committeeId=0): void {
    if (!session_can_manage($user)) throw new RuntimeException('Records validation permission is required.');
    if (!session_tracking_available($pdo)) throw new RuntimeException('Apply the session tracking database migration first.');
    if (trim($note)==='' || mb_strlen($note)>4000) throw new InvalidArgumentException('Provide a reference or note of 1 to 4,000 characters.');
    $stored=null;
    $pdo->beginTransaction();
    try {
        $lock=$pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE'); $lock->execute([$id]); $doc=$lock->fetch();
        if (!$doc || !can_view_document($user,$doc)) throw new RuntimeException('Document unavailable.');
        if (in_array($doc['records_status'],['Duplicate','Unauthorized Submission'],true)) throw new RuntimeException('This submission is closed.');
        $state=session_state($pdo,$id); $stage=$state['stage'] ?? '';
        if ($revision !== (int)($state['revision'] ?? 0)) throw new RuntimeException('The workflow changed. Refresh before continuing.');
        if (!in_array($action,session_actions($stage),true)) throw new RuntimeException('This action is not available at the current session stage.');
        $next=$stage; $attachment=null;
        if ($action==='send_agenda') {
            if ($doc['verified_at']===null) {
                require_once __DIR__.'/record_processing.php';
                if (!in_array($doc['records_status'],['Submitted','Pending Validation','Validated'],true)) throw new RuntimeException('Resolve corrections before sending to Agenda.');
                process_record($pdo,$user,$id,'Validated','Verified for Agenda: '.$note);
            }
            $next='agenda_pending';
        } elseif ($action==='confirm_delivery') $next='agenda_sent';
        elseif ($action==='receive_session') $next='first_session';
        elseif ($action==='second_session') $next='second_session';
        elseif ($action==='request_amendment') {
            $committeeId=$committeeId ?: (int)$doc['committee_id'];
            $committee=$pdo->prepare('SELECT id FROM committees WHERE id=?'); $committee->execute([$committeeId]);
            if (!$committee->fetchColumn()) throw new RuntimeException('Select the committee receiving the amendment request.');
            $pdo->prepare('UPDATE documents SET committee_id=? WHERE id=?')->execute([$committeeId,$id]);
            $doc['committee_id']=$committeeId;
            $note='Committee #'.$committeeId.': '.$note;
            $next='amendment';
        } elseif ($action==='third_session') $next='third_session';
        elseif ($action==='verify_signed') {
            if (empty($state['signed_attachment_id'])) throw new RuntimeException('Upload the signed copy first.');
            $next='signed';
        }
        if (in_array($action,['receive_amendment','upload_final','upload_signed'],true) || ($action==='receive_session' && $upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)) {
            if ($action==='upload_signed' && empty($state['final_attachment_id'])) throw new RuntimeException('Upload the final PDF before its signed copy.');
            require_once __DIR__.'/upload_validation.php';
            if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($upload['name'] ?? null) || !is_string($upload['tmp_name'] ?? null)) throw new RuntimeException('Select a document file up to 25 MB.');
            $name=basename($upload['name']);
            if (in_array($action,['upload_final','upload_signed'],true) && strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='pdf') throw new RuntimeException('Use a PDF for the final or scanned signed document.');
            $error=document_upload_error($upload['tmp_name'],$name);
            if ($error) throw new RuntimeException($error);
            // Older records may store their only original in documents.file_path.
            // Keep it retrievable when the first session attachment is added.
            if (!empty($doc['file_path'])) {
                $original=$pdo->prepare('SELECT id FROM document_attachments WHERE document_id=? AND file_path=? LIMIT 1');
                $original->execute([$id,$doc['file_path']]);
                if (!$original->fetchColumn()) $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) VALUES (?,?,?, -1)')
                    ->execute([$id,$doc['file_path'],basename($doc['file_path'])]);
            }
            $local=null; $stored=storage_store_upload($upload['tmp_name'],$name,$local);
            if (!$stored) throw new RuntimeException('Could not store the document file.');
            $order=$pdo->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM document_attachments WHERE document_id=?'); $order->execute([$id]);
            $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) VALUES (?,?,?,?)')->execute([$id,$stored,$name,$order->fetchColumn()]);
            $attachment=(int)$pdo->lastInsertId();
            if ($action==='receive_amendment') $next='amendment_received';
            if ($action==='upload_signed') $next='signed_pending';
        }
        if (!$state) $pdo->prepare('INSERT INTO document_sessions (document_id,stage) VALUES (?,?)')->execute([$id,$next]);
        else $pdo->prepare('UPDATE document_sessions SET stage=?,revision=revision+1,updated_at=NOW() WHERE document_id=?')->execute([$next,$id]);
        if ($action==='receive_session' && $doc['verified_at']===null) {
            require_once __DIR__.'/record_processing.php';
            process_record($pdo,$user,$id,'register_private','Received from 1st session: '.$note);
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE document_id=? AND type='incoming_document'")->execute([$id]);
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE document_id=? AND type='agenda_overdue'")->execute([$id]);
            $doc['verified_at']=date('Y-m-d H:i:s');
        }
        if ($action==='request_amendment') {
            // Calendar days, measured from this recorded dispatch; no external delivery is claimed.
            $started=new DateTimeImmutable(); $due=$started->modify('+15 days');
            $pdo->prepare('UPDATE document_sessions SET amendment_started_at=?,amendment_due_at=?,overdue_notified_at=NULL WHERE document_id=?')->execute([$started->format('Y-m-d H:i:s'),$due->format('Y-m-d H:i:s'),$id]);
            session_notify($pdo,$doc,'amendment_day1',$doc['doc_number'].': Day 1 — amendment requested. Due '.$due->format('M j, Y g:i A').'.');
        }
        if ($action==='receive_amendment') $pdo->prepare("UPDATE notifications SET is_read=1 WHERE document_id=? AND type IN ('amendment_day1','amendment_overdue')")->execute([$id]);
        if ($action==='send_agenda') $pdo->prepare("UPDATE notifications SET is_read=1 WHERE document_id=? AND type='agenda_overdue'")->execute([$id]);
        if ($action==='upload_final') $pdo->prepare('UPDATE document_sessions SET final_attachment_id=? WHERE document_id=?')->execute([$attachment,$id]);
        if ($action==='upload_signed') $pdo->prepare('UPDATE document_sessions SET signed_attachment_id=? WHERE document_id=?')->execute([$attachment,$id]);
        session_event($pdo,$id,(int)$user['id'],$action,$next,$note,$attachment);
        log_action('repository','session_'.$action,$doc['doc_number'].': '.$note);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Remove only a new local file from this failed transaction, never an existing attachment.
        if ($stored && !storage_is_remote($stored)) {
            $root=realpath(__DIR__.'/../uploads'); $path=realpath(__DIR__.'/../'.$stored);
            if ($root && $path && str_starts_with(str_replace('\\','/',$path),str_replace('\\','/',$root).'/')) @unlink($path);
        }
        throw $e;
    }
}
function session_send_overdue_reminders(PDO $pdo): int {
    if (!session_tracking_available($pdo)) return 0;
    $ids=$pdo->query("SELECT document_id FROM document_sessions WHERE stage='amendment' AND amendment_due_at<NOW() AND overdue_notified_at IS NULL LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
    $count=0;
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $doc=$stmt->fetch();
            $stmt=$pdo->prepare("SELECT * FROM document_sessions WHERE document_id=? AND stage='amendment' AND amendment_due_at<NOW() AND overdue_notified_at IS NULL FOR UPDATE"); $stmt->execute([$id]);
            if ($stmt->fetch()) {
                session_notify($pdo,$doc,'amendment_overdue',$doc['doc_number'].': Amendment overdue after 15 calendar days. Follow up with the assigned committee.');
                session_event($pdo,(int)$id,null,'overdue_reminder','amendment','15-day amendment period elapsed; committee follow-up required.');
                $pdo->prepare('UPDATE document_sessions SET overdue_notified_at=NOW() WHERE document_id=?')->execute([$id]); $count++;
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    return $count;
}
