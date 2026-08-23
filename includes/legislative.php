<?php
/**
 * Legislative Records helpers — Version Control upgrade.
 *
 * Two things live here, deliberately kept out of version.php itself so
 * that file stays readable:
 *
 *  1. RELATIONSHIP SYSTEM (Amends / Repeals / Substitutes / Related, and
 *     their inverses). Backed by the new `document_relationships` table
 *     (see database/migrate_legislative.php and sql/schema.sql). A
 *     relationship is recorded once, from whichever version was current
 *     at the time it was linked, and is shown on EVERY version in that
 *     document's chain — amending a document later doesn't detach its
 *     relationships.
 *
 *  2. VERSION BADGES — the CURRENT / ORIGINAL / AMENDED / SUPERSEDED
 *     labels shown next to each entry in the Legislative History /
 *     Versions view. Pure presentation over data version.php already
 *     loads (no new columns needed).
 *
 * Nothing here bypasses includes/rbac.php — relationship mutations are
 * gated by the existing 'version','amend' permission (the same one that
 * already governs creating a new version), not a new permission table
 * entry, per the "reuse existing permissions" guidance.
 */
require_once __DIR__ . '/rbac.php';

/** Forward relationship types stored in the DB, and their inverse label. */
function relationship_type_labels() {
    return [
        'amends'       => ['forward' => 'Amends',       'inverse' => 'Amended By'],
        'repeals'      => ['forward' => 'Repeals',       'inverse' => 'Repealed By'],
        'substitutes'  => ['forward' => 'Substitutes',   'inverse' => 'Substituted By'],
        'consolidates' => ['forward' => 'Consolidates',  'inverse' => 'Consolidated With'],
        'related'      => ['forward' => 'Related Legislation', 'inverse' => 'Related Legislation'],
    ];
}

/**
 * All relationships touching any version in this document's chain, in
 * both directions, joined to the related document's head (current)
 * metadata so links always land on the latest version.
 *
 * Returns an array keyed by display label (e.g. "Amends", "Amended By"),
 * each a list of ['doc' => <head document row>, 'created_at' => ...].
 */
function get_document_relationships($pdo, array $chainIds) {
    if (!$chainIds) return [];
    $labels = relationship_type_labels();
    $placeholders = implode(',', array_fill(0, count($chainIds), '?'));

    $sql = "SELECT r.*, 'forward' AS direction FROM document_relationships r
            WHERE r.document_id IN ($placeholders)
            UNION ALL
            SELECT r.*, 'reverse' AS direction FROM document_relationships r
            WHERE r.related_id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($chainIds, $chainIds));
    $rows = $stmt->fetchAll();

    $grouped = [];
    foreach ($rows as $row) {
        $otherId = $row['direction'] === 'forward' ? (int)$row['related_id'] : (int)$row['document_id'];
        if (in_array($otherId, array_map('intval', $chainIds), true)) continue; // relationship between two of this doc's own versions — skip

        $label = $row['direction'] === 'forward'
            ? $labels[$row['relationship_type']]['forward']
            : $labels[$row['relationship_type']]['inverse'];

        // Resolve to that document's current head (walk next_version_id forward)
        $head = _relationship_head($pdo, $otherId);
        if (!$head) continue;

        $grouped[$label][] = [
            'doc'        => $head,
            'created_at' => $row['created_at'],
            'rel_id'     => (int)$row['id'],
        ];
    }
    return $grouped;
}

function _relationship_head($pdo, $id) {
    $stmt = $pdo->prepare('SELECT d.*, u.full_name AS owner_name FROM documents d JOIN users u ON u.id = d.owner_id WHERE d.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return null;
    while ($row['next_version_id']) {
        $stmt->execute([$row['next_version_id']]);
        $next = $stmt->fetch();
        if (!$next) break;
        $row = $next;
    }
    return $row;
}

/** Records a relationship. $documentId is the "from" side (forward direction). */
function add_relationship($pdo, $documentId, $relatedId, $type, $userId) {
    if ((int)$documentId === (int)$relatedId) {
        return ['ok' => false, 'error' => 'A document cannot be related to itself.'];
    }
    if (!array_key_exists($type, relationship_type_labels())) {
        return ['ok' => false, 'error' => 'Unknown relationship type.'];
    }
    $chk = $pdo->prepare('SELECT id FROM documents WHERE id = ?');
    $chk->execute([$relatedId]);
    if (!$chk->fetchColumn()) {
        return ['ok' => false, 'error' => 'Target document not found.'];
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO document_relationships (document_id, related_id, relationship_type, created_by)
             VALUES (?,?,?,?)'
        );
        $stmt->execute([$documentId, $relatedId, $type, $userId]);
        return ['ok' => true];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Could not save relationship: ' . $e->getMessage()];
    }
}

function remove_relationship($pdo, $relId) {
    $pdo->prepare('DELETE FROM document_relationships WHERE id = ?')->execute([$relId]);
}

/**
 * Badge for a single version row in a chain.
 * $isCurrent = this is the chain's head (next_version_id IS NULL).
 * $isFirst   = this is v1.0 (previous_version_id IS NULL).
 * $isRollback = the change note on this version indicates a restore.
 */
function version_badge($isCurrent, $isFirst, $isRollback) {
    if ($isCurrent && $isFirst) return ['label' => 'CURRENT · ORIGINAL', 'class' => 'current'];
    if ($isCurrent) return ['label' => 'CURRENT', 'class' => 'current'];
    if ($isFirst) return ['label' => 'ORIGINAL FILING', 'class' => 'original'];
    if ($isRollback) return ['label' => 'RESTORED', 'class' => 'rollback'];
    return ['label' => 'SUPERSEDED', 'class' => 'superseded'];
}

/**
 * Lightweight legislative-stage caption shown alongside (not instead of)
 * the existing status stamp — purely additive context, the underlying
 * status enum in the documents table is unchanged.
 */
function legislative_stage_caption($status) {
    static $map = [
        'Draft'         => 'Drafting stage',
        'Submitted'     => 'Filed with the Secretariat',
        'Under Review'  => 'Committee review',
        'Enacted'       => 'Enacted — final version',
        'Amended'       => 'Superseded by a later amendment',
        'Superseded'    => 'Closed — superseded',
        'Withdrawn'     => 'Withdrawn by sponsor',
    ];
    return $map[$status] ?? '';
}
