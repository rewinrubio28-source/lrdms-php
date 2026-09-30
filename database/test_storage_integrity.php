<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/storage.php';
$directory = sys_get_temp_dir() . '/lrdms-integrity-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    file_put_contents($directory . '/incoming', 'Original document bytes');
    if (!storage_copy_new_file($directory . '/incoming', $directory . '/original')) throw new RuntimeException('Initial copy failed.');
    file_put_contents($directory . '/incoming', 'Revised document bytes');
    if (storage_copy_new_file($directory . '/incoming', $directory . '/original')) throw new RuntimeException('Existing original was overwritten.');
    if (file_get_contents($directory . '/original') !== 'Original document bytes') throw new RuntimeException('Original content changed.');
    if (!storage_copy_new_file($directory . '/incoming', $directory . '/revision')) throw new RuntimeException('Revision copy failed.');
    echo "PASS: existing original cannot be overwritten; revision stored separately.\n";
} finally {
    foreach (['incoming', 'original', 'revision'] as $name) if (is_file($directory . '/' . $name)) unlink($directory . '/' . $name);
    rmdir($directory);
}
