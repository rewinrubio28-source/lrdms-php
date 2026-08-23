<?php
/**
 * Document status workflow — enforces valid transitions.
 *
 * A legislative document moves through a defined lifecycle. Not every
 * status can jump to every other status. This module encodes those rules
 * so the UI, encoding form, and admin status-change form all share one
 * source of truth.
 *
 * Transition rules (who can do what):
 *   Draft       → Submitted, Withdrawn               (owner / staff)
 *   Submitted   → Under Review, Draft                (secretary sends back)
 *   Under Review → Enacted, Submitted, Withdrawn     (secretary approves)
 *   Enacted     → (none directly; amend creates a new version)
 *   Amended     → (terminal)
 *   Superseded  → (terminal)
 *   Withdrawn   → Draft                              (resubmit)
 *
 * "Amended" and "Superseded" are terminal — they're only set by the
 * version control system (document.php amend, version.php rollback), never
 * by the status-change dropdown.
 */

/**
 * Returns an associative array: current_status => [allowed_next_statuses].
 */
function valid_document_transitions() {
    return [
        'Draft'        => ['Submitted', 'Withdrawn'],
        'Submitted'    => ['Under Review', 'Draft'],
        'Under Review' => ['Enacted', 'Submitted', 'Withdrawn'],
        'Enacted'      => [],
        'Amended'      => [],
        'Superseded'   => [],
        'Withdrawn'    => ['Draft'],
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

    $pdo = get_db();
    $recipients = [];

    // 1. Always notify the document owner (if they have an email and
    //    aren't the one who made the change).
    if (!empty($doc['owner_email']) && (int)($doc['owner_id'] ?? 0) !== (int)($changedByUser['id'] ?? 0)) {
        $recipients[$doc['owner_email']] = $doc['owner_name'] ?? 'Document Owner';
    }

    // 2. When entering "Under Review", also notify the committee secretary
    //    (the user assigned to the document's committee with the
    //    Committee Secretary role).
    if ($newStatus === 'Under Review' && !empty($doc['committee_id'])) {
        $stmt = $pdo->prepare(
            'SELECT u.email, u.full_name FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.committee_id = ? AND r.name = ? AND u.is_active = 1 AND u.email IS NOT NULL'
        );
        $stmt->execute([(int)$doc['committee_id'], 'Committee Secretary']);
        $sec = $stmt->fetch();
        if ($sec && !isset($recipients[$sec['email']])) {
            $recipients[$sec['email']] = $sec['full_name'];
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
