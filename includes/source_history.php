<?php
function source_history_available(PDO $pdo): bool {
    return (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_source_history'")->fetchColumn();
}
function source_history_values(array $input): array {
    $values=[];
    foreach (['event_title'=>180,'source_office'=>180,'destination_office'=>180,'actor_name'=>180,'reference'=>180,'remarks'=>2000,'event_date'=>10,'evidence_type'=>30] as $field=>$limit) {
        if (!is_string($input[$field] ?? '')) throw new InvalidArgumentException('Invalid history field.');
        $values[$field]=trim($input[$field] ?? '');
        if (!mb_check_encoding($values[$field], 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $values[$field])) throw new InvalidArgumentException('Invalid text in history details.');
        if (mb_strlen($values[$field])>$limit) throw new InvalidArgumentException('History details exceed the allowed length.');
    }
    foreach (['event_title','source_office','remarks'] as $field) if ($values[$field]==='') throw new InvalidArgumentException('Provide the event, source office and remarks.');
    if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $values['event_date'])) throw new InvalidArgumentException('Provide a valid event date.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$values['event_date']);
    if (!$date || $date->format('Y-m-d')!==$values['event_date']) throw new InvalidArgumentException('Provide a valid event date.');
    if (!in_array($values['evidence_type'],['Manual source record','Simulated demo'],true)) throw new InvalidArgumentException('Choose how this history was obtained.');
    if ($values['evidence_type']==='Manual source record' && $values['reference']==='') throw new InvalidArgumentException('Provide a supporting reference for manually transcribed source history.');
    return $values;
}
function source_history_add(PDO $pdo, array $user, int $documentId, array $input): void {
    if (!_role_has_permission((int)$user['role_id'],'repository','edit_metadata')) throw new RuntimeException('Your role cannot record source history.');
    $values=source_history_values($input);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE');
        $stmt->execute([$documentId]); $record=$stmt->fetch();
        if (!$record || !can_view_document($user,$record)) throw new RuntimeException('Document unavailable.');
        $fields=array_keys($values);
        $stmt=$pdo->prepare('INSERT INTO document_source_history (document_id,recorded_by,'.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields)+2,'?')).')');
        $stmt->execute(array_merge([$documentId,$user['id']],array_values($values)));
        log_action('repository','recorded_source_history','Record #'.$documentId.'; source event #'.$pdo->lastInsertId().'; '.$values['evidence_type']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
