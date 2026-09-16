<?php
/**
 * Document status workflow — enforces valid transitions.
 *
 * Simplified lifecycle: LRDMS is the system of record for FINALIZED
 * documents only (see README's integration boundary). Every document that
 * enters this system — via the API push in api/upload_document.php, or the
 * (removed) old manual filing form — starts life already Enacted. The full
 * drafting-to-first-reading workflow (Draft → Submitted → Under Review) is
 * owned by System 1 upstream, not by LRDMS, so there is no in-app path left
 * that can ever create or move a document through those states.
 *
 * Transition rules (who can do what):
 *   Enacted     → Withdrawn                          (formally withdraw/repeal an
 *                                                       on-file record; Records
 *                                                       Officer / Administrator)
 *   Amended     → (terminal; only set by version control, never this dropdown)
 *   Superseded  → (terminal; reserved for a future consolidation feature)
 *   Withdrawn   → (terminal)
 *
 * 'Draft', 'Submitted', and 'Under Review' remain valid ENUM values in the
 * database (so any pre-existing/legacy or manually-inserted row in one of
 * those states doesn't break), but they are intentionally absent from the
 * transition map below — a document already in one of those states has no
 * outgoing transition here, since there's no LRDMS-owned workflow to move
 * it forward. That's correct, not a bug: those statuses aren't LRDMS's to
 * progress.
 */

/**
 * Returns an associative array: current_status => [allowed_next_statuses].
 */
function valid_document_transitions() {
    return [
        'Draft'        => [],
        'Submitted'    => [],
        'Under Review' => [],
        'Enacted'      => ['Withdrawn'],
        'Amended'      => [],
        'Superseded'   => [],
        'Withdrawn'    => [],
    ];
}

/**
 * Whether the transition from $from to $to is allowed by the workflow.
 */
function can_transition_status($from, $to) {
    $transitions = valid_document_transitions();
    return isset($transitions[$from]) && in_array($to, $transitions[$from], true);
}

/**
 * Returns the list of statuses a document in $currentStatus can move to.
 * An empty array means the document is in a terminal state.
 */
function valid_next_statuses($currentStatus) {
    $transitions = valid_document_transitions();
    return $transitions[$currentStatus] ?? [];
}

/* ============================================================
   Email notifications on status changes
   ============================================================ */

/**
 * Send email notification(s) when a document's status changes.
 * Notifies the document owner and, for certain transitions, the
 * relevant committee secretary or Records Officer.
 *
 * Calls are wrapped in a try/catch so a mail failure never blocks
 * the status change itself.
 */
function notify_status_change($doc, $oldStatus, $newStatus, $changedByUser) {
    require_once __DIR__ . '/../config/email.php';
    require_once __DIR__ . '/notifications.php';

    $pdo = get_db();
    $recipients = [];

    // 1. Always notify the document owner (if they have an email and
    //    aren't the one who made the change).
    if (!empty($doc['owner_email']) && (int)($doc['owner_id'] ?? 0) !== (int)($changedByUser['id'] ?? 0)) {
        $recipients[$doc['owner_email']] = $doc['owner_name'] ?? 'Document Owner';
    }

    // 2. When entering "Under Review", also notify the committee secretary
    //    (the user assigned to the document's committee with the
    //    Committee Secretary role). This is the moment someone is, in
    //    effect, requesting that secretary's review — so it's also
    //    where the in-app "review request" bell notification fires
    //    (see includes/notifications.php).
    if ($newStatus === 'Under Review' && !empty($doc['committee_id'])) {
        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.full_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.committee_id = ? AND r.name = ? AND u.is_active = 1 AND u.email IS NOT NULL'
        );
        $stmt->execute([(int)$doc['committee_id'], 'Committee Secretary']);
        $sec = $stmt->fetch();
        if ($sec && !isset($recipients[$sec['email']])) {
            $recipients[$sec['email']] = $sec['full_name'];
        }
        if ($sec) {
            create_notification(
                $sec['id'],
                'review_request',
                $doc['id'] ?? null,
                ($changedByUser['full_name'] ?? 'Someone') . ' is requesting your review for '
                    . ($doc['doc_number'] ?? 'a document') . ' — ' . ($doc['title'] ?? '')
            );
        }
    }

    // 3. When enacted, also notify Records Officers.
    if ($newStatus === 'Enacted') {
        $stmt = $pdo->prepare(
            'SELECT u.email, u.full_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE r.name = ? AND u.is_active = 1 AND u.email IS NOT NULL'
        );
        $stmt->execute(['Records Officer']);
        while ($ro = $stmt->fetch()) {
            if (!isset($recipients[$ro['email']])) {
                $recipients[$ro['email']] = $ro['full_name'];
            }
        }
    }

    if (empty($recipients)) return;

    $docNumber = htmlspecialchars($doc['doc_number'] ?? '—');
    $docTitle  = htmlspecialchars($doc['title'] ?? '—');
    $changedBy = htmlspecialchars($changedByUser['full_name'] ?? 'System');
    $statusColors = [
        'Draft' => '#6c757d', 'Submitted' => '#0dcaf0', 'Under Review' => '#ffc107',
        'Enacted' => '#198754', 'Amended' => '#0d6efd', 'Superseded' => '#6c757d', 'Withdrawn' => '#dc3545',
    ];
    $color = $statusColors[$newStatus] ?? '#6c757d';

    foreach ($recipients as $email => $name) {
        $body = '
        <html><head><style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #37517e; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .doc-info { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 16px 20px; margin: 16px 0; }
            .status-badge { display: inline-block; padding: 4px 14px; border-radius: 999px; color: #fff; font-weight: 600; font-size: 13px; background: ' . $color . '; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style></head><body>
            <div class="container">
                <div class="header"><h2>LRDMS Document Update</h2></div>
                <div class="content">
                    <p>Hello ' . htmlspecialchars($name) . ',</p>
                    <p>A document you are associated with has been updated:</p>
                    <div class="doc-info">
                        <p><strong>Document:</strong> ' . $docNumber . '</p>
                        <p><strong>Title:</strong> ' . $docTitle . '</p>
                        <p><strong>Status:</strong> <span class="status-badge">' . htmlspecialchars($newStatus) . '</span></p>
                        <p><strong>Changed by:</strong> ' . $changedBy . '</p>
                    </div>
                    <p><a href="' . BASE_URL . '/document.php?id=' . (int)$doc['id'] . '">View document →</a></p>
                </div>
                <div class="footer"><p>This is an automated message from LRDMS. Please do not reply.</p></div>
            </div>
        </body></html>';

        @send_email($email, "LRDMS: {$docNumber} — {$newStatus}", $body);
    }
}

/**
 * Send an alert to all active Records Officers when a document arrives
 * from an upstream system via api/upload_document.php.
 *
 * This is the "may nag-send sakin ng document" alert — it fires the moment
 * the push lands, before anyone has reviewed it. The document itself is
 * filed with is_public = 0 until a Records Officer verifies it (see the
 * "Documents Awaiting Verification" queue in encoding.php), so this email
 * is what tells them there's something waiting there.
 */
function notify_incoming_document($doc, $sourceSystem) {
    require_once __DIR__ . '/../config/email.php';
    require_once __DIR__ . '/notifications.php';

    // In-app bell notification — every active Records Officer, same
    // audience as the email alert below.
    notify_role_users(
        'Records Officer',
        'incoming_document',
        $doc['id'] ?? null,
        ($sourceSystem ?: 'An upstream system') . ' sent in ' . ($doc['doc_number'] ?? 'a document')
            . ' — ' . ($doc['title'] ?? '') . '. Awaiting verification.'
    );

    $pdo = get_db();
    $stmt = $pdo->prepare(
        'SELECT u.email, u.full_name FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE r.name = ? AND u.is_active = 1 AND u.email IS NOT NULL'
    );
    $stmt->execute(['Records Officer']);
    $recipients = [];
    while ($ro = $stmt->fetch()) {
        $recipients[$ro['email']] = $ro['full_name'];
    }
    if (empty($recipients)) return;

    $docNumber = htmlspecialchars($doc['doc_number'] ?? '—');
    $docTitle  = htmlspecialchars($doc['title'] ?? '—');
    $source    = htmlspecialchars($sourceSystem ?: 'an upstream system');

    foreach ($recipients as $email => $name) {
        $body = '
        <html><head><style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #0B2E59; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .doc-info { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 16px 20px; margin: 16px 0; }
            .status-badge { display: inline-block; padding: 4px 14px; border-radius: 999px; color: #fff; font-weight: 600; font-size: 13px; background: #D4AF37; }
            .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
        </style></head><body>
            <div class="container">
                <div class="header"><h2>LRDMS — Document Awaiting Verification</h2></div>
                <div class="content">
                    <p>Hello ' . htmlspecialchars($name) . ',</p>
                    <p>' . $source . ' has sent a document to the Legislative Records repository. It is on file but not yet public — please verify its details before it is released.</p>
                    <div class="doc-info">
                        <p><strong>Document:</strong> ' . $docNumber . '</p>
                        <p><strong>Title:</strong> ' . $docTitle . '</p>
                        <p><strong>Source:</strong> ' . $source . '</p>
                        <p><span class="status-badge">Awaiting Verification</span></p>
                    </div>
                    <p><a href="' . BASE_URL . '/encoding.php#awaiting-verification">Review in Encoding →</a></p>
                </div>
                <div class="footer"><p>This is an automated message from LRDMS. Please do not reply.</p></div>
            </div>
        </body></html>';

        @send_email($email, "LRDMS: {$docNumber} awaiting verification", $body);
    }
}