<?php
/**
 * In-app notification bell.
 *
 * Rides alongside the existing email alerts in includes/workflow.php —
 * it does not replace them. Two kinds of events create a row here:
 *
 *   'incoming_document' — a document pushed in from an upstream system
 *                         and awaiting verification. Created from
 *                         notify_incoming_document() for every active
 *                         Records Officer.
 *   'review_request'    — a document that needs this user's review or
 *                         action (currently: entering "Under Review",
 *                         which needs the committee secretary's
 *                         action). Created from notify_status_change().
 *
 * A failure here must never block the document action that triggered
 * it, so every write goes through create_notification(), which
 * swallows DB errors the same way the email helpers swallow mail
 * failures.
 */
require_once __DIR__ . '/../config/database.php';

/**
 * Insert a single notification row. Never throws — a notification
 * that fails to save is not worth breaking the document workflow for.
 */
function create_notification($userId, $type, $documentId, $message) {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, type, document_id, message) VALUES (?,?,?,?)'
        );
        $stmt->execute([(int)$userId, $type, $documentId !== null ? (int)$documentId : null, $message]);
    } catch (Throwable $e) {
        // Intentionally silent — same posture as email alert failures.
    }
}

/**
 * Create the same notification for every active user holding a given
 * role, optionally skipping one user (e.g. whoever just made the
 * change). Mirrors the recipient lookup already used for email alerts
 * in includes/workflow.php, so the bell and the inbox always agree on
 * "who gets told about this".
 */
function notify_role_users($roleName, $type, $documentId, $message, $excludeUserId = null) {
    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT u.id FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE r.name = ? AND u.is_active = 1'
    );
    $stmt->execute([$roleName]);
    foreach ($stmt->fetchAll() as $row) {
        if ($excludeUserId !== null && (int)$row['id'] === (int)$excludeUserId) continue;
        create_notification($row['id'], $type, $documentId, $message);
    }
}

/**
 * Count of unread notifications for the bell badge.
 */
function get_unread_notification_count($userId) {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([(int)$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Recent notifications for the bell dropdown, newest first.
 */
function get_recent_notifications($userId, $limit = 10) {
    $pdo = get_db();
    $limit = max(1, min(50, (int)$limit));
    $stmt = $pdo->prepare(
        "SELECT n.id, n.type, n.document_id, n.message, n.is_read, n.created_at,
                d.doc_number, d.title AS doc_title
         FROM notifications n
         LEFT JOIN documents d ON d.id = n.document_id
         WHERE n.user_id = ?
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT $limit"
    );
    $stmt->execute([(int)$userId]);
    return $stmt->fetchAll();
}

/**
 * Mark every notification tied to a document as read, for every user —
 * not just the one acting. Used when the underlying thing a notification
 * was about is resolved (e.g. an incoming document gets verified), so it
 * disappears from everyone's bell instead of staying "unread" for every
 * Records Officer except whoever happened to click it.
 */
function mark_notifications_read_for_document($documentId, $type = null) {
    $pdo = get_db();
    if ($type !== null) {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE document_id = ? AND type = ? AND is_read = 0');
        $stmt->execute([(int)$documentId, $type]);
    } else {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE document_id = ? AND is_read = 0');
        $stmt->execute([(int)$documentId]);
    }
}

/**
 * Mark one notification as read. Scoped to the owning user so one
 * account can never mark another account's notification.
 */
function mark_notification_read($notificationId, $userId) {
    $pdo = get_db();
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$notificationId, (int)$userId]);
}

/**
 * Mark every notification for this user as read (bell's "Mark all as
 * read").
 */
function mark_all_notifications_read($userId) {
    $pdo = get_db();
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([(int)$userId]);
}