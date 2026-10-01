<?php
/**
 * Document Retrieval & Search.
 *
 * keyword_search() is real and works today: it's a parameterized SQL
 * query against the documents table, respecting whatever visibility
 * clause the caller passes in (see includes/rbac.php).
 *
 * semantic_search() is the BERT-backed "meaning, not just words" search
 * from the thesis brief. PHP cannot run a transformer model itself, so
 * the real work happens in a standalone Python microservice
 * (bert_service/app.py — Flask + sentence-transformers) that this
 * function calls over HTTP, the same "call a small Python service"
 * pattern used for OCR in includes/ocr.php. If that service is
 * unreachable or errors out, this falls back to keyword_search() so
 * the rest of the app — the search page, the REST API, the search
 * log — keeps working even when the BERT service happens to be down.
 *
 * See bert_service/README.md for how to install and run the service.
 */

require_once __DIR__ . '/../config/env.php';
load_env_file();

// In production set BERT_SERVICE_URL (HostForge -> Environment Variables) to the
// deployed service, e.g. https://bert.example.com - "/search" is added if missing.
// With no variable set it falls back to localhost for local development.
$__bertUrl = rtrim((string) env_optional('BERT_SERVICE_URL', 'http://localhost:5000'), '/');
if (substr($__bertUrl, -7) !== '/search') {
    $__bertUrl .= '/search';
}
define('BERT_SERVICE_URL', $__bertUrl);
unset($__bertUrl);

// Optional shared secret - must match BERT_API_KEY on the BERT service.
define('BERT_API_KEY', (string) env_optional('BERT_API_KEY', ''));

function semantic_search($pdo, $query, $whereClause, $whereParams, $fallbackLimit = 25, &$execution = null) {
    $execution = ['effective_mode' => 'semantic', 'fallback' => false];
    $fallback = function () use ($pdo, $query, $whereClause, $whereParams, $fallbackLimit, &$execution) {
        $execution = ['effective_mode' => 'keyword', 'fallback' => true];
        return keyword_search($pdo, $query, $whereClause, $whereParams, $fallbackLimit);
    };
    if (!function_exists('curl_init')) return $fallback();
    $ch = curl_init(BERT_SERVICE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
    $headers = ['Content-Type: application/json'];
    if (BERT_API_KEY !== '') {
        $headers[] = 'X-API-Key: ' . BERT_API_KEY;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Service unreachable, errored, or returned something unexpected —
    // fall back to keyword search rather than breaking the page.
    if ($response === false || $httpCode !== 200) {
        return $fallback();
    }

    $decoded = json_decode($response, true);
    $matchedIds = is_array($decoded) ? ($decoded['document_ids'] ?? null) : null;

    if (!is_array($matchedIds) || !array_is_list($matchedIds) || count($matchedIds) > 1000) {
        return $fallback();
    }
    foreach ($matchedIds as $id) {
        if (!is_int($id) || $id < 1) return $fallback();
    }
    $matchedIds = array_values(array_unique($matchedIds));

    if (!$matchedIds) {
        return [];
    }

    // The BERT service only ranks by meaning — RBAC visibility is
    // still enforced here, exactly as in keyword_search().
    $placeholders = implode(',', array_fill(0, count($matchedIds), '?'));
    $sql = "SELECT * FROM documents d WHERE id IN ($placeholders) AND ($whereClause)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($matchedIds, $whereParams));
    $rows = $stmt->fetchAll();

    // Preserve the BERT service's relevance ranking (SQL's IN() does
    // not guarantee result order matches the ids list).
    $rank = array_flip($matchedIds);
    usort($rows, function ($a, $b) use ($rank) {
        return ($rank[$a['id']] ?? PHP_INT_MAX) <=> ($rank[$b['id']] ?? PHP_INT_MAX);
    });

    return $rows;
}

function keyword_search($pdo, $query, $whereClause, $whereParams, $limit = 25) {
    $sql = "SELECT * FROM documents d
            WHERE ($whereClause)
              AND (title LIKE ? OR ocr_text LIKE ? OR body LIKE ? OR doc_number LIKE ?)
            ORDER BY (doc_number = ?) DESC, (title = ?) DESC, (title LIKE ?) DESC, enactment_date DESC, id DESC";
    if ($limit !== null) $sql .= ' LIMIT ' . max(1, (int)$limit);
    $like = '%' . $query . '%';
    $params = array_merge($whereParams, [$like, $like, $like, $like, $query, $query, $like]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
