<?php
require_once __DIR__ . '/../config/database.php';

function ocr_job_files(PDO $pdo, array $doc): array {
    $stmt = $pdo->prepare('SELECT file_path FROM document_attachments WHERE document_id=? ORDER BY sort_order,id');
    $stmt->execute([$doc['id']]);
    $files = array_column($stmt->fetchAll(), 'file_path');
    if (!$files && !empty($doc['file_path'])) $files = [$doc['file_path']];
    return array_values(array_filter($files, static function ($path) {
        return in_array(strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION)), ['pdf','png','jpg','jpeg'], true);
    }));
}

function ocr_job_get(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM ocr_jobs WHERE document_id=?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// Caller enforces document visibility, action permission, and CSRF.
function ocr_job_enqueue(PDO $pdo, int $id, int $userId): void {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $doc = $stmt->fetch();
        if (!$doc) throw new RuntimeException('Document no longer exists.');
        $existing = ocr_job_get($pdo, $id);
        if ($existing && in_array($existing['status'], ['queued','running'], true)) {
            $pdo->commit();
            return;
        }
        $files = ocr_job_files($pdo, $doc);
        if (!$files) throw new RuntimeException('No PDF or image is attached to this record.');
        $stmt = $pdo->prepare("INSERT INTO ocr_jobs (document_id,requested_by,token,files_json,original_text_hash,file_count)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE requested_by=VALUES(requested_by),token=VALUES(token),
            files_json=VALUES(files_json),original_text_hash=VALUES(original_text_hash),file_count=VALUES(file_count),
            status='queued',file_index=0,pages_done=0,pages_total=NULL,error_message=NULL,attempts=0,
            created_at=NOW(),heartbeat_at=NOW(),finished_at=NULL");
        $stmt->execute([$id,$userId,bin2hex(random_bytes(16)),json_encode($files, JSON_THROW_ON_ERROR),hash('sha256',(string)$doc['ocr_text']),count($files)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ocr_job_claim(PDO $pdo): ?array {
    // Requests/downloads time out after 100 seconds; ten minutes means the
    // previous worker stopped. Fencing tokens prevent that worker saving later.
    $pdo->exec("UPDATE ocr_jobs SET status='failed',error_message='OCR worker stopped repeatedly. Please retry.',finished_at=NOW()
        WHERE status='running' AND heartbeat_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND attempts>=3");
    $pdo->beginTransaction();
    try {
        $job = $pdo->query("SELECT * FROM ocr_jobs WHERE status='queued' OR
            (status='running' AND heartbeat_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND attempts<3)
            ORDER BY created_at LIMIT 1 FOR UPDATE")->fetch();
        if (!$job) { $pdo->commit(); return null; }
        $job['token'] = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE ocr_jobs SET status='running',token=?,heartbeat_at=NOW(),attempts=attempts+1,
            file_index=0,pages_done=0,pages_total=NULL WHERE document_id=?")->execute([$job['token'],$job['document_id']]);
        $pdo->commit();
        return $job;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function ocr_job_execute(PDO $pdo, array $job, callable $scan): void {
    try {
        $parts = [];
        foreach (json_decode($job['files_json'], true, 512, JSON_THROW_ON_ERROR) as $index => $path) {
            $progress = static function ($done, $total) use ($pdo, $job, $index) {
                $pdo->prepare("UPDATE ocr_jobs SET file_index=?,pages_done=?,pages_total=?,heartbeat_at=NOW()
                    WHERE document_id=? AND token=? AND status='running'")
                    ->execute([$index+1,$done,$total,$job['document_id'],$job['token']]);
                $current = ocr_job_get($pdo, (int)$job['document_id']);
                if (!$current || $current['token'] !== $job['token'] || $current['status'] !== 'running') throw new RuntimeException('OCR job was replaced.');
            };
            $progress(0, null);
            $text = $scan($path, $progress);
            if (strncmp((string)$text, '[OCR', 4) === 0 || trim(str_replace('[PAGE BREAK]', '', (string)$text)) === '') {
                throw new RuntimeException('OCR could not read attachment ' . ($index+1) . '. Check the file and OCR service, then retry.');
            }
            $parts[] = $text;
        }
        $pdo->beginTransaction();
        // Same lock order as enqueue: document, then job.
        $stmt = $pdo->prepare('SELECT * FROM documents WHERE id=? FOR UPDATE');
        $stmt->execute([$job['document_id']]);
        $doc = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT * FROM ocr_jobs WHERE document_id=? FOR UPDATE');
        $stmt->execute([$job['document_id']]);
        $current = $stmt->fetch();
        if (!$current || $current['token'] !== $job['token'] || $current['status'] !== 'running') throw new RuntimeException('OCR job was replaced.');
        if (!$doc || ocr_job_files($pdo, $doc) !== json_decode($job['files_json'], true) ||
            hash('sha256', (string)$doc['ocr_text']) !== $job['original_text_hash']) {
            throw new RuntimeException('The document changed during scanning. Run OCR again for the updated record.');
        }
        $pdo->prepare('UPDATE documents SET ocr_text=? WHERE id=?')->execute([implode("\n\n[PAGE BREAK]\n\n", $parts),$doc['id']]);
        $pdo->prepare("UPDATE ocr_jobs SET status='completed',finished_at=NOW(),heartbeat_at=NOW() WHERE document_id=? AND token=?")
            ->execute([$doc['id'],$job['token']]);
        $pdo->prepare("INSERT INTO audit_log (user_id,username_snapshot,module,action,detail)
            VALUES (NULL,'system','repository','completed_background_ocr',?)")
            ->execute([$doc['doc_number'] . ': OCR requested by user ' . $job['requested_by']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Background OCR: ' . $e->getMessage());
        $message = $e instanceof PDOException ? 'Could not save OCR results. Please retry.' : $e->getMessage();
        $pdo->prepare("UPDATE ocr_jobs SET status='failed',error_message=?,finished_at=NOW() WHERE document_id=? AND token=? AND status='running'")
            ->execute([mb_substr($message,0,500),$job['document_id'],$job['token']]);
    }
}
