<?php
/**
 * Read-only REST endpoint used by the Research & Policy Analysis System
 * (System 9) and the Citizen Engagement / Public Feedback System to
 * query this repository.
 *
 * GET /api/search.php?query=...&mode=keyword|semantic  (mode optional, defaults to keyword)
 * Header: X-API-Key: <shared secret>
 *
 * External systems only ever see enacted, public records — the
 * status/ownership visibility layer from includes/rbac.php collapses to
 * that single rule for anyone outside the logged-in application.
 *
 * mode=semantic routes through the same BERT microservice
 * (bert_service/) that the internal search page uses, and falls back
 * to keyword search automatically if that service is unreachable —
 * see includes/semantic_search.php.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/semantic_search.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json');

define('API_SHARED_KEY', 'change-this-shared-key');

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!hash_equals(API_SHARED_KEY, $providedKey)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key.']);
    exit;
}

$query = trim($_GET['query'] ?? '');
if ($query === '') {
    http_response_code(422);
    echo json_encode(['error' => 'query parameter is required.']);
    exit;
}

$mode = ($_GET['mode'] ?? 'keyword') === 'semantic' ? 'semantic' : 'keyword';

$pdo = get_db();
$visClause = "status = 'Enacted' AND is_public = 1";
$results = $mode === 'semantic'
    ? semantic_search($pdo, $query, $visClause, [])
    : keyword_search($pdo, $query, $visClause, []);

$stmt = $pdo->prepare('INSERT INTO search_log (user_id, query, search_type, results_count) VALUES (NULL, ?, ?, ?)');
$stmt->execute([$query, $mode, count($results)]);
log_action('search', 'api_query', 'external → "' . $query . '" (' . $mode . ') — ' . count($results) . ' results');

echo json_encode([
    'mode' => $mode,
    'query' => $query,
    'results' => array_map(function ($d) {
        return [
            'id'             => (int)$d['id'],
            'doc_number'     => $d['doc_number'],
            'title'          => $d['title'],
            'doc_type'       => $d['doc_type'],
            'enactment_date' => $d['enactment_date'],
        ];
    }, $results),
]);
