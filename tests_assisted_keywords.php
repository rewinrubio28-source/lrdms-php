<?php
require_once __DIR__ . '/includes/semantic_search.php';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE documents (id INTEGER, title TEXT, ocr_text TEXT, body TEXT, doc_number TEXT, enactment_date TEXT, verified_at TEXT, owner INTEGER)');
$insert = $pdo->prepare('INSERT INTO documents VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([
    [1, 'Monthly assistance for senior citizens', '', '', 'ORD-9081', '2024-01-01', '2024-01-02', 7],
    [2, 'Senior citizens private record', '', '', 'ORD-2', '2024-01-01', '2024-01-02', 8],
    [3, 'Senior citizens pending record', '', '', 'ORD-3', '2024-01-01', null, 7],
    [4, 'Matatanda senior citizens', '', '', 'ORD-4', '2024-01-01', '2024-01-02', 7],
] as $row) $insert->execute($row);
$where = 'd.verified_at IS NOT NULL AND d.owner = ?';
foreach (['matanda', 'matatanda'] as $query) {
    $rows = assisted_keyword_search($pdo, $query, $where, [7], null);
    $ids = array_column($rows, 'id');
    sort($ids);
    if ($ids !== [1,4]) throw new RuntimeException('Missing matches, duplicates, or unauthorized rows');
}
if (count(assisted_keyword_search($pdo, 'matatanda', $where, [7], 1)) !== 1) throw new RuntimeException('Limit regression');
if (array_column(assisted_keyword_search($pdo, 'ORD-9081', $where, [7]), 'id') !== [1]) throw new RuntimeException('Reference regression');
if (assisted_keyword_search($pdo, 'missing phrase', $where, [7]) !== []) throw new RuntimeException('Unexpected matches');
echo "PASS: assisted keyword retrieval, access/registration filtering, deduplication, limits, and references\n";
