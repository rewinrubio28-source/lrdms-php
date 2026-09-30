<?php
/** CLI-only, local-file backup. Restore always creates a NEW database and file directory. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('LRDMS_BACKUP_CLI', true);
require_once __DIR__ . '/../config/database.php';

function backup_identifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function backup_json(string $path, array $value): void {
    $data = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    if (file_put_contents($path, $data) !== strlen($data)) throw new RuntimeException('Could not write backup file.');
}
function backup_files(string $directory): array {
    $result = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlinks are not supported in backup sets.');
        if ($file->isFile()) $result[] = $file->getPathname();
    }
    sort($result);
    return $result;
}
function backup_verify(string $directory): array {
    $manifest = json_decode(file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['format'] ?? '') !== 'lrdms-local-v1' || empty($manifest['files']['database.json'])) throw new RuntimeException('Unsupported backup set.');
    foreach ($manifest['files'] as $relative => $hash) {
        if (str_contains($relative, '..') || str_contains($relative, '\\') || !preg_match('#^(database\.json|uploads/.+)$#D', $relative)) throw new RuntimeException('Invalid backup path.');
        $path = $directory . '/' . $relative;
        if (!is_file($path) || is_link($path) || !hash_equals($hash, hash_file('sha256', $path))) throw new RuntimeException('Backup checksum failed: ' . $relative);
    }
    foreach (backup_files($directory) as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen($directory) + 1));
        if ($relative !== 'manifest.json' && !isset($manifest['files'][$relative])) throw new RuntimeException('Unexpected file in backup: ' . $relative);
    }
    return $manifest;
}
function backup_copy_uploads(string $from, string $to): void {
    if (!mkdir($to, 0700, true)) throw new RuntimeException('Could not create uploads directory.');
    foreach (backup_files($from) as $file) {
        $relative = substr($file, strlen($from) + 1);
        $target = $to . '/' . $relative;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) throw new RuntimeException('Could not create file directory.');
        if (!copy($file, $target) || hash_file('sha256', $file) !== hash_file('sha256', $target)) throw new RuntimeException('File copy verification failed.');
    }
}
function backup_check_references(PDO $pdo, string $uploads): void {
    $paths = $pdo->query("SELECT file_path FROM documents WHERE file_path IS NOT NULL AND file_path <> '' UNION SELECT file_path FROM document_attachments WHERE file_path IS NOT NULL AND file_path <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('profile_photo', $columns, true)) $paths = array_merge($paths, $pdo->query("SELECT profile_photo FROM users WHERE profile_photo IS NOT NULL AND profile_photo <> ''")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($paths as $path) {
        if (!str_starts_with($path, 'uploads/') || str_contains($path, '..') || str_contains($path, '\\')) throw new RuntimeException('Backup requires local uploads paths; remote or unsupported file reference found.');
        if (!is_file($uploads . '/' . substr($path, 8))) throw new RuntimeException('Referenced file is missing: ' . $path);
    }
}

function backup_compare_rows(array $expected, array $actual): bool {
    $canonical = static function (array $rows): array {
        $result = [];
        foreach ($rows as $row) { ksort($row); $result[] = json_encode($row, JSON_THROW_ON_ERROR); }
        sort($result);
        return $result;
    };
    return $canonical($expected) === $canonical($actual);
}

try {
    $command = $argv[1] ?? '';
    $root = dirname(__DIR__);
    if (!in_array($command, ['backup', 'verify', 'restore'], true)) {
        echo "Usage:\n  php database/backup_restore.php backup\n  php database/backup_restore.php verify SET_NAME\n  php database/backup_restore.php restore SET_NAME lrdms_restore_NAME\n\nSets and restored files are stored in backups/ (denied to HTTP). Restore never overwrites an existing database or directory.\n";
        exit(0);
    }
    if ($command === 'backup') {
        $lock = records_maintenance_lock(true);
        $pdo = get_db();
        // Deliberately reject objects that this portable format cannot preserve.
        foreach (['VIEWS', 'TRIGGERS', 'ROUTINES', 'EVENTS'] as $kind) {
            $column = $kind === 'ROUTINES' ? 'ROUTINE_SCHEMA' : ($kind === 'TRIGGERS' ? 'TRIGGER_SCHEMA' : ($kind === 'EVENTS' ? 'EVENT_SCHEMA' : 'TABLE_SCHEMA'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.$kind WHERE $column=?");
            $stmt->execute([DB_NAME]);
            if ($stmt->fetchColumn()) throw new RuntimeException("Database contains $kind; use a native database backup instead.");
        }
        $engines = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND ENGINE <> ?');
        $engines->execute([DB_NAME, 'InnoDB']);
        if ($engines->fetchColumn()) throw new RuntimeException('All tables must use InnoDB for a consistent snapshot.');
        $name = 'records-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $pending = $root . '/backups/' . $name . '.partial';
        if (!mkdir($pending, 0700)) throw new RuntimeException('Could not create backup directory.');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        backup_check_references($pdo, $root . '/uploads');
        $tables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $quoted = backup_identifier($table);
            $rows = [];
            foreach ($pdo->query("SELECT * FROM $quoted") as $row) {
                $rows[] = array_map(static fn($value) => $value === null ? null : base64_encode((string)$value), $row);
            }
            $tables[$table] = ['ddl' => $pdo->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_NUM)[1], 'rows' => $rows];
        }
        backup_json($pending . '/database.json', $tables);
        backup_copy_uploads($root . '/uploads', $pending . '/uploads');
        $pdo->commit();
        $manifest = ['format' => 'lrdms-local-v1', 'created_utc' => gmdate(DATE_ATOM), 'source_database' => DB_NAME, 'php' => PHP_VERSION, 'tables' => array_map(static fn($table) => count($table['rows']), $tables), 'files' => []];
        foreach (backup_files($pending) as $file) $manifest['files'][str_replace('\\', '/', substr($file, strlen($pending) + 1))] = hash_file('sha256', $file);
        backup_json($pending . '/manifest.json', $manifest);
        backup_verify($pending);
        if (!rename($pending, $root . '/backups/' . $name)) throw new RuntimeException('Could not finalize backup.');
        echo "Backup complete: $name\nTables: " . count($tables) . '; files: ' . (count($manifest['files']) - 1) . "\n";
    } else {
        $name = $argv[2] ?? '';
        if (!preg_match('/^records-[A-Za-z0-9-]+$/D', $name)) throw new RuntimeException('Provide a completed backup set name.');
        $directory = $root . '/backups/' . $name;
        $manifest = backup_verify($directory);
        if ($command === 'verify') { echo "Checksums verified: $name\n"; exit(0); }
        $target = $argv[3] ?? '';
        if (!preg_match('/^lrdms_restore_[a-zA-Z0-9_]{1,40}$/D', $target) || $target === DB_NAME) throw new RuntimeException('Use a fresh database name beginning with lrdms_restore_.');
        $files = $root . '/backups/' . $target;
        if (file_exists($files)) throw new RuntimeException('Restore directory already exists.');
        $tables = json_decode(file_get_contents($directory . '/database.json'), true, 512, JSON_THROW_ON_ERROR);
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // CREATE (without IF NOT EXISTS) prevents accidental replacement.
        $pdo->exec('CREATE DATABASE ' . backup_identifier($target) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE ' . backup_identifier($target));
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table => $data) {
            $pdo->exec($data['ddl']);
            foreach ($data['rows'] as $row) {
                $sql = 'INSERT INTO ' . backup_identifier($table) . ' (' . implode(',', array_map('backup_identifier', array_keys($row))) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
                $pdo->prepare($sql)->execute(array_map(static function ($value) {
                    if ($value === null) return null;
                    $decoded = base64_decode($value, true);
                    if ($decoded === false) throw new RuntimeException('Invalid encoded database value.');
                    return $decoded;
                }, array_values($row)));
            }
            if ((int)$pdo->query('SELECT COUNT(*) FROM ' . backup_identifier($table))->fetchColumn() !== $manifest['tables'][$table]) throw new RuntimeException('Restored table count mismatch.');
            $restored = [];
            foreach ($pdo->query('SELECT * FROM ' . backup_identifier($table))->fetchAll(PDO::FETCH_ASSOC) as $row) $restored[] = array_map(static fn($value) => $value === null ? null : base64_encode((string)$value), $row);
            if (!backup_compare_rows($data['rows'], $restored)) throw new RuntimeException('Restored row content mismatch: ' . $table);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        backup_copy_uploads($directory . '/uploads', $files . '/uploads');
        backup_check_references($pdo, $files . '/uploads');
        echo "Restore complete. New database: $target\nMatching uploads: backups/$target/uploads\nLive database and uploads were not replaced.\n";
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\nIncomplete .partial sets or restore targets are retained for inspection; never use them as completed restores.\n");
    exit(1);
}
