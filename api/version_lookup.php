<?php
/**
 * Legislative Document Search — AJAX lookup for version.php.
 *
 * Internal, session-authenticated (NOT the X-API-Key external endpoints
 * in this folder — those are for other systems; this one is called by
 * the browser of a logged-in LRDMS user). Same RBAC visibility rules as
 * every other internal list view.
 *
 * GET /api/version_lookup.php?q=...&type=...&status=...
 *
 * Deliberately returns HEAD documents only (next_version_id IS NULL) —
 * one row per document "family" — and caps results, so the Version
 * Control page never has to load its whole repository into the browser
 * to power the picker. See sql/schema.sql idx_next_version.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rbac.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

require_login();
$user = current_user();
$pdo = get_db();

$query = trim($_GET['q'] ?? '');
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

list($visClause, $visParams) = document_visibility_clause($user);

$where = ["d.next_version_id IS NULL", "($visClause)"];
$params = $visParams;

if ($query !== '') {
    $where[] = '(d.doc_number LIKE ? OR d.title LIKE ? OR d.sponsor LIKE ? OR d.ocr_text LIKE ?)';
    $like = '%' . $query . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($typeFilter !== '') {
    $where[] = 'd.doc_type = ?';
    $params[] = $typeFilter;
}
if ($statusFilter !== '') {
    $where[] = 'd.status = ?';
    $params[] = $statusFilter;
}

$sql = 'SELECT d.id, d.doc_number, d.title, d.doc_type, d.status, d.sponsor, d.committee_id,
               d.enactment_date, d.previous_version_id, c.name AS committee_name
        FROM documents d
        LEFT JOIN committees c ON c.id = d.committee_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY d.enactment_date DESC, d.updated_at DESC
        LIMIT 20';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Version count per family, without pulling every ancestor row back —
// walk previous_version_id counting hops (chains are short in practice;
// this stays well within one query per result, capped at 20 results).
function _lrdms_chain_length($pdo, $prevId) {
    $n = 1;
    while ($prevId) {
        $stmt = $pdo->prepare('SELECT previous_version_id FROM documents WHERE id = ?');
        $stmt->execute([$prevId]);
        $prevId = $stmt->fetchColumn();
        $n++;
    }
    return $n;
}

$out = [];
foreach ($rows as $d) {
    $out[] = [
        'id'             => (int)$d['id'],
        'doc_number'     => $d['doc_number'],
        'title'          => $d['title'],
        'doc_type'       => $d['doc_type'],
        'status'         => $d['status'],
        'sponsor'        => $d['sponsor'],
        'committee_name' => $d['committee_name'],
        'enactment_date' => $d['enactment_date'],
        'version_count'  => _lrdms_chain_length($pdo, $d['previous_version_id']),
    ];
}

echo json_encode(['count' => count($out), 'results' => $out]);
