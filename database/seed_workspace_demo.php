<?php
// Additive, local-only demo dataset. Never resets or updates existing documents.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
if (!in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true)) throw new RuntimeException('Local database only.');
$pdo = get_db();
$owner = $pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='Records Officer' AND u.is_active=1 ORDER BY u.id LIMIT 1")->fetchColumn();
if (!$owner) throw new RuntimeException('An active Records Officer is required.');
$committee = $pdo->query('SELECT id FROM committees ORDER BY id LIMIT 1')->fetchColumn() ?: null;
$prefix = 'DEMO-WS-';
$existing = $pdo->query("SELECT COUNT(*) FROM documents WHERE doc_number LIKE 'DEMO-WS-%'")->fetchColumn();
if ($existing) { echo "Demo dataset already exists ($existing records); no changes made.\n"; exit; }

function workspace_demo_pdf(array $lines): string {
    $stream = "BT /F1 11 Tf 40 790 Td 18 TL\n";
    foreach ($lines as $line) foreach (explode("\n", wordwrap($line, 80, "\n", true)) as $part) {
        $stream .= '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $part) . ") Tj T*\n";
    }
    $stream .= 'ET';
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "\nendstream"];
    $pdf = "%PDF-1.4\n"; $offsets = [];
    foreach ($objects as $i => $object) { $offsets[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n"; }
    $xref = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

// title, type, legislative status, records status, classification, council term
$samples = [
 ['E-trike Registration - Original','Ordinance','Amended','Registered','PUBLIC',13],
 ['E-trike Registration - Revised Coverage','Ordinance','Amended','Registered','PUBLIC',13],
 ['E-trike Registration - QR Permit','Ordinance','Enacted','Active','PUBLIC',13],
 ['Reading Centers - Original','Resolution','Amended','Registered','INTERNAL',null],
 ['Reading Centers - Revised Locations','Resolution','Amended','Registered','INTERNAL',null],
 ['Reading Centers - Expanded Program','Resolution','Enacted','Active','INTERNAL',null],
 ['Market Sanitation Standards','Ordinance','Enacted','Archive Eligible','PUBLIC',12],
 ['Traffic Route Reference','Ordinance','Enacted','Archive Preparation','RESTRICTED',11],
 ['Historical Street Names','Ordinance','Enacted','Transferred to Archive System','PUBLIC',10],
 ['Rejected Facilities Proposal','Resolution','Rejected','Registered','CONFIDENTIAL',null],
 ['Rejected Procurement Proposal','Resolution','Rejected','Registered','RESTRICTED',null],
 ['Health Committee Report','Committee Report','Enacted','Registered','INTERNAL',null],
 ['Community Garden Submission','Ordinance','Submitted','Submitted','INTERNAL',13],
 ['Bicycle Parking Review','Ordinance','Under Review','Pending Validation','INTERNAL',13],
 ['Scholarship Supporting Papers','Resolution','Submitted','Returned for Correction','RESTRICTED',null],
 ['Flood Response Reference','Resolution','Under Review','Validated','INTERNAL',null],
 ['Duplicate Market Submission','Ordinance','Submitted','Duplicate','INTERNAL',12],
 ['Unverified Office Submission','Other','Draft','Unauthorized Submission','CONFIDENTIAL',null],
 ['Meeting Minutes - Pending Intake','Minutes','Submitted','Pending Validation','INTERNAL',null],
 ['Public Park Lighting','Ordinance','Draft','Pending Validation','PUBLIC',null],
];
$directory = __DIR__ . '/../uploads/demo-workspace';
if (!is_dir($directory) && !mkdir($directory, 0775, true)) throw new RuntimeException('Cannot create demo file directory.');
$ids = []; $manifest = [];
$pdo->beginTransaction();
try {
 foreach ($samples as $index => [$title,$type,$status,$recordsStatus,$classification,$term]) {
    $n = $index + 1; $number = $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    $registered = $n <= 12;
    $received = date('Y-m-d H:i:s', strtotime('-' . (65 - $n * 2) . ' days'));
    $processed = $registered ? date('Y-m-d H:i:s', strtotime($received . ' +1 day')) : null;
    $due = !$registered ? date('Y-m-d H:i:s', strtotime($received . ' +15 days')) : null;
    if (in_array($n, [16,19,20], true)) $due = date('Y-m-d H:i:s', strtotime('+5 days'));
    $text = "DEMO ONLY - NOT AN OFFICIAL RECORD\nSection 1. Purpose: $title.\nSection 2. This sample illustrates records management.\nSection 3. No legal effect is implied.";
    if ($n <= 3) {
        $text = "DEMO ONLY - NOT AN OFFICIAL RECORD\nSection 1. Purpose: Registration of e-trikes within the City of Manila.\n";
        $text .= $n < 3 ? 'Section 2. All operators must register with the City Council Secretariat.' : 'Section 2. All operators must register and secure a QR-coded permit.';
        $text .= "\n" . ($n === 1 ? 'Section 3. Routes are limited to designated barangay routes.' : 'Section 3. Coverage includes designated city routes and transport hubs.');
        if ($n === 3) $text .= "\nSection 4. Implementation: operator orientation precedes rollout.\nSection 5. Sample penalties are for demonstration only.";
    } elseif ($n <= 6) {
        $text = "DEMO ONLY - NOT AN OFFICIAL RECORD\nSection 1. Establish community reading centers.\nSection 2. Initial locations: " . ($n === 4 ? 'District A.' : 'Districts A and B.');
        if ($n === 6) $text .= "\nSection 3. Include accessible reading materials and weekend programs.";
    }
    $file = 'uploads/demo-workspace/' . $number . '.pdf';
    if (file_put_contents(__DIR__ . '/../' . $file, workspace_demo_pdf([$number, 'DEMO - ' . $title, '', ...explode("\n", $text)])) === false) throw new RuntimeException('Could not write sample PDF.');
    $source = $type === 'Minutes' ? 'System 2 - Session (DEMO)' : ($type === 'Committee Report' ? 'System 4 - Committee (DEMO)' : 'System 1 - Lifecycle (DEMO)');
    $data = ['doc_number'=>$number,'title'=>'DEMO - '.$title,'doc_type'=>$type,'sponsor'=>'Councilor Sample '.(($n % 3)+1),'committee_id'=>$committee,'owner_id'=>$owner,'status'=>$status,'is_public'=>(int)($registered && $classification==='PUBLIC'),'verified_at'=>$processed,'source_system'=>$source,'enactment_date'=>$registered ? substr($processed,0,10) : null,'file_path'=>$file,'ocr_text'=>$n===19 ? null : $text,'body'=>null,'created_at'=>$received,'updated_at'=>$processed ?: $received,'records_status'=>$recordsStatus,'classification'=>$classification,'originating_office'=>$n===20 ? null : 'Demo Legislative Office','originating_division'=>$n===20 ? null : 'Demo Records Division','submitter_position'=>'Demo Records Liaison','responsible_custodian'=>$n===20 ? null : 'Demo Records Custodian','related_legislative_item'=>'DEMO-ITEM-'.str_pad((string)$n,3,'0',STR_PAD_LEFT),'source_record_id'=>'DEMO-SOURCE-'.$n,'source_status'=>$status,'source_status_date'=>$received,'status_last_synced'=>$received,'received_at'=>$received,'registered_at'=>$processed,'pending_since'=>$registered ? null : $received,'follow_up_due_at'=>$due,'validation_note'=>$n===15 ? 'DEMO: missing supporting signature page.' : null,'council_term'=>$term];
    $pdo->prepare('INSERT INTO documents (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_fill(0,count($data),'?')).')')->execute(array_values($data));
    $id=(int)$pdo->lastInsertId(); $ids[$n]=$id;
    $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) VALUES (?,?,?,0)')->execute([$id,$file,$number.'.pdf']);
    if ($n===3) {
        $extra='uploads/demo-workspace/'.$number.'-annex.pdf';
        if (file_put_contents(__DIR__.'/../'.$extra,workspace_demo_pdf(['DEMO ANNEX - NOT OFFICIAL','Sample permit checklist','1. Registration form','2. Orientation attendance']))===false) throw new RuntimeException('Could not write annex.');
        $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) VALUES (?,?,?,1)')->execute([$id,$extra,'Demo permit checklist.pdf']);
    }
    $event=$registered ? 'Registered' : (in_array($recordsStatus,['Returned for Correction','Validated','Duplicate','Unauthorized Submission'],true) ? $recordsStatus : null);
    if ($registered) $pdo->prepare('INSERT INTO record_validation_history (document_id,actor_id,action,note,created_at) VALUES (?,?,?,?,?)')->execute([$id,$owner,'Validated','DEMO: metadata checked against the sample source record.',$received]);
    if ($event) $pdo->prepare('INSERT INTO record_validation_history (document_id,actor_id,action,note,created_at) VALUES (?,?,?,?,?)')->execute([$id,$owner,$event,$data['validation_note'] ?: 'DEMO: '.$event.' record.',$processed ?: $received]);
    $pdo->prepare('INSERT INTO integration_receipts (source_system,external_reference_id,received_at,received_by,processing_status,lrdms_record_id) VALUES (?,?,?,?,?,?)')->execute([$source,$data['source_record_id'],$received,$owner,$event ?: 'Pending Validation',$id]);
    if (!$registered && in_array($n,[14,15,16,19,20],true)) $pdo->prepare('INSERT INTO record_followups (document_id,recorded_by,contact_person,contact_method,note,next_due_at,created_at) VALUES (?,?,?,?,?,?,?)')->execute([$id,$owner,'Demo Records Liaison','Official letter','DEMO: follow-up logged for pending intake. No message was sent.',$due,date('Y-m-d H:i:s',strtotime($received.' +5 days'))]);
    if ($registered) $pdo->prepare('INSERT INTO document_change_notes (document_id,note,created_by,created_at) VALUES (?,?,?,?)')->execute([$id,'DEMO: '.$title.'. Received sample content preserved for comparison.',$owner,$processed]);
    log_action('encoding','demo_record_created',$number.' - demonstration data, not official');
    $manifest[]=['number'=>$number,'id'=>$id,'type'=>$type,'status'=>$status,'records_status'=>$recordsStatus,'classification'=>$classification,'council_term'=>$term,'url'=>'document.php?id='.$id];
 }
 foreach ([[1,2,3],[4,5,6]] as $chain) foreach ($chain as $i=>$n) $pdo->prepare('UPDATE documents SET previous_version_id=?,next_version_id=? WHERE id=?')->execute([$i ? $ids[$chain[$i-1]] : null,$i<2 ? $ids[$chain[$i+1]] : null,$ids[$n]]);
 foreach ([[3,7,'amends'],[6,12,'related'],[9,8,'substitutes']] as [$from,$to,$relation]) $pdo->prepare('INSERT INTO document_relationships (document_id,related_id,relationship_type,created_by) VALUES (?,?,?,?)')->execute([$ids[$from],$ids[$to],$relation,$owner]);
 $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
echo json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\nCreated 20 demo records, 21 PDF attachments, two version chains.\n";
