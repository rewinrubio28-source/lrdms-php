<?php
const PRIVACY_NOTICE_VERSION = '2026-10-01';
function privacy_available(PDO $pdo): bool {
    $stmt=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('privacy_events','privacy_requests')");
    return (int)$stmt->fetchColumn()===2;
}
function privacy_event(PDO $pdo, int $userId, string $purpose, string $decision): void {
    if (!in_array([$purpose,$decision], [['notice','acknowledged'],['profile_photo','granted'],['profile_photo','withdrawn']],true)) throw new InvalidArgumentException('Invalid privacy preference.');
    $pdo->prepare('INSERT INTO privacy_events (user_id,purpose,decision,notice_version) VALUES (?,?,?,?)')->execute([$userId,$purpose,$decision,PRIVACY_NOTICE_VERSION]);
}
function privacy_request_create(PDO $pdo, int $userId, string $type, string $details): void {
    $details=trim($details);
    if (!in_array($type,['Access','Correction','Deletion'],true) || $details==='' || mb_strlen($details)>2000) throw new InvalidArgumentException('Choose a request type and enter 1 to 2,000 characters describing your request.');
    $pdo->prepare('INSERT INTO privacy_requests (user_id,request_type,details) VALUES (?,?,?)')->execute([$userId,$type,$details]);
}
function privacy_request_review(PDO $pdo, array $actor, int $id, string $status, string $response): void {
    if (!_role_has_permission((int)($actor['role_id']??0),'access','manage_users')) throw new RuntimeException('You cannot review privacy requests.');
    $response=trim($response);
    if (!in_array($status,['Under Review','Completed','Declined'],true) || $response==='' || mb_strlen($response)>2000) throw new InvalidArgumentException('Provide a valid status and a response of 1 to 2,000 characters.');
    $stmt=$pdo->prepare("UPDATE privacy_requests SET status=?, response=?, reviewed_by=?, updated_at=NOW() WHERE id=? AND status IN ('Open','Under Review')");
    $stmt->execute([$status,$response,$actor['id'],$id]);
    if ($stmt->rowCount()!==1) throw new RuntimeException('Request was not found, already closed, or unchanged.');
}

function privacy_photo_save(PDO $pdo, int $userId, ?string $mime, ?string $data): void {
    $pdo->beginTransaction();
    try {
        if ($data === null) {
            $pdo->prepare('DELETE FROM user_profile_photos WHERE user_id=?')->execute([$userId]);
        } else {
            $pdo->prepare('INSERT INTO user_profile_photos (user_id,mime_type,image_data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mime_type=VALUES(mime_type),image_data=VALUES(image_data),updated_at=NOW()')->execute([$userId,$mime,$data]);
        }
        privacy_event($pdo,$userId,'profile_photo',$data===null?'withdrawn':'granted');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
