<?php
require_once __DIR__ . '/../config/database.php';

function soft_delete_enabled(): bool {
    static $enabled = null;
    if ($enabled === null) {
        $stmt = get_db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('deleted_at', 'deleted_by', 'delete_reason')");
        $enabled = (int)$stmt->fetchColumn() === 3;
    }
    return $enabled;
}

function users_live_clause(string $alias = 'u'): string {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid user table alias.');
    }
    return soft_delete_enabled() ? ' AND ' . $alias . '.deleted_at IS NULL' : '';
}

function is_user_deleted(array $user): bool {
    if (!soft_delete_enabled()) return false;
    if (array_key_exists('deleted_at', $user)) return $user['deleted_at'] !== null;
    if (empty($user['id'])) return false;
    $stmt = get_db()->prepare('SELECT deleted_at FROM users WHERE id = ?');
    $stmt->execute([(int)$user['id']]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== null;
}
