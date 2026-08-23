<?php
/**
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
