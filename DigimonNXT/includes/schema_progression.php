<?php
declare(strict_types=1);

function dn_progression_schema(mysqli $db, array &$changes): void {
    foreach ([
        'abi'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
        'cam'=>'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'intelligence_stat'=>'INT UNSIGNED NOT NULL DEFAULT 1',
        'training_json'=>'TEXT NULL',
    ] as $column=>$definition) dn_add_column_if_missing($db,'digimon_instances',$column,$definition,$changes);
    dn_add_column_if_missing($db,'digivolution_log','abi_gained','SMALLINT UNSIGNED NOT NULL DEFAULT 0',$changes);
    dn_add_column_if_missing($db,'digivolution_log','direction',"VARCHAR(8) NOT NULL DEFAULT 'up'",$changes);
    dn_exec_schema($db,"CREATE TABLE IF NOT EXISTS digimon_learned_moves (
        digimon_id BIGINT UNSIGNED NOT NULL, move_slug VARCHAR(100) NOT NULL,
        PRIMARY KEY(digimon_id,move_slug),
        CONSTRAINT fk_learned_instance FOREIGN KEY(digimon_id) REFERENCES digimon_instances(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",$changes,'Verified learned move persistence');
    dn_exec_schema($db,"CREATE TABLE IF NOT EXISTS digimon_battle_state (
        owner_id INT UNSIGNED NOT NULL PRIMARY KEY, state_json LONGTEXT NOT NULL, updated_at INT UNSIGNED NOT NULL,
        CONSTRAINT fk_active_battle_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",$changes,'Verified persistent wild battles');
    dn_exec_schema($db,"CREATE TABLE IF NOT EXISTS digimon_action_receipts (
        owner_id INT UNSIGNED NOT NULL, token CHAR(48) NOT NULL, result_text VARCHAR(500) NOT NULL,
        created_at INT UNSIGNED NOT NULL, PRIMARY KEY(owner_id,token),
        CONSTRAINT fk_receipt_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",$changes,'Verified item request receipts');
}

function dn_progression_upgrade(mysqli $db, array &$changes): void {
    $row=$db->query("SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")->fetch_assoc();
    if ((int)($row['meta_value']??0)>=6) return;
    if($db->query("SELECT 1 FROM schema_meta WHERE meta_key='progression_v6_migrated'")->fetch_row())return;
    require_once __DIR__.'/digimon.php';
    $db->begin_transaction();
    try {
        $rows=$db->query('SELECT d.*,s.slug FROM digimon_instances d JOIN digimon_species s ON s.id=d.species_id');
        while ($d=$rows->fetch_assoc()) {
            $stats=dn_level_stats(dn_species_meta($d['slug']), (int)$d['level']);
            $hp=(int)$d['hp']<=0 ? 0 : max(1,(int)round($stats['max_hp']*(int)$d['hp']/max(1,(int)$d['max_hp'])));
            $sp=(int)round($stats['max_sp']*(int)$d['sp']/max(1,(int)$d['max_sp']));
            $exp=(int)$d['level']>=99?0:(int)floor(min(1,(int)$d['exp']/max(100,(int)$d['level']*(int)$d['level']*75))*dn_level_exp_required((int)$d['level']));
            $stmt=$db->prepare('UPDATE digimon_instances SET exp=?,hp=?,max_hp=?,sp=?,max_sp=?,attack_stat=?,defense_stat=?,intelligence_stat=?,speed_stat=? WHERE id=?');
            $stmt->bind_param('iiiiiiiiii',$exp,$hp,$stats['max_hp'],$sp,$stats['max_sp'],$stats['attack'],$stats['defense'],$stats['intelligence'],$stats['speed'],$d['id']); $stmt->execute(); $stmt->close();
            dn_learn_moves($db,(int)$d['id'],$d['slug'],(int)$d['level']);
            dn_discover_species($db,(int)$d['owner_id'],(int)$d['species_id']);
        }
        dn_write($db,"INSERT INTO schema_meta(meta_key,meta_value,updated_at) VALUES('progression_v6_migrated','1',?)",[time()]);
        $db->commit(); $changes[]='Migrated owned vitals and EXP progress proportionally; preserved levels and learned current-form skills';
    } catch (Throwable $e) { $db->rollback(); throw $e; }
}
