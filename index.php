<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

require __DIR__ . '/public.php';
