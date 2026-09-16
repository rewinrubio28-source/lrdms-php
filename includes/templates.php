<?php
/**
 * OCR Reference-Metadata Extraction.
 *
 * Parses OCR text to auto-fill the Encoding form's *metadata* fields —
 * title, sponsor/author, reference number, enactment date — for a document
 * that already exists. This does NOT parse or reconstruct legal clause
 * content (no WHEREAS/SECTION/RESOLVED extraction); the Encoding module
 * only files and classifies documents, it doesn't author or rebuild their
 * text. Title detection stops at the first clause boundary for the same
 * reason — it captures the caption line, never the body.
 *
 * Returns an associative array where keys match the HTML form field names
 * on encoding.php. Fields that cannot be confidently detected are left empty
 * for the records officer to fill in manually.
 */

/**
 * Entry point — kept as a single dispatcher (docType currently unused since
 * the metadata fields are the same across all document types) so the call
 * site in api/ocr_scan.php does not need to change.
 */
function parse_document_fields($docType, $ocrText) {
    $text = trim((string) $ocrText);
    if ($text === '') return [];

    return [
        'title'          => _detect_title($text),
        'sponsor'        => _detect_sponsor($text),
        'doc_number'     => _detect_reference_number($text),
        'enactment_date' => _detect_enactment_date($text),
    ];
}

// ─── Title ────────────────────────────────────────────────────────
// Legislative documents almost always open with a standard caption line —
// "AN ORDINANCE ...", "A RESOLUTION ...", or "COMMITTEE REPORT NO. X ON ...".
// Captures from that opening phrase up to the first clause boundary
// (WHEREAS / BE IT ORDAINED-RESOLVED-ENACTED / NOW THEREFORE / SECTION 1 /
// a blank line), never the clause text itself. Left empty — for the records
// officer to fill in or correct — if no confident caption/boundary is found.
function _detect_title($text) {
    $pattern = '/\b(AN\s+ORDINANCE|A\s+RESOLUTION|COMMITTEE\s+REPORT\s+NO\.?\s*\d+)\b([\s\S]*?)'
             . '(?=\r?\n\s*\r?\n|\bWHEREAS\b|\bBE\s+IT\s+(?:ORDAINED|RESOLVED|ENACTED)\b|\bNOW,?\s*THEREFORE\b|\bSECTION\s+1\b|$)/i';

    if (preg_match($pattern, $text, $m)) {
        $title = _clean_line(preg_replace('/\s+/', ' ', $m[1] . $m[2]));
        // No boundary found nearby (e.g. noisy OCR) → the match ran on too
        // long to be a real title; better to leave it blank than fill garbage.
        if ($title !== '' && mb_strlen($title) <= 300) {
            return $title;
        }
    }
    return '';
}

// ─── Sponsor / Author ───────────────────────────────────────────
// Looks for an explicit "Sponsored by:" / "Author:" / "Introduced by:" line,
// falling back to the first titled name found (e.g. "Hon. Juan Dela Cruz").
function _detect_sponsor($text) {
    if (preg_match('/(?:Sponsored\s+by|Author|Introduced\s+by)\s*:\s*(.+)/i', $text, $m)) {
        return _clean_line($m[1]);
    }
    if (preg_match('/(?:Hon\.|Councilor|Council\s?member)\s+([A-Z][a-zA-Z.\s]+)/', $text, $m)) {
        return _clean_line('Hon. ' . $m[1]);
    }
    return '';
}

// ─── Reference / Document Number ────────────────────────────────
// Matches this system's own numbering convention if it already appears on
// the source document (e.g. a document that was previously assigned a
// number and is being re-filed, or scanned from a printed copy that already
// carries a reference number).
function _detect_reference_number($text) {
    if (preg_match('/\b(?:ORD|RES|CR|MIN)-\d{4}-\d{3,4}\b/', $text, $m)) {
        return $m[0];
    }
    return '';
}

// ─── Enactment Date ──────────────────────────────────────────────
// Detects a written-out or numeric date and normalizes it to YYYY-MM-DD so
// it can populate the <input type="date"> field directly.
function _detect_enactment_date($text) {
    $months = '(?:January|February|March|April|May|June|July|August|September|October|November|December)';

    if (preg_match("/($months)\s+(\d{1,2}),?\s+(\d{4})/i", $text, $m)) {
        $ts = strtotime("{$m[1]} {$m[2]} {$m[3]}");
        if ($ts) return date('Y-m-d', $ts);
    }
    if (preg_match("/(\d{1,2})\s+($months)\s+(\d{4})/i", $text, $m)) {
        $ts = strtotime("{$m[1]} {$m[2]} {$m[3]}");
        if ($ts) return date('Y-m-d', $ts);
    }
    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $text, $m)) {
        return $m[0];
    }
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $text, $m)) {
        $ts = mktime(0, 0, 0, (int)$m[1], (int)$m[2], (int)$m[3]);
        if ($ts) return date('Y-m-d', $ts);
    }
    return '';
}

function _clean_line($s) {
    $s = preg_replace('/\s+/', ' ', trim($s));
    // Stop at the end of the line/sentence, not the rest of the paragraph.
    $s = preg_split('/[\r\n]|(?<=[a-z])\.(?=\s|$)/', $s)[0];
    return trim($s, " \t\n\r\0\x0B.,;:");
}