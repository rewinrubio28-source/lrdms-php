<?php
require_once __DIR__.'/database_schema.php';

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
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('access_requests',$tables,true)) $paths=array_merge($paths,$pdo->query("SELECT request_letter_path FROM access_requests WHERE request_letter_path IS NOT NULL AND request_letter_path<>''")->fetchAll(PDO::FETCH_COLUMN));
    if (in_array('profile_photo', $columns, true)) $paths = array_merge($paths, $pdo->query("SELECT profile_photo FROM users WHERE profile_photo IS NOT NULL AND profile_photo <> ''")->fetchAll(PDO::FETCH_COLUMN));
    foreach ($paths as $path) {
        if (!str_starts_with($path, 'uploads/') || str_contains($path, '..') || str_contains($path, '\\')) throw new RuntimeException('Backup requires local uploads paths; remote or unsupported file reference found.');
        if (!is_file($uploads . '/' . substr($path, 8))) throw new RuntimeException('Referenced file is missing: ' . $path);
    }
}

function backup_assert_integrity(PDO $pdo): int {
    $checks=database_foreign_key_violations($pdo);
    foreach ($checks as $check) if ($check['orphan_count']) throw new RuntimeException('Foreign-key orphan rows detected: '.$check['table'].'.'.$check['constraint']);
    return count($checks);
}

/** Caller must coordinate the exclusive maintenance lock and other database writers. */
function backup_create(PDO $pdo,string $uploads,string $backupDirectory): string {
    $sourcePath=realpath($uploads); $destinationPath=realpath($backupDirectory);
    if (!$sourcePath || !$destinationPath || $destinationPath===$sourcePath || str_starts_with(strtolower($destinationPath.DIRECTORY_SEPARATOR),strtolower($sourcePath.DIRECTORY_SEPARATOR))) throw new RuntimeException('Backup destination must exist outside the uploads tree.');
    $database=$pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach (['VIEWS','TRIGGERS','ROUTINES','EVENTS'] as $kind) {
        $column=match($kind){'ROUTINES'=>'ROUTINE_SCHEMA','TRIGGERS'=>'TRIGGER_SCHEMA','EVENTS'=>'EVENT_SCHEMA',default=>'TABLE_SCHEMA'};
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.$kind WHERE $column=?"); $stmt->execute([$database]);
        if ($stmt->fetchColumn()) throw new RuntimeException("Database contains $kind; use a native backup.");
    }
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND ENGINE<>'InnoDB'"); $stmt->execute([$database]);
    if ($stmt->fetchColumn()) throw new RuntimeException('All tables must use InnoDB.');
    $name='records-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)); $pending=$backupDirectory.'/'.$name.'.partial';
    if (!mkdir($pending,0700)) throw new RuntimeException('Could not create backup directory.');
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        backup_check_references($pdo,$uploads); $foreignKeys=backup_assert_integrity($pdo); $tables=[];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $rows=[]; $quoted=backup_identifier($table);
            foreach ($pdo->query("SELECT * FROM $quoted") as $row) $rows[]=array_map(static fn($v)=>$v===null?null:base64_encode((string)$v),$row);
            $tables[$table]=['ddl'=>$pdo->query("SHOW CREATE TABLE $quoted")->fetch(PDO::FETCH_NUM)[1],'rows'=>$rows];
        }
        backup_json($pending.'/database.json',$tables); backup_copy_uploads($uploads,$pending.'/uploads'); $pdo->commit();
        $manifest=['format'=>'lrdms-local-v1','created_utc'=>gmdate(DATE_ATOM),'source_database'=>$database,'php'=>PHP_VERSION,'foreign_keys_checked'=>$foreignKeys,'tables'=>array_map(static fn($t)=>count($t['rows']),$tables),'files'=>[]];
        foreach (backup_files($pending) as $file) $manifest['files'][str_replace('\\','/',substr($file,strlen($pending)+1))]=hash_file('sha256',$file);
        backup_json($pending.'/manifest.json',$manifest); backup_verify($pending);
        if (!rename($pending,$backupDirectory.'/'.$name)) throw new RuntimeException('Could not finalize backup.');
        return $name;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Uses a dedicated server connection; never switches the application's connection. */
function backup_restore(PDO $server,string $directory,string $target,string $files,string $activeDatabase): array {
    if (!preg_match('/^lrdms_restore_[a-zA-Z0-9_]{1,40}$/D',$target) || $target===$activeDatabase) throw new RuntimeException('Use a fresh database name beginning with lrdms_restore_.');
    if (file_exists($files)) throw new RuntimeException('Restore directory already exists.');
    $manifest=backup_verify($directory); $tables=json_decode(file_get_contents($directory.'/database.json'),true,512,JSON_THROW_ON_ERROR);
    if (array_keys($tables)!==array_keys($manifest['tables'])) throw new RuntimeException('Table manifest mismatch.');
    $server->exec('CREATE DATABASE '.backup_identifier($target).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server->exec('USE '.backup_identifier($target));
    try {
        $server->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table=>$data) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D',$table) || !str_starts_with($data['ddl'],'CREATE TABLE '.backup_identifier($table).' ')) throw new RuntimeException('Unexpected table definition.');
            $server->exec($data['ddl']);
            foreach ($data['rows'] as $row) {
                $sql='INSERT INTO '.backup_identifier($table).' ('.implode(',',array_map('backup_identifier',array_keys($row))).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')';
                $server->prepare($sql)->execute(array_map(static function($v){ if ($v===null) return null; $value=base64_decode($v,true); if ($value===false) throw new RuntimeException('Invalid encoded database value.'); return $value; },array_values($row)));
            }
            if ((int)$server->query('SELECT COUNT(*) FROM '.backup_identifier($table))->fetchColumn()!==$manifest['tables'][$table]) throw new RuntimeException('Restored table count mismatch.');
            $rows=[];
            foreach ($server->query('SELECT * FROM '.backup_identifier($table))->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[]=array_map(static fn($v)=>$v===null?null:base64_encode((string)$v),$row);
            if (!backup_compare_rows($data['rows'],$rows)) throw new RuntimeException('Restored row content mismatch: '.$table);
        }
    } finally { $server->exec('SET FOREIGN_KEY_CHECKS=1'); }
    $foreignKeys=backup_assert_integrity($server);
    backup_copy_uploads($directory.'/uploads',$files.'/uploads'); backup_check_references($server,$files.'/uploads');
    backup_json($files.'/restore-complete.json',['database'=>$target,'verified_utc'=>gmdate(DATE_ATOM),'foreign_keys_checked'=>$foreignKeys,'source_set'=>basename($directory)]);
    return ['tables'=>count($tables),'foreign_keys'=>$foreignKeys];
}

function backup_directory(): string {
    $value=env_optional('BACKUP_DIR',dirname(__DIR__).'/backups');
    if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~',$value) || str_contains($value,"\0") || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~',$value)) throw new RuntimeException('BACKUP_DIR must be an absolute directory without traversal.');
    if (!is_dir($value) && !mkdir($value,0700,true)) throw new RuntimeException('Could not create BACKUP_DIR.');
    $path=realpath($value); if (!$path || !is_writable($path)) throw new RuntimeException('BACKUP_DIR is not writable.');
    $root=realpath(dirname(__DIR__)); $private=realpath(dirname(__DIR__).'/backups');
    $within=static fn($child,$parent)=>$child===$parent || str_starts_with(strtolower($child.DIRECTORY_SEPARATOR),strtolower($parent.DIRECTORY_SEPARATOR));
    if (dirname($path)===$path || ($within($path,$root) && (!$private || !$within($path,$private)))) throw new RuntimeException('Use the protected backups directory or a secured destination outside the application web root.');
    return $path;
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

