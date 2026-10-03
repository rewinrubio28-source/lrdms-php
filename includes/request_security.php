<?php
/** Shared, database-backed fixed-window throttling. No credentials in logs. */
function security_rate_schema(): string {
    return 'CREATE TABLE IF NOT EXISTS security_rate_limits (bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, hits INT UNSIGNED NOT NULL, expires_at BIGINT NOT NULL, INDEX idx_rate_expiry(expires_at)) ENGINE=InnoDB';
}

function security_rate_take(PDO $pdo, string $scope, string $subject, int $limit, int $seconds, ?int $now = null): int {
    if ($limit < 1 || $seconds < 1) throw new InvalidArgumentException('Invalid rate policy.');
    $now ??= time();
    $end = (intdiv($now, $seconds) + 1) * $seconds;
    $key = hash('sha256', $scope . "\0" . $subject . "\0" . $end);
    // Atomic increment serializes competing requests, including different app instances.
    $stmt = $pdo->prepare('INSERT INTO security_rate_limits (bucket,hits,expires_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=LEAST(hits+1,1000000)');
    $stmt->execute([$key,$end]);
    $stmt = $pdo->prepare('SELECT hits FROM security_rate_limits WHERE bucket=?');
    $stmt->execute([$key]);
    return (int)$stmt->fetchColumn() > $limit ? max(1,$end-$now) : 0;
}

function security_event(string $event, string $scope): void {
    error_log(json_encode(['security_event'=>$event,'scope'=>$scope,'time'=>gmdate('c')], JSON_UNESCAPED_SLASHES));
}

function security_reject(int $status, string $message, int $retry = 0): never {
    http_response_code($status);
    header('Cache-Control: no-store');
    if ($retry) header('Retry-After: '.$retry);
    $api = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') || isset($_POST['ajax_login']);
    header('Content-Type: '.($api ? 'application/json' : 'text/plain').'; charset=utf-8');
    exit($api ? json_encode(['success'=>false,'message'=>$message,'error'=>$message]) : $message);
}

function security_throttle(string $scope, string $subject, int $limit, int $seconds): void {
    // CLI jobs/tests do not represent public HTTP traffic; test the core directly.
    if (PHP_SAPI === 'cli') return;
    try {
        $pdo = get_db();
        $wait = security_rate_take($pdo,$scope,$subject,$limit,$seconds);
        if (random_int(1,100) === 1) $pdo->prepare('DELETE FROM security_rate_limits WHERE expires_at < ? LIMIT 1000')->execute([time()]);
    } catch (Throwable $e) {
        security_event('rate_store_unavailable',$scope);
        security_reject(503,'Security checks are temporarily unavailable. Please retry shortly.',30);
    }
    if ($wait) {
        security_event('rate_limited',$scope);
        security_reject(429,'Too many requests. Please wait before trying again.',$wait);
    }
}

function security_client_ip(): string {
    // Never trust arbitrary X-Forwarded-For supplied by a client.
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function security_web_request(): void {
    if (PHP_SAPI === 'cli') return;
    $route = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $ip = security_client_ip();
    if (in_array($route,['public.php','index.php'],true)) security_throttle('public-read',$ip,120,60);
    if (in_array($route,['public.php','index.php','verify_2fa.php','reset_password.php'],true)) {
        foreach (['username','password','reset_email','reset_code','new_password','confirm_password','code','csrf_token'] as $field) {
            if (isset($_POST[$field]) && (!is_string($_POST[$field]) || strlen($_POST[$field])>1024)) security_reject(422,'Invalid form input. Refresh and try again.');
        }
    }
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) security_throttle('api-ip',$ip,600,60);
    if (in_array($route,['search.php','ocr_scan.php','export_records_report.php','export_dashboard.php','export_audit.php','download_document.php','preview_document.php'],true)) {
        security_throttle('expensive:'.$route,(string)($_SESSION['user_id'] ?? $ip),30,60);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') security_throttle('post:'.$route,$ip,60,60);
}

function security_session_expired(array $user, ?int $now = null): bool {
    $now ??= time();
    $created = strtotime($user['session_created_at'] ?? '') ?: 0;
    $seen = strtotime($user['session_last_seen'] ?? '') ?: $created;
    return $created <= $now-43200 || $seen <= $now-1800;
}
