<?php
require_once __DIR__.'/rbac.php';
require_once __DIR__.'/dashboard_filters.php';
require_once __DIR__.'/csv_export.php';

const RECORD_REPORT_COLUMNS = ['doc_number'=>'Document number','title'=>'Title','doc_type'=>'Type','status'=>'Current status','source_system'=>'Source','classification'=>'Classification','created_at'=>'Created','registered_at'=>'Registered'];
const RECORD_REPORT_LIMIT = 1000;

function record_report_options(array $input): array {
    $options=dashboard_filters($input);
    foreach (['title'=>'Records report','q'=>'','group'=>'none','sort'=>'created_at','direction'=>'desc'] as $key=>$default) {
        if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Invalid report option: '.$key);
        $options[$key]=trim($input[$key]??$default);
    }
    if ($options['title']==='' || mb_strlen($options['title'])>120 || mb_strlen($options['q'])>200) throw new InvalidArgumentException('Use a report title up to 120 characters and search text up to 200 characters.');
    foreach (['title','q'] as $key) if (!mb_check_encoding($options[$key],'UTF-8') || preg_match('/[\x00-\x1F]/',$options[$key])) throw new InvalidArgumentException('Use valid plain text for report titles and search.');
    if (!in_array($options['group'],['none','doc_type','status','source_system','month'],true)) throw new InvalidArgumentException('Invalid report grouping.');
    if (!array_key_exists($options['sort'],RECORD_REPORT_COLUMNS) || !in_array($options['direction'],['asc','desc'],true)) throw new InvalidArgumentException('Invalid report sort.');
    if ($options['group']==='none' && isset($input['columns_present']) && !isset($input['columns'])) throw new InvalidArgumentException('Select at least one report column.');
    $columns=$input['columns']??['doc_number','title','doc_type','status','created_at'];
    if (!is_array($columns) || !$columns || count($columns)>8) throw new InvalidArgumentException('Select between one and eight columns.');
    foreach ($columns as $column) if (!is_string($column) || !array_key_exists($column,RECORD_REPORT_COLUMNS)) throw new InvalidArgumentException('Invalid report column.');
    $options['columns']=array_values(array_unique($columns));
    return $options;
}

function record_report_allowed(array $user, string $action='view'): bool {
    if ($action==='export') return _role_has_permission($user['role_id'],'repository','download');
    if ($action==='print') return _role_has_permission($user['role_id'],'repository','print_record');
    foreach (['view_all','view_own','view_public','view_committee','view_memberships','view_office','view_division'] as $scope) if (_role_has_permission($user['role_id'],'repository',$scope)) return true;
    return false;
}

function record_report_scope(array $user,array $options): array {
    [$where,$params]=dashboard_record_scope($user,$options);
    $where.=' AND d.verified_at IS NOT NULL';
    if ($options['q']!=='') { $where.=' AND (d.doc_number LIKE ? OR d.title LIKE ?)'; array_push($params,'%'.$options['q'].'%','%'.$options['q'].'%'); }
    return [$where,$params];
}

function record_report_build(PDO $pdo,array $user,array $input): array {
    if (!record_report_allowed($user)) throw new RuntimeException('Repository access is required.');
    $options=record_report_options($input);
    [$where,$params]=record_report_scope($user,$options);
    $columns=implode(',',array_map(static fn($column)=>'d.'.$column,array_keys(RECORD_REPORT_COLUMNS)));
    $stmt=$pdo->prepare('SELECT d.id,'.$columns.' FROM documents d WHERE '.$where.' ORDER BY d.'.$options['sort'].' '.strtoupper($options['direction']).',d.id '.strtoupper($options['direction']).' LIMIT '.(RECORD_REPORT_LIMIT+1));
    $stmt->execute($params); $records=$stmt->fetchAll();
    if (count($records)>RECORD_REPORT_LIMIT) throw new InvalidArgumentException('More than 1,000 records match. Narrow the date range or filters; reports are never silently truncated.');
    $headers=array_map(static fn($c)=>RECORD_REPORT_COLUMNS[$c],$options['columns']); $rows=[];
    if ($options['group']==='none') {
        foreach ($records as $record) $rows[]=array_map(static fn($c)=>(string)($record[$c]??''),$options['columns']);
    } else {
        $headers=[$options['group']==='month'?'Creation month':RECORD_REPORT_COLUMNS[$options['group']],'Records']; $counts=[];
        foreach ($records as $record) { $value=$options['group']==='month'?substr($record['created_at'],0,7):($record[$options['group']]?:'Not specified'); $counts[$value]=($counts[$value]??0)+1; }
        if ($options['direction']==='asc') ksort($counts,SORT_NATURAL|SORT_FLAG_CASE); else krsort($counts,SORT_NATURAL|SORT_FLAG_CASE);
        foreach ($counts as $label=>$count) $rows[]=[(string)$label,$count];
    }
    return ['options'=>$options,'generated_at'=>date('Y-m-d H:i:s'),'prepared_for'=>$user['full_name']??$user['username'],'ids'=>array_map('intval',array_column($records,'id')),'count'=>count($records),'headers'=>$headers,'rows'=>$rows];
}

/** Stored reports remain private and are revoked when any included record is no longer visible. */
function record_report_snapshot_allowed(PDO $pdo,array $user,array $snapshot): bool {
    if (!record_report_allowed($user) || !record_report_allowed($user,'export')) return false;
    $ids=$snapshot['ids']; if (!$ids) return true;
    [$scope,$params]=document_visibility_clause($user);
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM documents d WHERE ('.$scope.') AND d.verified_at IS NOT NULL AND d.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $stmt->execute(array_merge($params,$ids));
    return (int)$stmt->fetchColumn()===count($ids);
}

function record_report_escape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

function record_report_html(array $report,bool $print=false): string {
    $o=$report['options']; $logo=__DIR__.'/../assets/img/manila logo.png';
    $image=is_file($logo)?'<img class="seal" alt="City of Manila seal" src="data:image/png;base64,'.base64_encode(file_get_contents($logo)).'">':'';
    $html='<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.record_report_escape($o['title']).'</title><style>'
        .'@page{size:A4 landscape;margin:18mm 14mm 20mm}body{font-family:DejaVu Sans,Arial,sans-serif;color:#19334e;font-size:10px;margin:0}.seal{width:100px;height:54px;float:left;margin-right:12px}header{min-height:60px;border-bottom:2px solid #234b75;margin-bottom:12px;padding-bottom:8px}h1{font-size:19px;margin:5px 0}h2{font-size:13px;margin:0}.meta{line-height:1.6;margin:10px 0}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #ccd6e2;padding:6px;word-wrap:break-word;overflow-wrap:anywhere;text-align:left;vertical-align:top}th{background:#e8eff6}thead{display:table-header-group}tr{page-break-inside:avoid}footer{margin-top:12px;border-top:1px solid #ccd6e2;padding-top:6px;font-size:9px}.toolbar{margin:16px 0}.toolbar button,.toolbar a{padding:8px;margin-right:8px}@media screen{body{padding:20px;max-width:1200px;margin:auto}.report-table{overflow-x:auto}}@media print{.toolbar{display:none}body{padding:0}.report-table{overflow:visible}footer{position:fixed;bottom:-12mm;left:0;right:0}}'
        .'</style></head><body>';
    if ($print) $html.='<div class="toolbar"><button type="button" onclick="window.print()">Print report</button><a href="reports.php">Back to Reports</a></div>';
    $html.='<header>'.$image.'<h2>MANILA CITY HALL · Records Registry</h2><h1>'.record_report_escape($o['title']).'</h1><div>Legislative Records &amp; Document Management System</div></header>';
    $html.='<div class="meta">Generated: '.record_report_escape($report['generated_at']).' (Asia/Manila) · Prepared for: '.record_report_escape($report['prepared_for']).'<br>Creation dates: '.record_report_escape($o['from'].' to '.$o['to']).' · Type: '.record_report_escape($o['type']).' · Status: '.record_report_escape($o['status']).'<br>Search: '.record_report_escape($o['q']?:'None').' · Grouping: '.record_report_escape($o['group']).' · Order: '.record_report_escape(($o['group']==='none'?$o['sort']:'Group label').' '.$o['direction']).' · Matching records: '.$report['count'].'<br>Current registered records visible to the requesting user; creation-date history is not a reconstruction of past record states.</div>';
    $footer='LRDMS · Manila City Hall · For authorized use. Generated '.record_report_escape($report['generated_at']);
    $html.='<div class="report-table"><table><thead><tr>'; foreach ($report['headers'] as $header) $html.='<th scope="col">'.record_report_escape($header).'</th>'; $html.='</tr></thead>';
    // Browser print engines repeat a table footer without overlapping data rows.
    if ($print) $html.='<tfoot style="display:table-footer-group"><tr><td colspan="'.count($report['headers']).'" style="font-size:9px;padding-top:10px">'.$footer.'</td></tr></tfoot>';
    $html.='<tbody>';
    foreach ($report['rows'] as $row) { $html.='<tr>'; foreach ($row as $cell) $html.='<td>'.record_report_escape($cell).'</td>'; $html.='</tr>'; }
    if (!$report['rows']) $html.='<tr><td colspan="'.count($report['headers']).'">No matching records.</td></tr>';
    return $html.'</tbody></table></div>'.($print?'':'<footer>'.$footer.'</footer>').'</body></html>';
}

function record_report_bytes(array $report,string $format): string {
    $rows=[[$report['options']['title']],['Manila City Hall — Records Registry'],['Generated (Asia/Manila)',$report['generated_at']],['Prepared for',$report['prepared_for']],['Creation dates',$report['options']['from'],$report['options']['to']],['Type',$report['options']['type']],['Status',$report['options']['status']],['Search',$report['options']['q']],['Grouping',$report['options']['group']],['Sort',$report['options']['group']==='none'?$report['options']['sort']:'Group label',$report['options']['direction']],['Matching records',$report['count']],$report['headers'],...$report['rows']];
    if ($format==='csv') {
        $out=fopen('php://temp','w+'); fwrite($out,"\xEF\xBB\xBF"); foreach ($rows as $row) fputcsv($out,array_map('export_csv_cell',$row),',','"',''); rewind($out); $bytes=stream_get_contents($out); fclose($out); return $bytes;
    }
    require_once __DIR__.'/../vendor/autoload.php';
    if ($format==='xlsx') return (string)\Shuchkin\SimpleXLSXGen::fromArray(array_map(static fn($r)=>array_map(static fn($v)=>is_string($v)?"\0".$v:$v,$r),$rows),'Records report');
    if ($format!=='pdf') throw new InvalidArgumentException('Choose PDF, Excel or CSV.');
    $options=new \Dompdf\Options(); $options->set('isRemoteEnabled',false); $options->set('isJavascriptEnabled',false); $options->set('isPhpEnabled',false); $options->set('defaultMediaType','print'); $options->setTempDir(sys_get_temp_dir()); $options->setFontCache(sys_get_temp_dir());
    $pdf=new \Dompdf\Dompdf($options); $pdf->loadHtml(record_report_html($report),'UTF-8'); $pdf->setPaper('A4','landscape'); $pdf->render();
    $pdf->getCanvas()->page_text(705,568,'Page {PAGE_NUM} of {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('Helvetica'),8);
    return $pdf->output();
}
