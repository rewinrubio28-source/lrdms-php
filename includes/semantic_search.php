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

// Change this if the BERT service runs on a different host/port.
define('BERT_SERVICE_URL', 'http://localhost:5000/search');

function semantic_search($pdo, $query, $whereClause, $whereParams) {
    $ch = curl_init(BERT_SERVICE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Service unreachable, errored, or returned something unexpected —
    // fall back to keyword search rather than breaking the page.
    if ($response === false || $httpCode !== 200) {
        return keyword_search($pdo, $query, $whereClause, $whereParams);
    }

    $decoded = json_decode($response, true);
    $matchedIds = $decoded['document_ids'] ?? null;

    if ($matchedIds === null) {
        return keyword_search($pdo, $query, $whereClause, $whereParams);
    }

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

function keyword_search($pdo, $query, $whereClause, $whereParams) {
    $sql = "SELECT * FROM documents d
            WHERE ($whereClause)
              AND (title LIKE ? OR ocr_text LIKE ? OR body LIKE ? OR doc_number LIKE ?)
            ORDER BY enactment_date DESC
            LIMIT 25";
    $like = '%' . $query . '%';
    $params = array_merge($whereParams, [$like, $like, $like, $like]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
