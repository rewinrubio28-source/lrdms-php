<?php
// Pure ranking regression tests: no database, network, or model required.
require_once __DIR__ . '/includes/semantic_search.php';
function check_hybrid($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$a = ['id'=>1, 'doc_number'=>'ORD-100'];
$b = ['id'=>2, 'doc_number'=>'ORD-200'];
$c = ['id'=>3, 'doc_number'=>'ORD-300'];
$ids = static fn($rows) => array_column($rows, 'id');
check_hybrid($ids(hybrid_rank_results([$a,$b], [$c], 'ord-300')) === [3,1,2], 'Exact reference must outrank semantic-only results');
check_hybrid($ids(hybrid_rank_results([$a,$b], [$b,$c], 'topic')) === [2,1,3], 'Agreement between ranking sources should receive a boost');
check_hybrid($ids(hybrid_rank_results([$a,$a], [$a], 'topic')) === [1], 'Duplicates must not become separate results');
check_hybrid($ids(hybrid_rank_results([], [$c,$b], 'topic')) === [3,2], 'Empty semantic results must retain keyword matches');
check_hybrid($ids(hybrid_rank_results([$a,$b], [], 'topic')) === [1,2], 'Empty keyword results must retain semantic matches');
check_hybrid(count(hybrid_rank_results([$a,$b], [$c], 'topic', 2)) === 2, 'API limit must apply after merging');
check_hybrid(count(hybrid_rank_results([$a,$b], [$c], 'topic', null)) === 3, 'Website must retain all candidates for filtering and pagination');
check_hybrid(hybrid_rank_results([], [], 'topic') === [], 'Empty candidate sets must remain empty');
echo "PASS: 8 hybrid ranking checks\n";
