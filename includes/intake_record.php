<?php
// Pure validation shared by API intake and workflow tests.
function intake_record_values(array $input): array {
    $values = [];
    foreach (['doc_number'=>60,'title'=>500,'sponsor'=>150,'source_system'=>100,'source_record_id'=>180,'source_status'=>100,'originating_office'=>180,'originating_division'=>180,'submitter_position'=>180,'responsible_custodian'=>180,'related_legislative_item'=>180] as $field=>$limit) {
        if (isset($input[$field]) && !is_string($input[$field])) throw new InvalidArgumentException('Invalid ' . $field . '.');
        $value = trim($input[$field] ?? '');
        if (mb_strlen($value) > $limit) throw new InvalidArgumentException($field . ' is too long.');
        $values[$field] = $value === '' ? null : $value;
    }
    if (!$values['title'] || !$values['doc_number']) throw new InvalidArgumentException('Document number and title are required.');
    $values['source_system'] = $values['source_system'] ?: 'System 1 - Lifecycle';
    if ($values['source_system'] === 'Manual Encoding') throw new InvalidArgumentException('API submissions must identify an external source.');
    $values['doc_type'] = $input['doc_type'] ?? 'Ordinance';
    if (!in_array($values['doc_type'], ['Ordinance','Resolution','Committee Report','Minutes','Other'], true)) throw new InvalidArgumentException('Invalid document type.');
    $statuses = ['Draft','Submitted','Under Review','Enacted','Amended','Superseded','Withdrawn','Rejected'];
    $values['status'] = $input['status'] ?? (in_array($values['source_status'], $statuses, true) ? $values['source_status'] : 'Submitted');
    if (!in_array($values['status'], $statuses, true)) throw new InvalidArgumentException('Invalid legislative status.');
    if (in_array($values['source_status'], $statuses, true) && $values['status'] !== $values['source_status']) throw new InvalidArgumentException('Status conflicts with source status.');
    $values['source_status'] = $values['source_status'] ?: $values['status'];
    $values['classification'] = $input['classification'] ?? 'INTERNAL';
    if (!in_array($values['classification'], ['PUBLIC','INTERNAL','RESTRICTED','CONFIDENTIAL'], true)) throw new InvalidArgumentException('Invalid classification.');
    foreach (['committee_id','previous_version_id','council_term'] as $field) {
        $raw = $input[$field] ?? null;
        $values[$field] = ($raw === null || $raw === '') ? null : filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>$field === 'council_term' ? 999 : PHP_INT_MAX]]);
        if ($values[$field] === false) throw new InvalidArgumentException('Invalid ' . $field . '.');
    }
    foreach (['enactment_date'=>'Y-m-d','source_status_date'=>'Y-m-d H:i:s'] as $field=>$format) {
        $raw = $input[$field] ?? null;
        if ($raw !== null && $raw !== '') {
            if (!is_string($raw)) throw new InvalidArgumentException('Invalid ' . $field . '.');
            $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if (!$date || $date->format($format) !== $raw) throw new InvalidArgumentException('Invalid ' . $field . '.');
        }
        $values[$field] = $raw ?: null;
    }
    foreach (['body','ocr_text'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) throw new InvalidArgumentException('Invalid ' . $field . '.');
        $values[$field] = $input[$field] ?? null;
    }
    return $values;
}
