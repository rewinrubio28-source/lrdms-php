<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/request_security.php';
get_db()->exec(security_rate_schema());
echo "Request security rate-limit table ready.\n";
