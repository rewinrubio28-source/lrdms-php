<?php
/**
 * Rule-based Named Entity Detection for OCR text.
 *
 * Detects common entity types in Philippine legislative documents:
 * person names, dates, monetary amounts, legal sections, and percentages.
 *
 * This is a lightweight, dependency-free alternative to NLP-based NER.
 * It uses regex patterns tuned for Filipino legislative text.
 */

/**
 * Detects entities in the given text and returns an array of
 * ['type' => ..., 'text' => ...] entries.
 */
function detect_entities($text) {
    $entities = [];
    $seen = [];

    $add = function ($type, $match) use (&$entities, &$seen) {
        $val = trim($match);
        if ($val === '' || isset($seen[$type][$val])) return;
        $seen[$type][$val] = true;
        $entities[] = ['type' => $type, 'text' => $val];
    };

    // ── Monetary amounts ──
    // ₱1,234.56 or PHP 1,234 or $500
    if (preg_match_all('/(?:₱|PHP|USD|\$)\s*[\d,]+(?:\.\d{2})?/i', $text, $m)) {
        foreach ($m[0] as $v) $add('amount', $v);
    }

    // ── Percentages ──
    if (preg_match_all('/\d+(?:\.\d+)?\s*%/', $text, $m)) {
        foreach ($m[0] as $v) $add('percentage', $v);
    }

    // ── Dates ──
    // "January 15, 2026" / "15 January 2026" / "01/15/2026" / "2026-01-15"
    $months = '(?:January|February|March|April|May|June|July|August|September|October|November|December|Enero|Pebrero|Marso|Abril|Mayo|Hunyo|Hulyo|Agosto|Setyembre|Oktubre|Nobyembre|Disyembre)';
    if (preg_match_all("/$months\s+\d{1,2},?\s+\d{4}/i", $text, $m)) {
        foreach ($m[0] as $v) $add('date', $v);
    }
    if (preg_match_all("/\d{1,2}\s+$months\s+\d{4}/i", $text, $m)) {
        foreach ($m[0] as $v) $add('date', $v);
    }
    if (preg_match_all('/\d{1,2}\/\d{1,2}\/\d{4}/', $text, $m)) {
        foreach ($m[0] as $v) $add('date', $v);
    }
    if (preg_match_all('/\d{4}-\d{2}-\d{2}/', $text, $m)) {
        foreach ($m[0] as $v) $add('date', $v);
    }

    // ── Legal section markers ──
    if (preg_match_all('/(?:WHEREAS|SECTION\s+\d+|ARTICLE\s+\d+|REPEALING\s+CLAUSE|RESOLVED|FINDINGS\s*&\s*DISCUSSION|RECOMMENDATION|ATTENDANCE|AGENDA|MOTIONS|VOTING\s+RESULTS)/i', $text, $m)) {
        foreach ($m[0] as $v) $add('legal', trim($v));
    }

    // ── Person names ──
    // Filipino name patterns: "Juan Dela Cruz", "Maria Santos", "Pedro Jr.", "Engr. Reyes"
    // Match: Title? Firstname Lastname (with optional suffixes)
    $titles = '(?:Hon\.|Mr\.|Mrs\.|Ms\.|Dr\.|Engr\.|Atty\.|Prof\.)';
    $suffixes = '(?:Jr\.?|Sr\.?|II|III|IV)';
    // Title + Name
    if (preg_match_all("/$titles\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*(?:\s+$suffixes)?/", $text, $m)) {
        foreach ($m[0] as $v) $add('person', $v);
    }
    // Filipino name: "Firstname Lastname" or "Firstname M. Lastname" or "Firstname Dela Lastname"
    // Two capitalized words in a row that aren't at line start and aren't legal terms
    $legalSkip = '(?:WHEREAS|SECTION|ARTICLE|REPEALING|RESOLVED|FINDINGS|RECOMMENDATION|ATTENDANCE|AGENDA|MOTIONS|VOTING|ORDINANCE|RESOLUTION|COMMITTEE|SESSION|PLANNED|ENACTED|AMENDED|SUPERSEDED)';
    if (preg_match_all("/(?:^|\.\s+)([A-Z][a-z]+(?:\s+(?:de\s+la\s+|del\s+|van\s+|von\s+)?[A-Z][a-z]+)+(?:\s+$suffixes)?)/m", $text, $m)) {
        foreach ($m[1] as $v) {
            $clean = trim($v);
            if (preg_match("/^$legalSkip$/i", $clean)) continue;
            $add('person', $clean);
        }
    }

    // ── Document numbers ──
    // ORD-2026-001, RES-2026-005, CR-2026-010
    if (preg_match_all('/\b(?:ORD|RES|CR|MIN)-\d{4}-\d{3}\b/', $text, $m)) {
        foreach ($m[0] as $v) $add('doc_number', $v);
    }

    return $entities;
}
