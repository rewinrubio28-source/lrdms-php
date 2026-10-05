<?php
require_once __DIR__ . '/search_language.php';

/** Static public vocabulary only: never derive suggestions from hidden records. */
function search_spelling_suggestion(string $query): ?string {
    if (mb_strlen($query) > 300) return null;
    $vocabulary = ['ordinance', 'resolution', 'committee', 'hospital', 'nutrition',
        'assistance', 'citizens', 'senior', 'hearing', 'barangay', 'ordinansa',
        'resolusyon', 'matatanda', 'matanda', 'nakatatanda', 'kalusugan', 'kabataan',
        'nutrisyon', 'pagamutan', 'ayuda'];
    $changed = false;
    $suggestion = preg_replace_callback('/(?<![\p{L}\p{N}_-])[a-z]{5,30}(?![\p{L}\p{N}_-])/iu',
        static function ($match) use ($vocabulary, &$changed) {
            $word = strtolower($match[0]);
            if (in_array($word, $vocabulary, true)) return $match[0];
            $candidates = [];
            foreach ($vocabulary as $candidate) {
                if (levenshtein($word, $candidate) === 1) $candidates[] = $candidate;
            }
            if (count($candidates) !== 1) return $match[0];
            $changed = true;
            return $candidates[0];
        }, $query);
    return $changed && $suggestion !== null ? $suggestion : null;
}

function search_display_terms(string $query, bool $assisted): array {
    $phrases = array_values(array_unique(array_filter([trim($query), $assisted ? search_semantic_query($query) : ''])));
    $terms = $phrases;
    $stop = ['the','and','for','with','from','para','mga','ang','ng','sa','na','of','in','to'];
    foreach ($phrases as $phrase) {
        foreach (preg_split('/[^\p{L}\p{N}-]+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $term) {
            if (mb_strlen($term) >= 3 && !in_array(mb_strtolower($term), $stop, true)) $terms[] = $term;
        }
    }
    $terms = array_values(array_unique($terms));
    usort($terms, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return array_slice($terms, 0, 40);
}

function search_highlight(string $text, array $terms): string {
    $terms = array_values(array_filter($terms, static fn($term) => $term !== ''));
    if (!$terms) return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $pattern = '~(' . implode('|', array_map(static fn($term) => preg_quote($term, '~'), $terms)) . ')~iu';
    $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '';
    foreach ($parts as $i => $part) {
        $safe = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html .= $i % 2 ? '<mark>' . $safe . '</mark>' : $safe;
    }
    return $html;
}

/** Mirrors the service's combined text so passage offsets cannot reference stale text. */
function search_document_text(array $row): string {
    $text = ($row['title'] ?? '') . '. ' . ($row['description'] ?? '') . ' ' . ($row['doc_number'] ?? '') . ' '
        . ($row['body'] ?? '') . ' ' . ($row['ocr_text'] ?? '');
    return preg_replace('/^\s+|\s+$/u', '', $text) ?? trim($text);
}

function search_result_preview(array $row, array $terms): array {
    $passage = $row['_search_passage'] ?? null;
    if (is_array($passage)) {
        $text = search_document_text($row);
        $start = $passage['start'] ?? null;
        $end = $passage['end'] ?? null;
        if (is_int($start) && is_int($end) && $start >= 0 && $end > $start && $end <= mb_strlen($text)
            && is_string($passage['text_hash'] ?? null) && hash_equals(md5($text), $passage['text_hash'])) {
            $text = mb_substr($text, $start, $end-$start);
            return search_preview_excerpt($text, $terms, 'Matching passage', $start > 0);
        }
    }
    $sources = ['body'=>'Document text', 'ocr_text'=>'OCR text', 'description'=>'Filing notes', 'title'=>'Title'];
    $fallback = null;
    foreach ($sources as $key => $label) {
        $text = trim(strip_tags((string)($row[$key] ?? '')));
        if ($text === '') continue;
        $fallback = $fallback ?? search_preview_excerpt($text, [], $label . ' preview');
        foreach ($terms as $term) {
            if (mb_stripos($text, $term) !== false) return search_preview_excerpt($text, $terms, $label . ' match');
        }
    }
    return $fallback ?? ['label'=>'Preview', 'text'=>'No searchable text preview available.'];
}

function search_preview_excerpt(string $text, array $terms, string $label, bool $prefix = false): array {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
    $position = 0;
    foreach ($terms as $term) {
        $found = mb_stripos($text, $term);
        if ($found !== false) { $position = max(0, $found-65); break; }
    }
    $excerpt = mb_substr($text, $position, 280);
    return ['label'=>$label, 'text'=>($prefix || $position > 0 ? '…' : '') . $excerpt
        . ($position + mb_strlen($excerpt) < mb_strlen($text) ? '…' : '')];
}
