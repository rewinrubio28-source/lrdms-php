<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/report_schema.php';
foreach (report_schedule_schema() as $sql) get_db()->exec($sql);
echo "Report schedules and private generation history tables ready.\n";
