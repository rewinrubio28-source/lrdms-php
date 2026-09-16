<?php
/**
<<<<<<< HEAD
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
=======
 * Document Template Parsers.
 *
 * Parses OCR text into structured fields based on document type.
 * Each parser splits the raw text into the form fields used by encoding.php.
 *
 * Returns an associative array where keys match the HTML form field names.
 * Fields that cannot be parsed are left empty for the user to fill.
 */

/**
 * Master dispatcher — calls the right parser for the given document type.
 */
function parse_document_fields($docType, $ocrText) {
    if (trim($ocrText) === '') return [];

    switch ($docType) {
        case 'Ordinance':        return _parse_ordinance($ocrText);
        case 'Resolution':       return _parse_resolution($ocrText);
        case 'Committee Report': return _parse_committee_report($ocrText);
        case 'Minutes':          return _parse_minutes($ocrText);
        default:                 return [];
    }
}

// ─── Ordinance ──────────────────────────────────────────────────
// Structure: WHEREAS clauses → Sections → Repealing Clause
function _parse_ordinance($text) {
    $fields = ['whereas' => '', 'sections' => '', 'repealing' => ''];

    // Extract WHEREAS clauses
    if (preg_match_all('/WHEREAS,\s*(.+?)(?=WHEREAS,|SECTION\s+\d|REPEALING\s+CLAUSE|$)/si', $text, $m)) {
        $clauses = array_map(function ($c) {
            $c = trim(preg_replace('/\s+/', ' ', $c));
            $c = rtrim($c, ';.');
            return $c;
        }, $m[1]);
        $fields['whereas'] = implode("\n", $clauses);
    }

    // Extract Sections
    if (preg_match_all('/SECTION\s+\d+\.\s*(.+?)(?=SECTION\s+\d+|REPEALING\s+CLAUSE|$)/si', $text, $m)) {
        $sections = array_map(function ($s) {
            return trim(preg_replace('/\s+/', ' ', $s));
        }, $m[1]);
        $fields['sections'] = implode("\n", $sections);
    }

    // Extract Repealing clause
    if (preg_match('/REPEALING\s+CLAUSE\.?\s*(.+?)$/si', $text, $m)) {
        $fields['repealing'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    return $fields;
}

// ─── Resolution ─────────────────────────────────────────────────
// Structure: WHEREAS clauses → RESOLVED clauses
function _parse_resolution($text) {
    $fields = ['whereas' => '', 'resolved' => ''];

    // Extract WHEREAS clauses
    if (preg_match_all('/WHEREAS,\s*(.+?)(?=WHEREAS,|RESOLVED|RESOLUTION|$)/si', $text, $m)) {
        $clauses = array_map(function ($c) {
            $c = trim(preg_replace('/\s+/', ' ', $c));
            $c = rtrim($c, ';.');
            return $c;
        }, $m[1]);
        $fields['whereas'] = implode("\n", $clauses);
    }

    // Extract RESOLVED clauses
    if (preg_match_all('/RESOLVED,?\s*(?:THAT\s+)?(.+?)(?=RESOLVED|WHEREAS|$)/si', $text, $m)) {
        $clauses = array_map(function ($c) {
            $c = trim(preg_replace('/\s+/', ' ', $c));
            $c = rtrim($c, '.;');
            return $c;
        }, $m[1]);
        $fields['resolved'] = implode("\n", $clauses);
    }

    return $fields;
}

// ─── Committee Report ───────────────────────────────────────────
// Structure: RE: subject → FINDINGS & DISCUSSION → RECOMMENDATION
function _parse_committee_report($text) {
    $fields = ['cr_re' => '', 'cr_findings' => '', 'cr_recommendation' => ''];

    // Extract RE: line
    if (preg_match('/RE:\s*(.+?)(?=FINDINGS|RECOMMENDATION|$)/si', $text, $m)) {
        $fields['cr_re'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // Extract FINDINGS & DISCUSSION
    if (preg_match('/FINDINGS\s*(?:&\s*DISCUSSION)?[:\s]*(.+?)(?=RECOMMENDATION|RE:|$)/si', $text, $m)) {
        $fields['cr_findings'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // Extract RECOMMENDATION
    if (preg_match('/RECOMMENDATION[:\s]*(.+?)$/si', $text, $m)) {
        $rec = trim(preg_replace('/\s+/', ' ', $m[1]));
        // Try to match standard recommendation options
        $fields['cr_recommendation'] = _match_recommendation($rec);
        if ($fields['cr_recommendation'] === '') {
            $fields['cr_findings'] .= "\n\nRecommendation: " . $rec;
        }
    }

    return $fields;
}

function _match_recommendation($text) {
    $lower = strtolower($text);
    if (strpos($lower, 'approve') !== false && strpos($lower, 'amend') !== false) return 'Approve with Amendments';
    if (strpos($lower, 'approve') !== false) return 'Approve';
    if (strpos($lower, 'disapprove') !== false) return 'Disapprove';
    if (strpos($lower, 'recommit') !== false) return 'Recommit';
    return '';
}

// ─── Minutes ────────────────────────────────────────────────────
// Structure: Session type, Venue, Presiding → Attendance → Agenda → Motions → Votes
function _parse_minutes($text) {
    $fields = [
        'mnt_session_type' => '', 'mnt_venue' => '', 'mnt_presiding' => '',
        'mnt_attendance' => '', 'mnt_agenda' => '', 'mnt_motions' => '',
        'mnt_votes' => '', 'mnt_adjourned' => '',
    ];

    // Session type
    if (preg_match('/Session\s+type:\s*(Regular|Special|Joint)/i', $text, $m)) {
        $fields['mnt_session_type'] = ucfirst(strtolower($m[1]));
    }

    // Venue
    if (preg_match('/Venue:\s*(.+?)(?=Presiding|Session\s+type|Attendance|Agenda|$)/si', $text, $m)) {
        $fields['mnt_venue'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // Presiding officer
    if (preg_match('/Presiding\s+officer:\s*(.+?)(?=Venue|Session|Attendance|Agenda|$)/si', $text, $m)) {
        $fields['mnt_presiding'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // Attendance
    if (preg_match('/ATTENDANCE(?:\s*&\s*QUORUM)?[:\s]*(.+?)(?=AGENDA|MOTIONS|VOTING|ADJOURN|$)/si', $text, $m)) {
        $fields['mnt_attendance'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // Agenda items
    if (preg_match('/AGENDA[:\s]*(.+?)(?=MOTIONS|DECISIONS|VOTING|ADJOURN|$)/si', $text, $m)) {
        $agenda = trim($m[1]);
        // Try to split numbered items
        $items = preg_split('/(?:^|\n)\s*\d+\.\s*/m', $agenda);
        $items = array_filter(array_map('trim', $items));
        $fields['mnt_agenda'] = implode("\n", array_map(function ($i, $n) {
            return ($n + 1) . '. ' . $i;
        }, array_values($items), array_keys($items)));
    }

    // Motions & decisions
    if (preg_match('/MOTIONS?\s*(?:&\s*DECISIONS?)?[:\s]*(.+?)(?=VOTING|ADJOURN|$)/si', $text, $m)) {
        $fields['mnt_motions'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // Voting results
    if (preg_match('/VOTING(?:\s+RESULTS?)?[:\s]*(.+?)(?=ADJOURN|$)/si', $text, $m)) {
        $fields['mnt_votes'] = trim(preg_replace('/\s+/', ' ', $m[1]));
    }

    // Adjourned at
    if (preg_match('/Adjourned\s+at:\s*(.+?)$/si', $text, $m)) {
        $fields['mnt_adjourned'] = trim($m[1]);
    } elseif (preg_match('/adjourned\s+(?:at\s+)?(\d{1,2}:\d{2}\s*(?:AM|PM))/i', $text, $m)) {
        $fields['mnt_adjourned'] = trim($m[1]);
    }

    return $fields;
}
>>>>>>> origin/main
