<?php
function dashboard_filters(array $input): array {
    $defaults=['from'=>date('Y-m-01',strtotime('first day of -11 months')),'to'=>date('Y-m-d'),'type'=>'All','status'=>'All'];
    $out=[];
    foreach ($defaults as $key=>$default) {
        $value=$input[$key]??$default;
        if (!is_string($value)) throw new InvalidArgumentException('Invalid dashboard filter: '.$key);
        $out[$key]=trim($value);
    }
    foreach (['from','to'] as $key) {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$out[$key]);
        if (!$date || $date->format('Y-m-d')!==$out[$key] || (int)$date->format('Y')<1900 || (int)$date->format('Y')>2100) throw new InvalidArgumentException('Choose valid start and end dates.');
    }
    $start=new DateTimeImmutable($out['from']); $end=new DateTimeImmutable($out['to']);
    $months=((int)$end->format('Y')-(int)$start->format('Y'))*12+(int)$end->format('m')-(int)$start->format('m');
    if ($start>$end || $months>=60) throw new InvalidArgumentException('Choose an ordered date range covering at most 60 calendar months.');
    if (!in_array($out['type'],['All','Ordinance','Resolution','Committee Report','Minutes','Other'],true)) throw new InvalidArgumentException('Invalid document type.');
    if (!in_array($out['status'],['All','Draft','Submitted','Under Review','Enacted','Amended','Rejected','Superseded','Withdrawn'],true)) throw new InvalidArgumentException('Invalid document status.');
    return $out;
}
function dashboard_date_scope(array $filters, string $column='d.created_at'): array {
    return ["$column >= ? AND $column < ?",[$filters['from'].' 00:00:00',(new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00']];
}
function dashboard_record_filter_scope(array $filters): array {
    [$clause,$params]=dashboard_date_scope($filters);
    foreach (['type'=>'d.doc_type','status'=>'d.status'] as $filter=>$column) {
        if ($filters[$filter]!=='All') { $clause.=" AND $column=?"; $params[]=$filters[$filter]; }
    }
    return [$clause,$params];
}
function dashboard_record_scope(array $user, array $filters): array {
    [$visibility,$params]=document_visibility_clause($user);
    [$filter,$filterParams]=dashboard_record_filter_scope($filters);
    return ["($visibility) AND ($filter)",array_merge($params,$filterParams)];
}
function dashboard_months(array $filters, array $rows): array {
    $counts=array_column($rows,'n','month'); $out=[];
    $end=new DateTimeImmutable(substr($filters['to'],0,7).'-01');
    for ($date=new DateTimeImmutable(substr($filters['from'],0,7).'-01');$date<=$end;$date=$date->modify('+1 month')) {
        $month=$date->format('Y-m'); $out[]=['month'=>$month,'n'=>(int)($counts[$month]??0)];
    }
    return $out;
}
function dashboard_drill_url(array $filters, array $overrides=[]): string {
    return 'dashboard_records.php?'.http_build_query(array_merge($filters,$overrides));
}
