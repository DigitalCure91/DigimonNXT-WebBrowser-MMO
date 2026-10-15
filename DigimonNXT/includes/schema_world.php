<?php
declare(strict_types=1);

/** Additive revision 8: local console control and durable administrator audit. */
function dn_world_schema(mysqli $db, array &$changes): void {
    dn_add_column_if_missing($db, 'members', 'session_version', 'INT UNSIGNED NOT NULL DEFAULT 0', $changes);
    $tables = [
        "CREATE TABLE IF NOT EXISTS nxt_world_ip_bans (
            ip VARCHAR(45) NOT NULL PRIMARY KEY, reason VARCHAR(255) NOT NULL,
            banned_at INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_world_ip_ban_expiry(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_world_settings (
            setting_key VARCHAR(64) NOT NULL PRIMARY KEY, value_json TEXT NOT NULL,
            updated_at INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_world_bans (
            owner_id INT UNSIGNED NOT NULL PRIMARY KEY, reason VARCHAR(255) NOT NULL,
            banned_at INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL DEFAULT 0,
            KEY idx_world_ban_expiry(expires_at),
            CONSTRAINT fk_world_ban_member FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_world_admin_audit (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at INT UNSIGNED NOT NULL, operator_name VARCHAR(120) NOT NULL,
            command_name VARCHAR(64) NOT NULL, target_name VARCHAR(80) NOT NULL DEFAULT '',
            success TINYINT(1) NOT NULL, summary VARCHAR(500) NOT NULL,
            KEY idx_world_audit_time(created_at), KEY idx_world_audit_target(target_name,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($tables as $sql) $db->query($sql);
    $changes[] = 'Local world console controls, expiring bans and administrator audit ready';
}
