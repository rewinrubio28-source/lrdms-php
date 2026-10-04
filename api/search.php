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
require_once __DIR__ . '/../includes/external_api.php';

header('Content-Type: application/json');

// Shared secret comes from the API_SHARED_KEY environment variable —
// same value as api/upload_document.php uses. Never hardcode it here.
require_once __DIR__ . '/../config/env.php';
load_env_file();
external_api_require_request('GET');
try {
    [$query, $mode] = external_api_search_parameters($_GET);
} catch (InvalidArgumentException $e) {
    external_api_error(422, $e->getMessage());
}

$pdo = get_db();
// Same public-visibility rule as includes/rbac.php's document_visibility_clause()
// for an anonymous caller — is_public is the persistent "was this authorized to
// be public" flag; a document keeps showing here after being Amended/Withdrawn/
// Superseded (that's the point of a records archive), just never at the
// pre-filing stage (Draft/Submitted/Under Review), which isn't LRDMS's to show.
$visClause = "d.verified_at IS NOT NULL AND d.is_public = 1 AND d.status NOT IN ('Draft','Submitted','Under Review')";
$searchExecution = ['effective_mode' => 'keyword', 'fallback' => false];
$results = $mode === 'semantic'
    ? semantic_search($pdo, $query, $visClause, [], 25, $searchExecution)
    : keyword_search($pdo, $query, $visClause, []);

$stmt = $pdo->prepare('INSERT INTO search_log (user_id, query, search_type, results_count) VALUES (NULL, ?, ?, ?)');
$stmt->execute([$query, $searchExecution['effective_mode'], count($results)]);
log_action('search', 'api_query', 'external requested=' . $mode . ' effective=' . $searchExecution['effective_mode'] . ' query=' . $query . ' results=' . count($results));

echo json_encode([
    'mode' => $mode,
    'effective_mode' => $searchExecution['effective_mode'],
    'strategy' => $searchExecution['strategy'] ?? 'keyword',
    'language_assisted' => $searchExecution['language_assisted'] ?? false,
    'fallback' => $searchExecution['fallback'],
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
