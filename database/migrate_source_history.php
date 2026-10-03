<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$sql = file_get_contents(__DIR__ . '/../sql/source_history.sql');
if (!$sql) throw new RuntimeException('Source history schema is missing.');
get_db()->exec($sql);
echo "Document source history table ready.\n";
