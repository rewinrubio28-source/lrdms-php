<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/session_workflow.php';
try { echo session_send_overdue_reminders(get_db())." overdue amendment reminder(s) delivered.\n"; }
catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
