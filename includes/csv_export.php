<?php
/** Preserve UTF-8/quoting while preventing spreadsheet formula execution. */
function export_csv_cell($value): string {
    $text=(string)($value??'');
    return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u',$text) ? "'".$text : $text;
}

/** Caller supplies a cursor, not a fetchAll array. Returns the exact row count. */
function write_audit_csv($output, iterable $logs): int {
    if (fwrite($output,"\xEF\xBB\xBF")===false || fputcsv($output,['Timestamp','Username','Full Name','Role','Module','Action','Detail','IP Address'],',','"','')===false) throw new RuntimeException('Could not write export.');
    $count=0;
    foreach ($logs as $row) {
        $values=[];
        foreach (['created_at','username_snapshot','actor_full_name','actor_role','module','action','detail','ip_address'] as $key) $values[]=export_csv_cell($row[$key]??'');
        if (fputcsv($output,$values,',','"','')===false) throw new RuntimeException('Could not finish export.');
        $count++;
    }
    return $count;
}
