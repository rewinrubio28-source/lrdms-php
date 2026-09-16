<?php
/**
 * Notification Bell — AJAX endpoint for the logged-in app.
 *
 * Internal, session-authenticated (NOT the X-API-Key external
 * endpoints in this folder — those are for other systems; this one is
 * called by the browser of a logged-in LRDMS user, same pattern as
 * api/version_lookup.php).
 *
 * GET  /api/notifications.php?action=list        → recent notifications + unread count
 * GET  /api/notifications.php?action=count        → unread count only (cheap polling)
 * POST /api/notifications.php  action=mark_read    id=<int>
 * POST /api/notifications.php  action=mark_all_read
 *
 * POST requests are CSRF-checked the same way every other POST in
 * this app is (see includes/auth.php validate_csrf — token can come
 * from the body or the X-CSRF-Token header).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json');

require_login();
$user = current_user();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'count') {
        echo json_encode(['unread' => get_unread_notification_count($user['id'])]);
        exit;
    }

    // Default: list
    $items = get_recent_notifications($user['id'], (int)($_GET['limit'] ?? 10));
    echo json_encode([
        'unread' => get_unread_notification_count($user['id']),
        'notifications' => array_map(function ($n) {
            return [
                'id'          => (int)$n['id'],
                'type'        => $n['type'],
                'document_id' => $n['document_id'] !== null ? (int)$n['document_id'] : null,
                'doc_number'  => $n['doc_number'],
                'doc_title'   => $n['doc_title'],
                'message'     => $n['message'],
                'is_read'     => (bool)$n['is_read'],
                'created_at'  => $n['created_at'],
            ];
        }, $items),
    ]);
    exit;
}

if ($method === 'POST') {
    if (!validate_csrf()) {
        http_response_code(403);
        echo json_encode(['error' => 'Security token expired. Please refresh the page.']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) mark_notification_read($id, $user['id']);
        echo json_encode(['ok' => true, 'unread' => get_unread_notification_count($user['id'])]);
        exit;
    }

    if ($action === 'mark_all_read') {
        mark_all_notifications_read($user['id']);
        echo json_encode(['ok' => true, 'unread' => 0]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['error' => 'Unknown action.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
