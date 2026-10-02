<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/database_indexes.php';
$added=database_add_indexes(get_db());
echo 'Database query indexes ready; added '.count($added).".\n";
$keys=database_add_revision_keys(get_db());
echo 'Version reference foreign keys ready; added '.count($keys).".\n";
