<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';

if (current_user()) {
    log_action('auth', 'logout', $_SESSION['username'] ?? '');
}
do_logout();
header('Location: public.php');
exit;
