<?php
require_once __DIR__ . '/../config/database.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$pdo = get_db();
$exists = $pdo->query("SHOW COLUMNS FROM documents LIKE 'council_term'")->fetch();
if (!$exists) $pdo->exec('ALTER TABLE documents ADD council_term SMALLINT UNSIGNED NULL, ADD INDEX idx_documents_council_term (council_term)');
echo "Council term metadata ready. Existing records remain unassigned.\n";
