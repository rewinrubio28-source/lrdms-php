<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/database_schema.php';
$schema=database_schema(get_db());
if (($argv[1]??'')==='--integrity') {
    $checks=database_foreign_key_violations(get_db(),$schema);
    echo json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    exit(array_sum(array_column($checks,'orphan_count'))?1:0);
}
echo json_encode($schema,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
