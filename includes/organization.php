<?php
// Organization assignments describe staff identity; permission checks stay in RBAC.
// Unique position titles from the supplied Information and Communication Division draft.
function organization_chart_positions(): array {
    return [
        'Chief Administrative Officer' => ['Leadership', null],
        'Supervising Administrative Officer' => ['Leadership', null],
        'Administrative Officer V' => ['RMPS ITTS', 18],
        'Senior Administrative Assistant IV' => ['RMPS ITTS', 16],
        'Administrative Officer III' => ['RMPS', 14],
        'Administrative Assistant II' => ['RMPS', 8],
        'Administrative Aide VI' => ['RMPS', 6],
        'Administrative Aide IV' => ['RMPS ITTS', 4],
        'Administrative Aide II' => ['RMPS ITTS', 2],
        'Senior Administrative Assistant II (Computer Operator II)' => ['ITTS', 14],
        'Administrative Assistant IV (Videographer/Photographer III)' => ['ITTS', 10],
        'Administrative Assistant I (Videographer/Photographer II)' => ['ITTS', 7],
        'Administrative Assistant I (Audio-Visual Equipment Operator III)' => ['ITTS', 7],
        'Administrative Assistant IV (Communication Equipment Operator III)' => ['ITTS', 10],
        'Administrative Assistant I (Computer Operator I)' => ['ITTS', 7],
    ];
}

function organization_schema_available(PDO $pdo): bool {
    static $available = null;
    if ($available === null) {
        $count = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('offices','divisions','positions','user_committees')")->fetchColumn();
        $available = (int)$count === 4;
    }
    return $available;
}

function organization_lists(PDO $pdo): array {
    $lists = [];
    foreach (['offices', 'divisions', 'positions', 'committees'] as $table) {
        $lists[$table] = $pdo->query("SELECT * FROM $table ORDER BY name")->fetchAll();
    }
    return $lists;
}

function organization_input(PDO $pdo, array $input, array &$errors): array {
    if (!organization_schema_available($pdo)) {
        $errors[] = 'Database update required. Please ask the administrator to run the deployment upgrade before saving this account.';
        return [];
    }
    $values = [];
    foreach (['office_id' => 'offices', 'division_id' => 'divisions', 'position_id' => 'positions'] as $field => $table) {
        $raw = $input[$field] ?? '';
        $id = is_scalar($raw) && filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ? (int)$raw : null;
        if ($raw !== '' && $raw !== null && !$id) $errors[] = 'Invalid organizational assignment.';
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) $errors[] = 'The selected organizational assignment does not exist.';
            if ($field === 'division_id' && $row && (!$values['office_id'] || (int)$row['office_id'] !== (int)$values['office_id'])) {
                $errors[] = 'Select a division belonging to the selected office.';
            }
        }
        $values[$field] = $id;
    }
    $memberships = $input['committee_ids'] ?? [];
    if (!is_array($memberships)) { $errors[] = 'Invalid committee memberships.'; $memberships = []; }
    if (!empty($input['committee_id'])) $memberships[] = $input['committee_id'];
    $values['committee_ids'] = [];
    $stmt = $pdo->prepare('SELECT id FROM committees WHERE id = ?');
    foreach ($memberships as $raw) {
        $id = is_scalar($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $stmt->execute([$id ?: 0]);
        if (!$id || !$stmt->fetchColumn()) { $errors[] = 'Select a valid committee.'; continue; }
        $values['committee_ids'][] = (int)$id;
    }
    $values['committee_ids'] = array_values(array_unique($values['committee_ids']));
    return $values;
}

// Called inside the transaction that creates/updates the account.
function organization_save(PDO $pdo, int $userId, array $values): void {
    $pdo->prepare('UPDATE users SET office_id = ?, division_id = ?, position_id = ? WHERE id = ?')
        ->execute([$values['office_id'], $values['division_id'], $values['position_id'], $userId]);
    $pdo->prepare('DELETE FROM user_committees WHERE user_id = ?')->execute([$userId]);
    $stmt = $pdo->prepare('INSERT INTO user_committees (user_id, committee_id) VALUES (?, ?)');
    foreach ($values['committee_ids'] as $id) $stmt->execute([$userId, $id]);
}

function organization_user(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT u.office_id, u.division_id, u.position_id, o.name AS office_name, d.name AS division_name, p.name AS position_name FROM users u LEFT JOIN offices o ON o.id=u.office_id LEFT JOIN divisions d ON d.id=u.division_id LEFT JOIN positions p ON p.id=u.position_id WHERE u.id=?');
    $stmt->execute([$userId]);
    $values = $stmt->fetch() ?: [];
    $stmt = $pdo->prepare('SELECT c.id, c.name FROM committees c WHERE c.id IN (SELECT committee_id FROM user_committees WHERE user_id=?) OR c.id=(SELECT committee_id FROM users WHERE id=?) ORDER BY c.name');
    $stmt->execute([$userId, $userId]);
    $values['committees'] = $stmt->fetchAll();
    $values['committee_ids'] = array_map('intval', array_column($values['committees'], 'id'));
    return $values;
}
