<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../config/database.php';
get_db()->exec(file_get_contents(__DIR__ . '/../sql/privacy.sql'));
echo "Privacy request and consent history tables ready.\n";
