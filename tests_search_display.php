<?php
require_once __DIR__ . '/includes/search_display.php';
require_once __DIR__ . '/includes/semantic_search.php';
function display_check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
display_check(search_spelling_suggestion('ordinace') === 'ordinance', 'Expected explicit typo suggestion');
display_check(search_spelling_suggestion('ORD-ORDINACE-2024') === null, 'Do not change document references');
display_check(search_spelling_suggestion('ordinance') === null, 'Correct spelling needs no suggestion');
display_check(search_spelling_suggestion('Roxas Boulevard') === null, 'Do not guess names');
$row = ['id'=>1, 'title'=>'Allowance', 'description'=>'', 'doc_number'=>'ORD-9081',
    'body'=>str_repeat('Introduction. ', 100) . 'Monthly financial assistance for senior citizens.', 'ocr_text'=>''];
$terms = search_display_terms('matatanda', true);
$preview = search_result_preview($row, $terms);
display_check(str_contains($preview['text'], 'senior citizens'), 'Locate keyword matches near the end');
display_check($preview['label'] === 'Document text match', 'Accurate keyword label');
$text = search_document_text($row);
$row['_search_passage'] = ['start'=>mb_strpos($text, 'Monthly'), 'end'=>mb_strlen($text), 'text_hash'=>md5($text)];
$preview = search_result_preview($row, []);
display_check($preview['label'] === 'Matching passage' && str_contains($preview['text'], 'Monthly'), 'Resolve authorized passage offsets');
$merged = hybrid_rank_results([$row], [array_diff_key($row, ['_search_passage'=>1])], 'matatanda');
display_check(isset($merged[0]['_search_passage']), 'Merging keyword rows must preserve semantic passage');
$row['body'] = 'Changed source text';
display_check(search_result_preview($row, [])['label'] !== 'Matching passage', 'Reject stale passage hash');
$row['_search_passage'] = ['start'=>-1, 'end'=>99999, 'text_hash'=>md5(search_document_text($row))];
display_check(search_result_preview($row, [])['label'] !== 'Matching passage', 'Reject out-of-bounds passage');
$html = search_highlight('<script>alert("x")</script> senior citizens & aid', ['senior citizens','script']);
display_check(!str_contains($html, '<script>') && str_contains($html, '<mark>senior citizens</mark>') && str_contains($html, '&amp;'), 'Escape HTML before highlighting');
display_check(search_result_preview([], [])['text'] === 'No searchable text preview available.', 'Empty source fallback');
echo "PASS: spelling suggestions, passage integrity, merge metadata, late-text preview, and safe highlighting\n";
