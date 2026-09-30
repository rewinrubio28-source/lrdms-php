<?php
// Local CLI only. Adds demo intake records without resetting existing records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/audit.php';
if (!in_array(DB_HOST, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "Demo intake seeding is restricted to a local database.\n");
    exit(1);
}
$pdo = get_db();
$owner = $pdo->query("SELECT id FROM users WHERE username='system.integration'")->fetchColumn();
if (!$owner) { fwrite(STDERR, "Missing system.integration account. No records added.\n"); exit(1); }

function demo_intake_pdf(array $lines): string {
    $content = "BT /F1 13 Tf 50 780 Td 23 TL\n";
    foreach ($lines as $line) {
        $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $content .= '(' . $safe . ") Tj T*\n";
    }
    $content .= "ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . 'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    return $pdf . "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

$samples = [
    ['DEMO-ORD-2026-001', 'DEMO - Community Recycling Program', 'Ordinance', 'Councilor Demo A', 'System 1 - Lifecycle (DEMO)', 'Filed'],
    ['DEMO-RES-2026-002', 'DEMO - Support for Youth Reading Centers', 'Resolution', 'Councilor Demo B', 'System 1 - Lifecycle (DEMO)', 'For Agenda'],
    ['DEMO-CR-2026-003', 'DEMO - Public Market Sanitation Report', 'Committee Report', 'Committee on Health (DEMO)', 'System 4 - Committee (DEMO)', 'Committee Report'],
];
$directory = __DIR__ . '/../uploads/demo-intake';
if (!is_dir($directory) && !mkdir($directory, 0775, true)) throw new RuntimeException('Cannot create demo attachment directory.');
$created = 0;
foreach ($samples as [$number, $title, $type, $sponsor, $source, $sourceStatus]) {
    $exists = $pdo->prepare('SELECT id FROM documents WHERE doc_number=?');
    $exists->execute([$number]);
    if ($exists->fetchColumn()) { echo "Skipped existing $number\n"; continue; }
    $file = 'uploads/demo-intake/' . $number . '.pdf';
    $lines = ['DEMO / SAMPLE ONLY - NOT AN OFFICIAL RECORD', '', 'CITY OF MANILA', 'Legislative Records - Demonstration', '', $number, $title, '', 'Sponsor: ' . $sponsor, 'Source: ' . $source, 'Simulated source status: ' . $sourceStatus, '', 'This sample is provided to demonstrate records intake,', 'metadata editing, validation, and registration.', '', 'All names and document details here are sample data.', 'No official signatures, approval, or legal effect is implied.'];
    if (file_put_contents(__DIR__ . '/../' . $file, demo_intake_pdf($lines)) === false) throw new RuntimeException('Could not write demo PDF.');
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO documents (doc_number,title,doc_type,sponsor,owner_id,status,is_public,verified_at,source_system,source_record_id,source_status,source_status_date,status_last_synced,records_status,classification,received_at,pending_since,file_path) VALUES (?,?,?,?,?,'Submitted',0,NULL,?,?,?,NOW(),NOW(),'Pending Validation','INTERNAL',NOW(),NOW(),?)")
            ->execute([$number, $title, $type, $sponsor, $owner, $source, $number, $sourceStatus, $file]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO document_attachments (document_id,file_path,display_name,sort_order) VALUES (?,?,?,0)')->execute([$id, $file, $number . '.pdf']);
        $pdo->prepare("INSERT INTO integration_receipts (source_system,external_reference_id,received_by,processing_status,lrdms_record_id) VALUES (?,?,?,'Pending Validation',?)")->execute([$source, $number, $owner, $id]);
        log_action('encoding', 'demo_intake_created', $number . ' - sample record for reviewing incoming metadata');
        $pdo->commit();
        $created++;
        echo "Added $number (#$id) with demo PDF\n";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
echo "Created $created demo records.\n";
$queue = $pdo->query("SELECT COUNT(*) FROM documents WHERE verified_at IS NULL AND source_system <> 'Manual Encoding'")->fetchColumn();
echo "Documents awaiting verification: $queue\n";
