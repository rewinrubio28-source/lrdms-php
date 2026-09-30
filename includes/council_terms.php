<?php
function council_term_label(int $term): string {
    $suffix = ($term % 100 >= 11 && $term % 100 <= 13) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$term % 10] ?? 'th');
    return $term . $suffix . ' City Council';
}
