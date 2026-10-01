<?php
function dashboard_report_rows(array $data, array $user): array {
    $f=$data['filters'];
    $rows=[['LRDMS Dashboard Report','',''],['Generated (Asia/Manila)',$data['generated_at'],''],['Prepared for',$user['full_name']??$user['username'],''],['Creation date range',$f['from'],$f['to']],['Document type',$f['type'],''],['Current status',$f['status'],''],['Scope','Current visibility/status; creation-date history. User totals are current.',''],['Section','Metric','Value']];
    $kpis=['Visible registered records'=>$data['totalDocs'],'Enacted records'=>$data['enactedCount'],'Rejected records'=>$data['rejectedCount'],'Public enacted records'=>$data['publicCount'],'Public records (all statuses)'=>$data['pubCount'],'Not public'=>$data['restrictedCount'],'Current revision heads'=>$data['versionChains'],'Revision records'=>$data['totalRevisions'],'Original records'=>$data['totalDocs']-$data['totalRevisions']];
    if ($data['canEncode']) { $kpis['Awaiting verification']=$data['awaitingVerificationCount']; $kpis['Needs digitization']=$data['pendingDigit']; }
    foreach ($kpis as $label=>$value) $rows[]=['Records',$label,(int)$value];
    foreach ($data['monthData'] as $row) $rows[]=['Creation month',$row['month'],(int)$row['n']];
    foreach ($data['typeCounts'] as $row) $rows[]=['Document type',$row['doc_type'],(int)$row['n']];
    foreach ($data['statusCounts'] as $label=>$value) $rows[]=['Current status',$label,(int)$value];
    if ($data['canSearch']) {
        $section=$data['canAudit']?'Search activity':'Your search activity';
        foreach (['Total'=>$data['totalSearches'],'Keyword'=>$data['keywordSearches'],'Semantic'=>$data['semanticSearches']] as $label=>$value) $rows[]=[$section,$label,(int)$value];
    }
    if ($data['canAccess']) {
        foreach (['Total'=>$data['totalUsers'],'Active'=>$data['activeUsers'],'Disabled'=>$data['inactiveUsers']] as $label=>$value) $rows[]=['Current accounts',$label,(int)$value];
        foreach ($data['usersByRole'] as $row) $rows[]=['Current accounts by role',$row['name'],(int)$row['n']];
    }
    return $rows;
}
function dashboard_csv_cell($value) {
    if (is_string($value) && preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u',$value)) return "'".$value;
    return $value;
}
function dashboard_report_bytes(array $rows, string $format): string {
    if (!in_array($format,['csv','xlsx','pdf'],true)) throw new InvalidArgumentException('Choose PDF, Excel or CSV.');
    if ($format==='csv') {
        $out=fopen('php://temp','w+'); fwrite($out,"\xEF\xBB\xBF");
        foreach ($rows as $row) fputcsv($out,array_map('dashboard_csv_cell',$row),',','"','');
        rewind($out); $bytes=stream_get_contents($out); fclose($out); return $bytes;
    }
    $autoload=__DIR__.'/../vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Report dependencies are missing. Run composer install or rebuild the application image.');
    require_once $autoload;
    if ($format==='xlsx') {
        $safe=array_map(static fn($row)=>array_map(static fn($v)=>is_string($v)?"\0".$v:$v,$row),$rows);
        return (string)\Shuchkin\SimpleXLSXGen::fromArray($safe,'Dashboard report');
    }
    $html='<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#172b4d}h1{font-size:18px}table{border-collapse:collapse;width:100%}td{border:1px solid #cbd5e1;padding:5px;word-wrap:break-word}tr{page-break-inside:avoid}tr:nth-child(even){background:#f1f5f9}</style></head><body><h1>LRDMS Dashboard Report</h1><table>';
    foreach ($rows as $row) { $html.='<tr>'; foreach ($row as $cell) $html.='<td>'.htmlspecialchars((string)$cell,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</td>'; $html.='</tr>'; }
    $html.='</table></body></html>';
    $options=new \Dompdf\Options(); $options->set('isRemoteEnabled',false); $options->set('isJavascriptEnabled',false); $options->set('isPhpEnabled',false); $options->setTempDir(sys_get_temp_dir()); $options->setFontCache(sys_get_temp_dir());
    $pdf=new \Dompdf\Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4'); $pdf->render();
    $pdf->getCanvas()->page_text(36,815,'LRDMS | Page {PAGE_NUM} of {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('Helvetica'),8);
    return $pdf->output();
}
