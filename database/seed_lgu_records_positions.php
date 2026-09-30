<?php
// Documented LGU reference titles; not a complete/current Manila plantilla.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run through the local PHP CLI.'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
$entries = json_decode(file_get_contents(__DIR__ . '/lgu_records_positions.json'), true, 512, JSON_THROW_ON_ERROR);
$pdo = get_db();
$added = 0;
$pdo->beginTransaction();
try {
    $find = $pdo->prepare('SELECT id FROM positions WHERE name = ?');
    $insert = $pdo->prepare('INSERT INTO positions (name) VALUES (?)');
    foreach ($entries as $entry) {
        $find->execute([$entry['name']]);
        if ($find->fetchColumn()) continue;
        $insert->execute([$entry['name']]);
        log_action('access', 'created_organization_reference', 'LGU position: ' . $entry['name'] . '; source=' . $entry['source']);
        $added++;
    }
    $pdo->commit();
    echo "Added $added documented LGU position titles. Existing entries preserved.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
