<?php
/** Domain vocabulary assistance, not general-purpose translation. */
function search_semantic_query(string $query): string {
    // Longer phrases win. One pass prevents replacements from being translated again.
    $terms = [
        'tulong pangkalusugan' => 'health assistance',
        'pista ng pelikula' => 'film festival',
        'opisyal ng barangay' => 'barangay officials',
        'iskolar sa nutrisyon' => 'nutrition scholar',
        'pantawid pamilya' => 'Pantawid Pamilya conditional cash transfer',
        'mahihirap na pamilya' => 'low income families',
        'buwanang ayuda' => 'monthly financial assistance',
        'pagbibisikleta' => 'cycling',
        'paglalakad' => 'walking',
        'pagsasara' => 'closure',
        'pagbabantay' => 'monitoring',
        'pangkalusugan' => 'health',
        'matatanda' => 'senior citizens',
        'matanda' => 'senior citizens',
        'kabataan' => 'youth',
        'ospital' => 'hospital',
        'sakit' => 'disease',
        'nutrisyon' => 'nutrition',
        'ayuda' => 'financial assistance',
        'pagkilala' => 'recognition',
        'natatanging' => 'outstanding',
        'parangal' => 'awards',
        'nanalo' => 'winners',
        'pelikula' => 'film',
        'ordinansa' => 'ordinance',
        'resolusyon' => 'resolution',
    ];
    uksort($terms, static fn($a, $b) => strlen($b) <=> strlen($a));
    $patterns = array_map(static fn($term) => str_replace(' ', '\\s+', preg_quote($term, '~')), array_keys($terms));
    // Do not rewrite substrings, identifiers, or hyphenated document references.
    $pattern = '~(?<![\p{L}\p{N}_-])(?:' . implode('|', $patterns) . ')(?![\p{L}\p{N}_-])~iu';
    return preg_replace_callback($pattern, static function ($match) use ($terms) {
        $key = strtolower(preg_replace('/\s+/u', ' ', $match[0]));
        return $terms[$key];
    }, $query) ?? $query;
}
