<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
get_db()->exec(file_get_contents(__DIR__ . '/../sql/ocr_jobs.sql'));
echo "OCR queue ready.\n";
