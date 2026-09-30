<?php
/** Photos are optional until the deployment's photo migration is applied. */
function profile_photos_available(PDO $pdo): bool {
    static $available = null;
    if ($available === null) {
        $query = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_profile_photos'");
        $available = (bool)$query->fetchColumn();
    }
    return $available;
}
