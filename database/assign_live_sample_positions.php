<?php
// Sample assignments for the six staff usernames in the supplied live directory.
// Run explicitly on the target server; never runs as part of deployment upgrades.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';

function assign_live_sample_positions(PDO $pdo, bool $apply = false): array {
    $assignments = [
        'rrubio' => ['Rewin Rubio', 'Chief Administrative Officer', null],
        'jmpaderes' => ['John Mark Paderes', 'Supervising Administrative Officer', null],
        'admin' => ['Ana Dela Cruz', 'Administrative Officer V', 'Records Management and Publication Section (RMPS)'],
        'rofficer' => ['Ramon Santos', 'Administrative Officer III', 'Records Management and Publication Section (RMPS)'],
        'staff' => ['Liza Dizon', 'Administrative Assistant II', 'Records Management and Publication Section (RMPS)'],
        'secretary' => ['Mark Cruz', 'Senior Administrative Assistant IV', 'Records Management and Publication Section (RMPS)'],
    ];
    $pdo->beginTransaction();
    try {
        $office = $pdo->prepare('SELECT id FROM offices WHERE name=?');
        $office->execute(['Information and Communication Division']);
        $officeId = $office->fetchColumn();
        if (!$officeId) throw new RuntimeException('Required parent division is missing. Run the deployment upgrade first.');
        $find = $pdo->prepare('SELECT u.*, r.name AS access_role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=? FOR UPDATE');
        $position = $pdo->prepare('SELECT id FROM positions WHERE name=?');
        $section = $pdo->prepare('SELECT id FROM divisions WHERE name=? AND office_id=?');
        $update = $pdo->prepare('UPDATE users SET full_name=?, office_id=?, division_id=?, position_id=? WHERE id=? AND username=?');
        $plan = [];
        foreach ($assignments as $username => [$name, $title, $sectionName]) {
            $find->execute([$username]);
            $user = $find->fetch();
            if (!$user) throw new RuntimeException('Expected live username missing: ' . $username . '. No changes saved.');
            if ($user['access_role'] === 'Super Admin') throw new RuntimeException('Protected Super Admin encountered; no changes saved.');
            $position->execute([$title]);
            $positionId = $position->fetchColumn();
            if (!$positionId) throw new RuntimeException('Missing position: ' . $title);
            $sectionId = null;
            if ($sectionName) {
                $section->execute([$sectionName, $officeId]);
                $sectionId = $section->fetchColumn();
                if (!$sectionId) throw new RuntimeException('Missing section: ' . $sectionName);
            }
            $plan[] = ['username' => $username, 'name' => $name, 'position' => $title, 'section' => $sectionName ?? 'Division leadership', 'access_role' => $user['access_role']];
            if (!$apply) continue;
            $update->execute([$name, $officeId, $sectionId, $positionId, $user['id'], $username]);
            if (!$update->rowCount()) continue;
            $find->execute([$username]);
            $after = $find->fetch();
            foreach ($user as $field => $value) {
                if (in_array($field, ['full_name', 'office_id', 'division_id', 'position_id'], true)) continue;
                if ($after[$field] !== $value) throw new RuntimeException('Unexpected change to protected account field: ' . $field);
            }
            log_action('access', 'assigned_sample_position', json_encode([
                'user_id' => $user['id'], 'username' => $username,
                'before' => array_intersect_key($user, array_flip(['full_name', 'office_id', 'division_id', 'position_id'])),
                'after' => ['full_name' => $name, 'office_id' => $officeId, 'division_id' => $sectionId, 'position_id' => $positionId],
                'note' => 'Sample organizational assignment for demonstration, not verified employment data.',
            ]));
        }
        if ($apply) $pdo->commit(); else $pdo->rollBack();
        return $plan;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $apply = in_array('--apply', $argv, true);
        echo json_encode(assign_live_sample_positions(get_db(), $apply), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        echo $apply ? "Sample positions saved. Login details and access roles preserved.\n" : "Preview only. Run with --apply to save these sample assignments.\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
