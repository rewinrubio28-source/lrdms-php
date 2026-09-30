<?php
// Reference choices for the presentation, not a complete Manila plantilla.
// Position titles: https://citycouncilofmanila.com.ph/ (Council Secretariat).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this seed through the local PHP CLI.');
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
$pdo = get_db();
$entries = [
    'offices' => ['City Council'],
    'positions' => ['Secretary to the City Council', 'Local Legislative Staff Officer VI'],
];
$pdo->beginTransaction();
try {
    foreach ($entries as $table => $names) {
        $find = $pdo->prepare("SELECT id FROM $table WHERE name = ?");
        $insert = $pdo->prepare("INSERT INTO $table (name) VALUES (?)");
        foreach ($names as $name) {
            $find->execute([$name]);
            if ($find->fetchColumn()) {
                echo "Already available: $name\n";
                continue;
            }
            $insert->execute([$name]);
            log_action('access', 'created_organization_reference', "Presentation reference ($table): $name");
            echo "Added: $name\n";
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
