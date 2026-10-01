<?php
require_once __DIR__ . '/intake_record.php';

const DATASET_MAX_BYTES = 5242880;
const DATASET_MAX_ROWS = 1000;
const DATASET_FIELDS = ['doc_number','title','doc_type','source_system','source_record_id','status','source_status','classification','sponsor','enactment_date','council_term','originating_office','originating_division','submitter_position','responsible_custodian','related_legislative_item','source_status_date','body','ocr_text'];

function dataset_xml(string $xml): SimpleXMLElement {
    if (strlen($xml)>8388608 || substr_count($xml,'<')>100000) throw new InvalidArgumentException('Workbook XML exceeds the supported size or complexity.');
    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) throw new InvalidArgumentException('XML entities are not allowed.');
    $old = libxml_use_internal_errors(true);
    try {
        $result = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        if (!$result) throw new InvalidArgumentException('The workbook contains invalid XML.');
        return $result;
    } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
}

/** Narrow, bounded XLSX data reader: one sheet, literal values, no formulas. */
function dataset_xlsx(string $path): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Excel import is unavailable. Ask your administrator to enable the PHP zip extension.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new InvalidArgumentException('The file is not a valid XLSX workbook.');
    try {
        $expanded = 0; $names = [];
        if ($zip->numFiles > 200) throw new InvalidArgumentException('Workbook has too many components.');
        for ($i=0; $i<$zip->numFiles; $i++) {
            $s = $zip->statIndex($i); $name = $s['name']; $expanded += $s['size'];
            if ($expanded > 20971520 || isset($names[$name]) || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) throw new InvalidArgumentException('Workbook is too large or has invalid entries.');
            if (preg_match('~vbaProject|externalLinks|embeddings~i', $name)) throw new InvalidArgumentException('Macros, embedded objects and external links are not supported.');
            $names[$name] = true;
        }
        $xml = static function (string $name) use ($zip): SimpleXMLElement {
            $bytes = $zip->getFromName($name,8388609);
            if ($bytes === false) throw new InvalidArgumentException('Required workbook component is missing.');
            return dataset_xml($bytes);
        };
        $book = $xml('xl/workbook.xml');
        $sheets = $book->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (count($sheets) !== 1) throw new InvalidArgumentException('Use a workbook containing exactly one worksheet.');
        $relId = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
        $target = null;
        foreach ($xml('xl/_rels/workbook.xml.rels')->children() as $rel) {
            if ((string)$rel['TargetMode'] === 'External') throw new InvalidArgumentException('External workbook relationships are not supported.');
            if ((string)$rel['Id'] === $relId) $target = (string)$rel['Target'];
        }
        if (!$target || str_contains($target, '..') || str_contains($target, '\\')) throw new InvalidArgumentException('Invalid worksheet reference.');
        $sheet = $xml(str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target);
        $shared = [];
        if (isset($names['xl/sharedStrings.xml'])) {
            foreach ($xml('xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') as $item) {
                $shared[] = implode('', array_map('strval', $item->xpath('.//*[local-name()="t"]')));
            }
        }
        $rows = [];
        foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
            if (count($rows) > DATASET_MAX_ROWS) throw new InvalidArgumentException('Maximum 1,000 records per import.');
            $values = [];
            foreach ($row->children() as $cell) {
                if ($cell->getName() !== 'c') continue;
                if ($cell->xpath('./*[local-name()="f"]')) throw new InvalidArgumentException('Formula cells are not supported. Paste values before uploading.');
                if (!preg_match('/^([A-Z]{1,2})[1-9][0-9]*$/', (string)$cell['r'], $match)) throw new InvalidArgumentException('Invalid spreadsheet cell reference.');
                $column=0; foreach (str_split($match[1]) as $letter) $column=$column*26+ord($letter)-64;
                if ($column>count(DATASET_FIELDS) || isset($values[$column-1])) throw new InvalidArgumentException('Unexpected or duplicate spreadsheet column.');
                $raw=(string)$cell->v; $type=(string)$cell['t'];
                if ($type==='s' && isset($cell->v)) {
                    if (!ctype_digit($raw) || !array_key_exists((int)$raw,$shared)) throw new InvalidArgumentException('Invalid shared string reference.');
                    $raw=$shared[(int)$raw];
                } elseif ($type==='inlineStr') $raw=implode('',array_map('strval',$cell->xpath('.//*[local-name()="t"]')));
                elseif (!in_array($type,['','n','str','d','s'],true)) throw new InvalidArgumentException('Boolean and error cells are not supported.');
                $values[$column-1]=$raw;
            }
            if ($values) { $dense=array_fill(0,max(array_keys($values))+1,''); foreach ($values as $k=>$v) $dense[$k]=$v; $rows[]=$dense; }
        }
        return $rows;
    } finally { $zip->close(); }
}

function dataset_csv_syntax(string $bytes): void {
    // fgetcsv tolerates unterminated quotes. Reject malformed files first.
    $state='start'; $length=strlen($bytes);
    for ($i=0;$i<$length;$i++) {
        $char=$bytes[$i];
        if ($state==='quoted') {
            if ($char==='"') {
                if ($i+1<$length && $bytes[$i+1]==='"') $i++;
                else $state='closed';
            }
        } elseif ($char===',' || $char==="\r" || $char==="\n") $state='start';
        elseif ($state==='closed' || ($state==='plain' && $char==='"')) throw new InvalidArgumentException('Invalid CSV quoting. Save the file as comma-separated CSV.');
        elseif ($state==='start') $state=$char==='"'?'quoted':'plain';
    }
    if ($state==='quoted') throw new InvalidArgumentException('CSV contains an unclosed quoted field.');
}

function dataset_parse(string $path, string $name): array {
    $size = filesize($path);
    if (!$size || $size > DATASET_MAX_BYTES) throw new InvalidArgumentException('Choose a nonempty file up to 5 MB.');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext,['csv','json','xlsx'],true)) throw new InvalidArgumentException('Use CSV, JSON or Excel (.xlsx). Legacy .xls is not supported.');
    if ($ext==='xlsx') $table=dataset_xlsx($path);
    else {
        $bytes=file_get_contents($path);
        $bytes=preg_replace('/^\xEF\xBB\xBF/','',$bytes);
        if (!mb_check_encoding($bytes,'UTF-8') || str_contains($bytes,"\0")) throw new InvalidArgumentException('Use UTF-8 text without binary content.');
        if ($ext==='json') {
            try { $objects=json_decode($bytes,false,32,JSON_THROW_ON_ERROR); }
            catch (JsonException $e) { throw new InvalidArgumentException('Invalid JSON. Use an array of record objects.'); }
            if (!is_array($objects)) throw new InvalidArgumentException('JSON must contain an array of record objects.');
            $records=[];
            foreach ($objects as $object) {
                if (!$object instanceof stdClass) throw new InvalidArgumentException('Each JSON record must be an object.');
                $records[]=(array)$object;
            }
            return dataset_validate($records);
        }
        dataset_csv_syntax($bytes);
        $stream=fopen('php://temp','w+'); fwrite($stream,$bytes); rewind($stream); $table=[];
        try {
            while (($row=fgetcsv($stream,0,',','"',''))!==false) {
                if ($row===[null]) continue;
                $table[]=$row;
                if (count($table)>DATASET_MAX_ROWS+1) throw new InvalidArgumentException('Maximum 1,000 records per import.');
            }
        } finally { fclose($stream); }
    }
    $headers=array_shift($table);
    if (!$headers || count($headers)!==count(array_unique($headers))) throw new InvalidArgumentException('Provide a header row with unique field names.');
    if (array_diff($headers,DATASET_FIELDS) || array_diff(['doc_number','title','source_system'],$headers)) throw new InvalidArgumentException('Use the template headers, including doc_number, title and source_system.');
    $records=[];
    foreach ($table as $i=>$row) {
        if (count(array_filter($row,static fn($v)=>$v!=='' && $v!==null))===0) continue;
        if (count($row)>count($headers) || ($ext==='csv' && count($row)!==count($headers))) throw new InvalidArgumentException('Row '.($i+2).' does not match the header columns.');
        $records[]=array_combine($headers,array_pad($row,count($headers),''));
    }
    return dataset_validate($records);
}

function dataset_validate(array $records): array {
    if (!$records || count($records)>DATASET_MAX_ROWS) throw new InvalidArgumentException('Provide between 1 and 1,000 records.');
    $result=[];
    foreach ($records as $i=>$record) {
        try {
            if (!is_array($record) || array_diff(array_keys($record),DATASET_FIELDS)) throw new InvalidArgumentException('Unknown fields. Use the template; ownership, visibility and registration fields cannot be imported.');
            foreach ($record as $key=>$value) {
                if ($value===null || $value==='') { unset($record[$key]); continue; }
                if (!is_string($value) && !($key==='council_term' && is_int($value))) throw new InvalidArgumentException('Fields must be text (council_term may be an integer).');
                if (is_string($value) && (!mb_check_encoding($value,'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))) throw new InvalidArgumentException('Invalid text content.');
            }
            if (empty($record['source_system'])) throw new InvalidArgumentException('Identify the source system.');
            foreach (['body','ocr_text'] as $key) if (strlen($record[$key]??'')>60000) throw new InvalidArgumentException($key.' exceeds 60,000 bytes.');
            $values=intake_record_values($record);
            foreach (['enactment_date','source_status_date'] as $key) if ($values[$key] && ((int)substr($values[$key],0,4)<1000 || (int)substr($values[$key],0,4)>9999)) throw new InvalidArgumentException('Invalid date year.');
            $result[]=$values;
        } catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Record '.($i+1).': '.$e->getMessage()); }
    }
    return $result;
}

/** Caller holds the authenticated session; all records/receipts/audit commit together. */
function dataset_save(PDO $pdo, array $user, array $records): int {
    if (!_role_has_permission($user['role_id'],'encoding','create')) throw new RuntimeException('Document intake permission is required.');
    // Revalidate the server-side preview; do not accept posted rows or authority fields.
    $records=dataset_validate(array_map(static fn($r)=>array_intersect_key($r,array_flip(DATASET_FIELDS)),$records));
    $pdo->beginTransaction();
    try {
        $duplicate=$pdo->prepare('SELECT id FROM documents WHERE doc_number=? FOR UPDATE');
        $receipt=$pdo->prepare("INSERT INTO integration_receipts (source_system,external_reference_id,received_by,processing_status,lrdms_record_id) VALUES (?,?,?,'Pending Validation',?)");
        foreach ($records as $i=>$values) {
            $duplicate->execute([$values['doc_number']]);
            if ($duplicate->fetchColumn()) throw new InvalidArgumentException('Record '.($i+1).': document number already exists or is repeated in this batch. Nothing was imported.');
            $values+=['owner_id'=>(int)$user['id'],'is_public'=>0,'records_status'=>'Pending Validation','received_at'=>date('Y-m-d H:i:s'),'pending_since'=>date('Y-m-d H:i:s'),'status_last_synced'=>date('Y-m-d H:i:s')];
            $columns=array_keys($values);
            $stmt=$pdo->prepare('INSERT INTO documents (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
            $stmt->execute(array_values($values)); $id=$pdo->lastInsertId();
            $receipt->execute([$values['source_system'],$values['source_record_id']?:$values['doc_number'],$user['id'],$id]);
            $pdo->prepare('INSERT INTO audit_log (user_id,username_snapshot,module,action,detail,ip_address) VALUES (?,?,?,?,?,?)')->execute([$user['id'],$user['username'],'encoding','dataset_import',$values['doc_number'].' received from '.$values['source_system'],$_SERVER['REMOTE_ADDR']??null]);
        }
        $pdo->commit(); return count($records);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
