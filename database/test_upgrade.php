<?php
// Creates a separate local test database. Never modifies the configured database.
if (PHP_SAPI !== 'cli') exit;
define('LRDMS_BACKUP_CLI', true);
require_once __DIR__ . '/../config/database.php';
if (!in_array(DB_HOST, ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Local test only.');
$testName = 'lrdms_upgrade_test_' . bin2hex(random_bytes(5));
$pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE `$testName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$testName`");
$schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
$schema = preg_replace('/^CREATE DATABASE[^;]+;\s*$/mi', '', $schema, -1, $creates);
$schema = preg_replace('/^USE lrdms_db;\s*$/mi', '', $schema, -1, $uses);
if ($creates !== 1 || $uses !== 1) throw new RuntimeException('Unexpected base schema format.');
$pdo->exec($schema);
require_once __DIR__ . '/../includes/organization.php';
if (organization_schema_available($pdo)) throw new RuntimeException('Expected missing organization schema.');
ob_start(); require __DIR__ . '/../includes/organization_form.php'; $form = ob_get_clean();
if (!str_contains($form, 'Database update required')) throw new RuntimeException('Missing-schema form fallback failed.');
$pdo->exec("INSERT INTO users (username,password_hash,full_name,role_id) VALUES ('upgrade_test','not-a-login-hash','Upgrade Test',(SELECT id FROM roles LIMIT 1))");
$owner = $pdo->lastInsertId();
$pdo->exec("INSERT INTO documents (doc_number,title,doc_type,owner_id,status,source_system,verified_at) VALUES ('UPGRADE-PENDING','Original pending text','Ordinance',$owner,'Submitted','Test source',NULL),('UPGRADE-REGISTERED','Original registered text','Resolution',$owner,'Enacted','Test source','2026-01-01 10:00:00')");
putenv('DB_NAME=' . $testName);
function run_upgrade_test(): void {
    $process = proc_open([PHP_BINARY, __DIR__ . '/upgrade.php'], [0=>STDIN,1=>['pipe','w'],2=>['pipe','w']], $pipes);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || !str_contains($output, 'UPGRADE COMPLETE')) throw new RuntimeException('Upgrade failed: ' . $errors . $output);
}
run_upgrade_test();
if ($pdo->query("SELECT records_status FROM documents WHERE doc_number='UPGRADE-PENDING'")->fetchColumn() !== 'Pending Validation') throw new RuntimeException('Initial pending state incorrect.');
if ($pdo->query("SELECT records_status FROM documents WHERE doc_number='UPGRADE-REGISTERED'")->fetchColumn() !== 'Registered') throw new RuntimeException('Initial registered state incorrect.');
$pdo->exec("UPDATE documents SET records_status='Returned for Correction', received_at='2026-01-02 12:00:00', validation_note='Preserve this review' WHERE doc_number='UPGRADE-PENDING'");
$before = $pdo->query('SELECT * FROM documents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
run_upgrade_test();
$after = $pdo->query('SELECT * FROM documents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if ($before !== $after) throw new RuntimeException('Rerun changed existing records.');
echo "PASS: old-schema upgrade, missing-table form fallback, initial states, and rerun preservation.\nIsolated test database retained: $testName\n";
