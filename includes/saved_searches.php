<?php
/**
 * Saved Searches — lets a user store a search.php query configuration
 * (keyword, mode, and filters) under a name, and re-run it later.
 *
 * This does NOT implement its own search. A saved search is just the
 * exact q / mode / doc_type / status / date_from / date_to values that
 * search.php's existing GET-based search already understands. "Running"
 * a saved search means linking back to search.php with those same values
 * as query params, so it goes through the exact same keyword_search() /
 * semantic_search() code path (includes/semantic_search.php) as a search
 * the user types and submits by hand — nothing here duplicates that logic.
 *
 * Ownership: every function below that reads, renames, or deletes a
 * specific saved search takes the acting user's id and qualifies its
 * query with "AND user_id = ?". A user can never touch another user's
 * saved search by guessing or tampering with an id — the row simply
 * won't match the WHERE clause, the same way document_visibility_clause()
 * in includes/rbac.php scopes document rows to what a role may see.
 */
require_once __DIR__ . '/../config/database.php';

/**
 * All of a user's saved searches, most recently created first. Each row's
 * stored JSON criteria is decoded into a 'criteria' array for callers.
 */
function list_saved_searches($pdo, $userId) {
    $stmt = $pdo->prepare(
        'SELECT * FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['criteria'] = json_decode($row['search_criteria'], true);
        if (!is_array($row['criteria'])) {
            $row['criteria'] = []; // tolerate corrupted/unreadable criteria rather than crash
        }
    }
    unset($row);
    return $rows;
}

/**
 * Builds the search.php query string for a saved search's stored criteria.
 * Missing/blank pieces just fall back to search.php's own defaults (empty
 * filter, keyword mode), so an older saved search never breaks even if a
 * key is absent.
 */
function saved_search_query_string(array $criteria) {
    return http_build_query([
        'q'         => $criteria['q'] ?? '',
        'mode'      => $criteria['mode'] ?? 'keyword',
        'doc_type'  => $criteria['doc_type'] ?? '',
        'status'    => $criteria['status'] ?? '',
        'date_from' => $criteria['date_from'] ?? '',
        'date_to'   => $criteria['date_to'] ?? '',
    ]);
}

/**
 * Creates a saved search for the given user. $criteria is stored as-is
 * (JSON-encoded) — callers are expected to only pass the fields search.php
 * actually supports (see saved_search_query_string()).
 */
function create_saved_search($pdo, $userId, $name, array $criteria) {
    $stmt = $pdo->prepare(
        'INSERT INTO saved_searches (user_id, name, search_criteria) VALUES (?,?,?)'
    );
    $stmt->execute([$userId, $name, json_encode($criteria)]);
    return (int)$pdo->lastInsertId();
}

/**
 * Renames a saved search. Returns false if no saved search with that id
 * belongs to this user (not found OR not theirs — both look the same to
 * the caller, which is the point: no way to distinguish "doesn't exist"
 * from "exists but isn't yours" from the response).
 *
 * Checks ownership with a SELECT before the UPDATE rather than trusting
 * UPDATE's affected-row count, because renaming to the SAME name a
 * saved search already has would otherwise report 0 affected rows (no
 * column actually changed) and be mistaken for "not found".
 */
function rename_saved_search($pdo, $userId, $id, $newName) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM saved_searches WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if ((int)$stmt->fetchColumn() === 0) {
        return false;
    }
    $pdo->prepare('UPDATE saved_searches SET name = ? WHERE id = ? AND user_id = ?')
        ->execute([$newName, $id, $userId]);
    return true;
}

/**
 * Deletes a saved search. Returns false if no saved search with that id
 * belongs to this user. Only removes the saved-search row — never touches
 * documents, search_log, or anything else.
 */
function delete_saved_search($pdo, $userId, $id) {
    $stmt = $pdo->prepare('DELETE FROM saved_searches WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    return $stmt->rowCount() > 0;
}