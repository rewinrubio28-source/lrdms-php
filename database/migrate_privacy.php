<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
$schemaPath = __DIR__ . '/../sql/privacy.sql';
if (!is_readable($schemaPath)) {
    fwrite(STDERR, "Privacy migration schema is missing. Rebuild and redeploy the image with sql/privacy.sql included.\n");
    exit(1);
}
$schema = file_get_contents($schemaPath);
if ($schema === false || trim($schema) === '') {
    fwrite(STDERR, "Privacy migration schema is empty or unreadable. Rebuild and redeploy the image.\n");
    exit(1);
}
get_db()->exec($schema);
echo "Privacy request and consent history tables ready.\n";
