<?php
/**
 * Department-based amendment routing.
 * Adds documents.amendment_office_id (nullable, no backfill): amendment
 * requests are assigned to a department (office) instead of a committee.
 * Safe to re-run on MySQL/MariaDB.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$pdo = get_db();
$q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
$q->execute(['documents', 'amendment_office_id']);
if ((int)$q->fetchColumn() > 0) { echo "Already present: documents.amendment_office_id\n"; return; }
$pdo->exec('ALTER TABLE `documents` ADD COLUMN `amendment_office_id` INT NULL');
$q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
$q->execute(['documents', 'fk_documents_amendment_office']);
if (!(int)$q->fetchColumn()) $pdo->exec('ALTER TABLE `documents` ADD CONSTRAINT `fk_documents_amendment_office` FOREIGN KEY (`amendment_office_id`) REFERENCES `offices`(id) ON DELETE SET NULL');
echo "Applied: documents.amendment_office_id\n";
