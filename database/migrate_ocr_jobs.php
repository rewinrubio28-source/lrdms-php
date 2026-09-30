<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$schemaPath = __DIR__ . '/../sql/ocr_jobs.sql';
if (!is_readable($schemaPath)) {
    fwrite(STDERR, "OCR migration failed: sql/ocr_jobs.sql is missing from the deployment. Rebuild the PHP image with this file included.\n");
    exit(1);
}
$schema = file_get_contents($schemaPath);
if ($schema === false || trim($schema) === '') {
    fwrite(STDERR, "OCR migration failed: sql/ocr_jobs.sql is empty or unreadable.\n");
    exit(1);
}
get_db()->exec($schema);
echo "OCR queue ready.\n";
