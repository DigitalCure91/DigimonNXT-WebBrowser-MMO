<?php
declare(strict_types=1);
/** Additive, repeatable Battle Park and autonomous tamer schema. */
function dn_ranked_schema(mysqli $db, array &$changes): void {
    $tables = [
        "CREATE TABLE IF NOT EXISTS nxt_bots (
            owner_id INT UNSIGNED NOT NULL PRIMARY KEY, bot_no SMALLINT UNSIGNED NOT NULL UNIQUE,
            activity_step INT UNSIGNED NOT NULL DEFAULT 0, last_active INT UNSIGNED NOT NULL DEFAULT 0,
            next_action_at INT UNSIGNED NOT NULL DEFAULT 0, last_area VARCHAR(64) NOT NULL DEFAULT 'cyber-route',
            wild_wins INT UNSIGNED NOT NULL DEFAULT 0, wild_losses INT UNSIGNED NOT NULL DEFAULT 0,
            materialized INT UNSIGNED NOT NULL DEFAULT 0, party_changes INT UNSIGNED NOT NULL DEFAULT 0,
            evolutions INT UNSIGNED NOT NULL DEFAULT 0, enabled TINYINT NOT NULL DEFAULT 1,
            KEY idx_bot_due(enabled,next_action_at,bot_no),
            CONSTRAINT fk_nxt_bot_member FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_ranked_profiles (
            owner_id INT UNSIGNED NOT NULL PRIMARY KEY, energy TINYINT UNSIGNED NOT NULL DEFAULT 5,
            energy_at INT UNSIGNED NOT NULL DEFAULT 0, wins INT UNSIGNED NOT NULL DEFAULT 0,
            losses INT UNSIGNED NOT NULL DEFAULT 0, draws INT UNSIGNED NOT NULL DEFAULT 0,
            medals INT UNSIGNED NOT NULL DEFAULT 0, joined_at INT UNSIGNED NOT NULL,
            CONSTRAINT fk_nxt_ranked_member FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_ranked_seasons (
            season_id INT UNSIGNED NOT NULL, owner_id INT UNSIGNED NOT NULL,
            points INT UNSIGNED NOT NULL DEFAULT 0, wins INT UNSIGNED NOT NULL DEFAULT 0,
            losses INT UNSIGNED NOT NULL DEFAULT 0, draws INT UNSIGNED NOT NULL DEFAULT 0,
            streak INT UNSIGNED NOT NULL DEFAULT 0, best_streak INT UNSIGNED NOT NULL DEFAULT 0,
            defense_wins INT UNSIGNED NOT NULL DEFAULT 0, defense_losses INT UNSIGNED NOT NULL DEFAULT 0,
            reward_claimed TINYINT NOT NULL DEFAULT 0, final_rank INT UNSIGNED NOT NULL DEFAULT 0, season_closed_at INT UNSIGNED NOT NULL DEFAULT 0, updated_at INT UNSIGNED NOT NULL,
            PRIMARY KEY(season_id,owner_id), KEY idx_nxt_season_board(season_id,points,wins,owner_id),
            CONSTRAINT fk_nxt_season_member FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_ranked_tickets (
            token CHAR(64) NOT NULL PRIMARY KEY, owner_id INT UNSIGNED NOT NULL UNIQUE,
            defender_id INT UNSIGNED NOT NULL, defender_json MEDIUMTEXT NOT NULL,
            season_id INT UNSIGNED NOT NULL, expires_at INT UNSIGNED NOT NULL,
            CONSTRAINT fk_nxt_ticket_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
            CONSTRAINT fk_nxt_ticket_defender FOREIGN KEY(defender_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_ranked_matches (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, request_token CHAR(64) NOT NULL UNIQUE,
            season_id INT UNSIGNED NOT NULL, attacker_id INT UNSIGNED NOT NULL, defender_id INT UNSIGNED NOT NULL,
            result ENUM('win','loss','draw') NOT NULL, points_before INT UNSIGNED NOT NULL, points_after INT UNSIGNED NOT NULL,
            bits_gain INT UNSIGNED NOT NULL, exp_gain INT UNSIGNED NOT NULL, medals_gain SMALLINT UNSIGNED NOT NULL,
            rounds SMALLINT UNSIGNED NOT NULL, attacker_json MEDIUMTEXT NOT NULL, defender_json MEDIUMTEXT NOT NULL,
            battle_json MEDIUMTEXT NOT NULL, created_at INT UNSIGNED NOT NULL,
            KEY idx_nxt_match_attacker(attacker_id,id), KEY idx_nxt_match_defender(defender_id,id),
            KEY idx_nxt_match_pair(attacker_id,defender_id,created_at), KEY idx_nxt_match_season(season_id,id),
            CONSTRAINT fk_nxt_match_attacker FOREIGN KEY(attacker_id) REFERENCES members(id) ON DELETE CASCADE,
            CONSTRAINT fk_nxt_match_defender FOREIGN KEY(defender_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_bot_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, owner_id INT UNSIGNED NOT NULL,
            action VARCHAR(24) NOT NULL, summary VARCHAR(255) NOT NULL, detail_json MEDIUMTEXT NOT NULL,
            match_id BIGINT UNSIGNED NULL, created_at INT UNSIGNED NOT NULL,
            KEY idx_nxt_activity_owner(owner_id,id), KEY idx_nxt_activity_action(action,id), KEY idx_nxt_activity_time(created_at),
            CONSTRAINT fk_nxt_activity_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS nxt_worker_state (
            worker_key VARCHAR(40) NOT NULL PRIMARY KEY, cursor_no INT UNSIGNED NOT NULL DEFAULT 0,
            last_run INT UNSIGNED NOT NULL DEFAULT 0, processed INT UNSIGNED NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($tables as $sql) { $db->query($sql); }
    dn_add_column_if_missing($db,'nxt_ranked_seasons','final_rank','INT UNSIGNED NOT NULL DEFAULT 0',$changes);
    dn_add_column_if_missing($db,'nxt_ranked_seasons','season_closed_at','INT UNSIGNED NOT NULL DEFAULT 0',$changes);
    $changes[]='Battle Park seasons, secure matchmaking, 1000-tamer worker and activity records ready.';
}
