<?php
/** Shared request contract for the two external integration endpoints. */
function external_api_error(int $status, string $message): void {
    if (function_exists('security_event')) security_event('api_error_'.$status,'external-api');
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message]);
    exit;
}

function external_api_require_request(string $method): void {
    require_once __DIR__.'/request_security.php';
    security_throttle('external-api-ip',security_client_ip(),120,60);
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        external_api_error(405, 'Use ' . $method . '.');
    }
    $key = getenv('API_SHARED_KEY');
    if ($key === false || $key === '') {
        external_api_error(503, 'API integration is not configured. Contact the administrator.');
    }
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!is_string($provided) || !hash_equals($key, $provided)) {
        security_event('api_auth_failed','external-api');
        external_api_error(401, 'Invalid or missing API key.');
    }
    security_throttle('external-api-key',$key,120,60);
}

function external_api_search_parameters(array $input): array {
    $query = $input['query'] ?? '';
    $mode = $input['mode'] ?? 'keyword';
    if (!is_string($query) || trim($query) === '') {
        throw new InvalidArgumentException('query must be a non-empty string.');
    }
    $query = trim($query);
    if (mb_strlen($query) > 500) {
        throw new InvalidArgumentException('query must not exceed 500 characters.');
    }
    if (!in_array($mode, ['keyword', 'semantic'], true)) {
        throw new InvalidArgumentException('mode must be keyword or semantic.');
    }
    return [$query, $mode];
}

function external_api_json_payload(string $body): array {
    if (strlen($body)>5*1024*1024) throw new InvalidArgumentException('JSON payload must not exceed 5 MB.');
    try {
        $object = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Request body must contain valid JSON.');
    }
    if (!$object instanceof stdClass) {
        throw new InvalidArgumentException('Request body must be a JSON object.');
    }
    return (array)$object;
}
