<?php
/** Shared application policy; used by every interactive password writer. */
function password_policy_error($password): ?string {
    if (!is_string($password) || str_contains($password, "\0") || mb_strlen($password) < 15 || strlen($password) > 72) {
        return 'Use a passphrase of at least 15 characters and at most 72 bytes.';
    }
    if (preg_match('/^(.)\1+$/us', $password) || in_array(strtolower(trim($password)), ['password123456789','123456789012345','1234567890123456','qwertyuiopasdfgh','administrator123'], true)) {
        return 'Choose a less predictable passphrase.';
    }
    return null;
}

function privileged_mfa_required(array $user): bool {
    if (in_array($user['role_name'] ?? '', ['Super Admin','Administrator'], true)) return true;
    $stmt = get_db()->prepare("SELECT 1 FROM roles r WHERE r.id=? AND (r.name IN ('Super Admin','Administrator') OR EXISTS (SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE rp.role_id=r.id AND p.module='access' AND p.action IN ('manage_users','manage_roles','reset_password'))) LIMIT 1");
    $stmt->execute([(int)($user['role_id'] ?? 0)]);
    return (bool)$stmt->fetchColumn();
}

function revoke_user_sessions(int $userId, ?string $keepToken = null): void {
    $sql = 'UPDATE user_sessions SET is_active=0 WHERE user_id=?';
    $params = [$userId];
    if ($keepToken !== null) { $sql .= ' AND session_token<>?'; $params[]=$keepToken; }
    get_db()->prepare($sql)->execute($params);
}
