<?php
declare(strict_types=1);

const DN_SCHEMA_VERSION = 8;
require_once __DIR__.'/schema_progression.php';
require_once __DIR__.'/schema_ranked.php';
require_once __DIR__.'/schema_world.php';
require_once __DIR__.'/bots.php';

function dn_exec_schema(mysqli $db, string $sql, array &$changes, string $label): void {
    if (!$db->query($sql)) {
        throw new RuntimeException($label . ': ' . $db->error);
    }
    $changes[] = $label;
}


function dn_column_exists(mysqli $db,string $table,string $column): bool {
    $stmt=$db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    if(!$stmt)return false;$stmt->bind_param('ss',$table,$column);$stmt->execute();$ok=(bool)$stmt->get_result()->fetch_row();$stmt->close();return$ok;
}
function dn_add_column_if_missing(mysqli $db,string $table,string $column,string $definition,array &$changes): void {
    if(dn_column_exists($db,$table,$column))return;
    $safeTable=preg_replace('/[^a-zA-Z0-9_]/','',$table);$safeColumn=preg_replace('/[^a-zA-Z0-9_]/','',$column);
    if(!$db->query('ALTER TABLE `'.$safeTable.'` ADD COLUMN `'.$safeColumn.'` '.$definition))throw new RuntimeException('Could not add '.$safeTable.'.'.$safeColumn.': '.$db->error);
    $changes[]='Added compatibility column '.$safeTable.'.'.$safeColumn;
}

function dn_seed_species(mysqli $db, array &$changes): void {
    $file = dirname(__DIR__) . '/config/digimon_species.json';
    $rows = json_decode((string)@file_get_contents($file), true);
    if (!is_array($rows)) throw new RuntimeException('Digimon species catalog is unavailable.');

    $sql = 'INSERT INTO digimon_species
        (slug,name,stage,attribute,type,element,base_hp,base_attack,base_defense,base_speed,skill_name,skill_power,sprite)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          name=VALUES(name),stage=VALUES(stage),attribute=VALUES(attribute),type=VALUES(type),element=VALUES(element),
          base_hp=VALUES(base_hp),base_attack=VALUES(base_attack),base_defense=VALUES(base_defense),base_speed=VALUES(base_speed),
          skill_name=VALUES(skill_name),skill_power=VALUES(skill_power),sprite=VALUES(sprite)';
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('Could not prepare Digimon catalog seed: ' . $db->error);
    foreach ($rows as $r) {
        $slug=(string)$r['slug']; $name=(string)$r['name']; $stage=(string)$r['stage'];
        $attribute=(string)$r['attribute']; $type=(string)$r['type']; $element=(string)$r['element'];
        $hp=(int)$r['base_hp']; $atk=(int)$r['base_attack']; $def=(int)$r['base_defense']; $spd=(int)$r['base_speed'];
        $skill=(string)$r['skill_name']; $power=(int)$r['skill_power']; $sprite=(string)$r['sprite'];
        $stmt->bind_param('ssssssiiiisis', $slug,$name,$stage,$attribute,$type,$element,$hp,$atk,$def,$spd,$skill,$power,$sprite);
        if (!$stmt->execute()) {
            $err=$stmt->error; $stmt->close(); throw new RuntimeException('Could not seed '.$name.': '.$err);
        }
    }
    $stmt->close();
    $changes[] = 'Synchronized '.count($rows).' Digimon species records';
}

function dn_seed_items(mysqli $db, array &$changes): void {
    $items = [
        ['hp-capsule-s','Small HP Capsule','Recovery','Restores 250 HP to one Digimon.',120,99,'HP'],
        ['hp-capsule-m','Medium HP Capsule','Recovery','Restores 500 HP to one Digimon.',300,99,'HP'],
        ['hp-capsule-l','Large HP Capsule','Recovery','Restores 1000 HP to one Digimon.',720,99,'HP'],
        ['sp-capsule-s','Small SP Capsule','Recovery','Restores 50 SP to one Digimon.',140,99,'SP'],
        ['sp-capsule-m','Medium SP Capsule','Recovery','Restores 100 SP to one Digimon.',340,99,'SP'],
        ['sp-capsule-l','Large SP Capsule','Recovery','Restores 250 SP to one Digimon.',780,99,'SP'],
        ['network-key','Network Key','Utility','A stabilized access key used by special network events.',1200,25,'KEY'],
        ['digicore-fragment','DigiCore Fragment','Material','Condensed Digimon data recovered from difficult battles.',850,99,'CORE'],
    ];
    $stmt=$db->prepare('INSERT INTO items(slug,name,category,description,price,stack_limit,icon_code) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),description=VALUES(description),price=VALUES(price),stack_limit=VALUES(stack_limit),icon_code=VALUES(icon_code)');
    if(!$stmt)throw new RuntimeException('Could not prepare item catalog seed.');
    foreach($items as $it){[$slug,$name,$cat,$desc,$price,$stack,$icon]=$it;$stmt->bind_param('ssssiis',$slug,$name,$cat,$desc,$price,$stack,$icon);if(!$stmt->execute())throw new RuntimeException('Could not seed item '.$name.': '.$stmt->error);}
    $stmt->close(); $changes[]='Synchronized '.count($items).' item records';
}

function dn_seed_news(mysqli $db, array &$changes): void {
    $rows=[
        ['Welcome to Digimon NXT','The NXT Network is online. Explore 264 Dawn locations at their supplied native image resolution, battle wild Digimon, secure scan data on contact and materialize new partners.','system'],
        ['DigiLab Materialization Protocol','Scan data is species-specific. Reach 100% scan data to materialize a persistent Digimon instance. Conversion uses all accumulated scan data. Every 20% above 100% grants 1 starting ABI, up to 5 at 200%.','research'],
        ['Paradox Signals Detected','The roster now contains 1,000 supplied forms. Paradox signals have a 0.2% chance per wild encounter, 1.75 times base stats, and yield only 1–3% scan data. Command a team of three and build ABI through evolution. Your full party recovers after every battle.','research'],
        ['Multiplayer Presence Active','Tamers sharing the same Cyberspace sector are synchronized through the map presence service. Movement remains server validated against the sector collision grid.','network'],
    ];
    $stmt=$db->prepare('INSERT INTO news(slug,title,body,category,published_at,is_published) VALUES(?,?,?,?,?,1) ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),category=VALUES(category),is_published=1');
    if(!$stmt)throw new RuntimeException('Could not prepare news seed.');
    $now=time();
    foreach($rows as $i=>$row){[$title,$body,$category]=$row;$slug='launch-'.($i+1);$published=$now-(86400*(count($rows)-1-$i));$stmt->bind_param('ssssi',$slug,$title,$body,$category,$published);if(!$stmt->execute())throw new RuntimeException('Could not seed news: '.$stmt->error);}
    $stmt->close(); $changes[]='Synchronized launch news records';
}

function dn_seed_events(mysqli $db, array &$changes): void {
    // Retire the launch-area labels before seeding the upgraded native-map event set.
    $db->query("UPDATE events SET is_active=0 WHERE slug IN ('packet-storm','firewall-breach','core-nexus-protocol')");
    $events=[
        ['cyber-route-sweep','Dawn Trail Survey','Dawn Trails','Win wild battles along the Dawn trails to stabilize the local network.',0,0],
        ['icewall-breach','Frozen Signal Survey','Frozen Reaches','Win wild battles across the frozen reaches to complete the signal survey.',0,0],
        ['the-core-protocol','Deep Network Survey','Deep Network','Win wild battles in the deep network to complete this advanced survey.',0,0],
    ];
    $stmt=$db->prepare('INSERT INTO events(slug,name,area_name,description,starts_at,ends_at,is_active) VALUES(?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),area_name=VALUES(area_name),description=VALUES(description),is_active=1');
    if(!$stmt)throw new RuntimeException('Could not prepare event seed.');
    foreach($events as $e){[$slug,$name,$area,$desc,$start,$end]=$e;$stmt->bind_param('ssssii',$slug,$name,$area,$desc,$start,$end);if(!$stmt->execute())throw new RuntimeException('Could not seed event: '.$stmt->error);}
    $stmt->close(); $changes[]='Synchronized Dawn operation records';
}

function dn_apply_schema_migrations(mysqli $db): array {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $changes=[];
    $tables=[
"CREATE TABLE IF NOT EXISTS schema_meta (
 meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
 meta_value VARCHAR(255) NOT NULL,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS members (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(45) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL,
 email VARCHAR(160) NOT NULL UNIQUE,
 role ENUM('tamer','moderator','admin') NOT NULL DEFAULT 'tamer',
 registered INT UNSIGNED NOT NULL DEFAULT 0,
 llogin INT UNSIGNED NOT NULL DEFAULT 0,
 last_login VARCHAR(45) NOT NULL DEFAULT '',
 ip VARCHAR(64) NOT NULL DEFAULT '',
 banned TINYINT(1) NOT NULL DEFAULT 0,
 memonmap TINYINT(1) NOT NULL DEFAULT 1,
 messonoff TINYINT(1) NOT NULL DEFAULT 1,
 messnotifyonoff TINYINT(1) NOT NULL DEFAULT 1,
 money INT NOT NULL DEFAULT 5000,
 battle INT UNSIGNED NOT NULL DEFAULT 0,
 wins INT UNSIGNED NOT NULL DEFAULT 0,
 losses INT UNSIGNED NOT NULL DEFAULT 0,
 totalexp BIGINT UNSIGNED NOT NULL DEFAULT 0,
 points INT UNSIGNED NOT NULL DEFAULT 0,
 time INT UNSIGNED NOT NULL DEFAULT 0,
 bio VARCHAR(500) NOT NULL DEFAULT '',
 created_avatar SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 KEY idx_members_username(username), KEY idx_members_registered(registered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS members_options (
 id INT UNSIGNED NOT NULL PRIMARY KEY,
 tamer SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 memonmap TINYINT(1) NOT NULL DEFAULT 1,
 messonoff TINYINT(1) NOT NULL DEFAULT 1,
 messnotifyonoff TINYINT(1) NOT NULL DEFAULT 1,
 battle_animation TINYINT(1) NOT NULL DEFAULT 1,
 compact_ui TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_options_member FOREIGN KEY(id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS login_trys (
 ip VARCHAR(64) NOT NULL PRIMARY KEY,
 username VARCHAR(45) NOT NULL DEFAULT '',
 time INT UNSIGNED NOT NULL DEFAULT 0,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS online (
 id INT UNSIGNED NOT NULL PRIMARY KEY,
 username VARCHAR(45) NOT NULL,
 activity VARCHAR(120) NOT NULL DEFAULT 'Dashboard',
 time INT UNSIGNED NOT NULL DEFAULT 0,
 useragent VARCHAR(250) NOT NULL DEFAULT '',
 server VARCHAR(45) NOT NULL DEFAULT 'local',
 CONSTRAINT fk_online_member FOREIGN KEY(id) REFERENCES members(id) ON DELETE CASCADE,
 KEY idx_online_time(time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS mapusers (
 id INT UNSIGNED NOT NULL PRIMARY KEY,
 username VARCHAR(45) NOT NULL,
 tamer SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 world_key VARCHAR(24) NOT NULL DEFAULT 'cyberspace',
 map VARCHAR(64) NOT NULL DEFAULT 'dawn-001',
 x INT NOT NULL DEFAULT 25,
 y INT NOT NULL DEFAULT 18,
 time TIME NULL,
 KEY idx_map_area(world_key,map),
 CONSTRAINT fk_mapuser_member FOREIGN KEY(id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS world_map_positions (
 user_id INT UNSIGNED NOT NULL,
 world_key VARCHAR(24) NOT NULL,
 area_key VARCHAR(64) NOT NULL,
 x INT NOT NULL,
 y INT NOT NULL,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(user_id,world_key,area_key),
 KEY idx_area(world_key,area_key,updated_at),
 CONSTRAINT fk_worldpos_member FOREIGN KEY(user_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS world_map_blocks (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 world_key VARCHAR(24) NOT NULL,
 area_key VARCHAR(64) NOT NULL,
 xblock INT NOT NULL,
 yblock INT NOT NULL,
 UNIQUE KEY uq_block(world_key,area_key,xblock,yblock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS digimon_species (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(100) NOT NULL UNIQUE,
 stage VARCHAR(24) NOT NULL,
 attribute VARCHAR(24) NOT NULL,
 type VARCHAR(45) NOT NULL,
 element VARCHAR(24) NOT NULL,
 base_hp INT UNSIGNED NOT NULL,
 base_attack INT UNSIGNED NOT NULL,
 base_defense INT UNSIGNED NOT NULL,
 base_speed INT UNSIGNED NOT NULL,
 skill_name VARCHAR(100) NOT NULL,
 skill_power INT UNSIGNED NOT NULL,
 sprite VARCHAR(180) NOT NULL,
 KEY idx_stage(stage), KEY idx_attribute(attribute)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS digimon_instances (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT UNSIGNED NOT NULL,
 species_id INT UNSIGNED NOT NULL,
 nickname VARCHAR(100) NOT NULL DEFAULT '',
 level INT UNSIGNED NOT NULL DEFAULT 1,
 exp BIGINT UNSIGNED NOT NULL DEFAULT 0,
 hp INT UNSIGNED NOT NULL DEFAULT 1,
 max_hp INT UNSIGNED NOT NULL DEFAULT 1,
 sp INT UNSIGNED NOT NULL DEFAULT 30,
 max_sp INT UNSIGNED NOT NULL DEFAULT 30,
 attack_stat INT UNSIGNED NOT NULL DEFAULT 1,
 defense_stat INT UNSIGNED NOT NULL DEFAULT 1,
 speed_stat INT UNSIGNED NOT NULL DEFAULT 1,
 personality VARCHAR(32) NOT NULL DEFAULT 'Balanced',
 source VARCHAR(32) NOT NULL DEFAULT 'materialized',
 locked_trade TINYINT(1) NOT NULL DEFAULT 0,
 created_at INT UNSIGNED NOT NULL DEFAULT 0,
 KEY idx_owner(owner_id), KEY idx_species(species_id),
 CONSTRAINT fk_digimon_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_digimon_species FOREIGN KEY(species_id) REFERENCES digimon_species(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS digimon_party (
 owner_id INT UNSIGNED NOT NULL,
 slot TINYINT UNSIGNED NOT NULL,
 digimon_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(owner_id,slot),
 UNIQUE KEY uq_party_digimon(owner_id,digimon_id),
 CONSTRAINT fk_party_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_party_instance FOREIGN KEY(digimon_id) REFERENCES digimon_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS digimon_scan (
 owner_id INT UNSIGNED NOT NULL,
 species_id INT UNSIGNED NOT NULL,
 scan_percent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 encounters INT UNSIGNED NOT NULL DEFAULT 0,
 wins INT UNSIGNED NOT NULL DEFAULT 0,
 first_seen INT UNSIGNED NOT NULL DEFAULT 0,
 last_seen INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(owner_id,species_id),
 KEY idx_scan_ready(owner_id,scan_percent),
 CONSTRAINT fk_scan_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_scan_species FOREIGN KEY(species_id) REFERENCES digimon_species(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS materialization_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT UNSIGNED NOT NULL,
 species_id INT UNSIGNED NOT NULL,
 digimon_id BIGINT UNSIGNED NOT NULL,
 scan_spent SMALLINT UNSIGNED NOT NULL DEFAULT 100,
 created_at INT UNSIGNED NOT NULL,
 KEY idx_materialize_owner(owner_id,created_at),
 CONSTRAINT fk_materialize_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_materialize_species FOREIGN KEY(species_id) REFERENCES digimon_species(id),
 CONSTRAINT fk_materialize_instance FOREIGN KEY(digimon_id) REFERENCES digimon_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS digivolution_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT UNSIGNED NOT NULL,
 digimon_id BIGINT UNSIGNED NOT NULL,
 from_species_id INT UNSIGNED NOT NULL,
 to_species_id INT UNSIGNED NOT NULL,
 level_at_change INT UNSIGNED NOT NULL,
 bits_spent INT UNSIGNED NOT NULL DEFAULT 0,
 created_at INT UNSIGNED NOT NULL,
 KEY idx_digivolution_owner(owner_id,created_at),
 CONSTRAINT fk_digivolution_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_digivolution_instance FOREIGN KEY(digimon_id) REFERENCES digimon_instances(id) ON DELETE CASCADE,
 CONSTRAINT fk_digivolution_from FOREIGN KEY(from_species_id) REFERENCES digimon_species(id),
 CONSTRAINT fk_digivolution_to FOREIGN KEY(to_species_id) REFERENCES digimon_species(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS battle_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT UNSIGNED NOT NULL,
 digimon_id BIGINT UNSIGNED NULL,
 enemy_species_id INT UNSIGNED NULL,
 battle_type ENUM('wild','arena','event') NOT NULL DEFAULT 'wild',
 result ENUM('win','loss','disconnect') NOT NULL,
 area_key VARCHAR(64) NOT NULL DEFAULT '',
 exp_gain INT UNSIGNED NOT NULL DEFAULT 0,
 scan_gain SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 bits_gain INT UNSIGNED NOT NULL DEFAULT 0,
 created_at INT UNSIGNED NOT NULL,
 KEY idx_battle_owner(owner_id,created_at),
 CONSTRAINT fk_battle_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_battle_digimon FOREIGN KEY(digimon_id) REFERENCES digimon_instances(id) ON DELETE SET NULL,
 CONSTRAINT fk_battle_enemy FOREIGN KEY(enemy_species_id) REFERENCES digimon_species(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS items (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(100) NOT NULL UNIQUE,
 category VARCHAR(40) NOT NULL,
 description VARCHAR(255) NOT NULL,
 price INT UNSIGNED NOT NULL DEFAULT 0,
 stack_limit INT UNSIGNED NOT NULL DEFAULT 99,
 icon_code VARCHAR(16) NOT NULL DEFAULT 'ITEM',
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS member_items (
 owner_id INT UNSIGNED NOT NULL,
 item_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(owner_id,item_id),
 CONSTRAINT fk_member_item_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_member_item_item FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT UNSIGNED NOT NULL,
 category VARCHAR(32) NOT NULL DEFAULT 'system',
 title VARCHAR(120) NOT NULL,
 body VARCHAR(500) NOT NULL,
 link VARCHAR(180) NOT NULL DEFAULT '',
 created_at INT UNSIGNED NOT NULL,
 read_at INT UNSIGNED NULL,
 KEY idx_notify_owner(owner_id,read_at,created_at),
 CONSTRAINT fk_notify_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS news (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(100) NOT NULL UNIQUE,
 title VARCHAR(160) NOT NULL,
 body TEXT NOT NULL,
 category VARCHAR(32) NOT NULL DEFAULT 'system',
 published_at INT UNSIGNED NOT NULL,
 is_published TINYINT(1) NOT NULL DEFAULT 1,
 KEY idx_news_publish(is_published,published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS messages (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 sender_id INT UNSIGNED NOT NULL,
 recipient_id INT UNSIGNED NOT NULL,
 subject VARCHAR(120) NOT NULL,
 body TEXT NOT NULL,
 created_at INT UNSIGNED NOT NULL,
 read_at INT UNSIGNED NULL,
 sender_deleted TINYINT(1) NOT NULL DEFAULT 0,
 recipient_deleted TINYINT(1) NOT NULL DEFAULT 0,
 KEY idx_msg_recipient(recipient_id,recipient_deleted,created_at),
 KEY idx_msg_sender(sender_id,sender_deleted,created_at),
 CONSTRAINT fk_msg_sender FOREIGN KEY(sender_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_msg_recipient FOREIGN KEY(recipient_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS friends (
 user_low INT UNSIGNED NOT NULL,
 user_high INT UNSIGNED NOT NULL,
 status ENUM('pending','accepted','blocked') NOT NULL DEFAULT 'pending',
 requested_by INT UNSIGNED NOT NULL,
 created_at INT UNSIGNED NOT NULL,
 updated_at INT UNSIGNED NOT NULL,
 PRIMARY KEY(user_low,user_high),
 KEY idx_friend_status(status),
 CONSTRAINT fk_friend_low FOREIGN KEY(user_low) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_friend_high FOREIGN KEY(user_high) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_friend_requester FOREIGN KEY(requested_by) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS clans (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL UNIQUE,
 tag VARCHAR(8) NOT NULL UNIQUE,
 description VARCHAR(500) NOT NULL DEFAULT '',
 owner_id INT UNSIGNED NOT NULL,
 created_at INT UNSIGNED NOT NULL,
 CONSTRAINT fk_clan_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS clan_members (
 clan_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL UNIQUE,
 member_rank ENUM('leader','officer','member') NOT NULL DEFAULT 'member',
 joined_at INT UNSIGNED NOT NULL,
 PRIMARY KEY(clan_id,user_id),
 CONSTRAINT fk_clan_member_clan FOREIGN KEY(clan_id) REFERENCES clans(id) ON DELETE CASCADE,
 CONSTRAINT fk_clan_member_user FOREIGN KEY(user_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS trade_listings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 seller_id INT UNSIGNED NOT NULL,
 digimon_id BIGINT UNSIGNED NOT NULL,
 price INT UNSIGNED NOT NULL,
 status ENUM('active','sold','cancelled') NOT NULL DEFAULT 'active',
 buyer_id INT UNSIGNED NULL,
 created_at INT UNSIGNED NOT NULL,
 completed_at INT UNSIGNED NULL,
 KEY idx_trade_market(status,created_at),
 KEY idx_trade_seller(seller_id,status),
 CONSTRAINT fk_trade_seller FOREIGN KEY(seller_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_trade_buyer FOREIGN KEY(buyer_id) REFERENCES members(id) ON DELETE SET NULL,
 CONSTRAINT fk_trade_digimon FOREIGN KEY(digimon_id) REFERENCES digimon_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS arena_progress (
 owner_id INT UNSIGNED NOT NULL PRIMARY KEY,
 rating INT UNSIGNED NOT NULL DEFAULT 1000,
 wins INT UNSIGNED NOT NULL DEFAULT 0,
 losses INT UNSIGNED NOT NULL DEFAULT 0,
 streak INT UNSIGNED NOT NULL DEFAULT 0,
 best_streak INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0,
 CONSTRAINT fk_arena_progress_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS arena_battles (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 owner_id INT UNSIGNED NOT NULL,
 opponent_key VARCHAR(64) NOT NULL,
 opponent_name VARCHAR(100) NOT NULL,
 result ENUM('win','loss') NOT NULL,
 rating_before INT UNSIGNED NOT NULL,
 rating_after INT UNSIGNED NOT NULL,
 bits_gain INT UNSIGNED NOT NULL DEFAULT 0,
 exp_gain INT UNSIGNED NOT NULL DEFAULT 0,
 created_at INT UNSIGNED NOT NULL,
 KEY idx_arena_owner(owner_id,created_at),
 CONSTRAINT fk_arena_battle_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS events (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(100) NOT NULL UNIQUE,
 name VARCHAR(140) NOT NULL,
 area_name VARCHAR(100) NOT NULL,
 description VARCHAR(500) NOT NULL,
 starts_at INT UNSIGNED NOT NULL DEFAULT 0,
 ends_at INT UNSIGNED NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS event_progress (
 owner_id INT UNSIGNED NOT NULL,
 event_id INT UNSIGNED NOT NULL,
 progress INT UNSIGNED NOT NULL DEFAULT 0,
 completed TINYINT(1) NOT NULL DEFAULT 0,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(owner_id,event_id),
 CONSTRAINT fk_event_progress_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_event_progress_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

"CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_id INT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 details VARCHAR(500) NOT NULL DEFAULT '',
 ip VARCHAR(64) NOT NULL DEFAULT '',
 created_at INT UNSIGNED NOT NULL,
 KEY idx_audit_actor(actor_id,created_at),
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach($tables as $i=>$sql) dn_exec_schema($db,$sql,$changes,'Verified schema component '.($i+1));

    // Compatibility additions for older v0.1.0 databases.
    dn_add_column_if_missing($db,'members','role',"ENUM('tamer','moderator','admin') NOT NULL DEFAULT 'tamer'",$changes);
    dn_add_column_if_missing($db,'members','bio',"VARCHAR(500) NOT NULL DEFAULT ''",$changes);
    dn_add_column_if_missing($db,'members','created_avatar',"TINYINT UNSIGNED NOT NULL DEFAULT 1",$changes);
    dn_add_column_if_missing($db,'members_options','battle_animation',"TINYINT(1) NOT NULL DEFAULT 1",$changes);
    dn_add_column_if_missing($db,'members_options','compact_ui',"TINYINT(1) NOT NULL DEFAULT 0",$changes);
    dn_add_column_if_missing($db,'digimon_instances','locked_trade',"TINYINT(1) NOT NULL DEFAULT 0",$changes);

    dn_progression_schema($db,$changes);
    dn_seed_species($db,$changes);
    dn_progression_upgrade($db,$changes);
    dn_seed_items($db,$changes);
    dn_seed_news($db,$changes);
    dn_seed_events($db,$changes);
    foreach ([['members','created_avatar'],['members_options','tamer'],['mapusers','tamer']] as [$table,$column]) {
        $type=$db->query("SHOW COLUMNS FROM `$table` LIKE '$column'")->fetch_assoc();
        if (!str_starts_with(strtolower((string)$type['Type']),'smallint')) {
            $db->query("ALTER TABLE `$table` MODIFY `$column` SMALLINT UNSIGNED NOT NULL DEFAULT 1");
            $changes[]='Expanded Dawn tamer selection for '.$table;
        }
    }
    dn_ranked_schema($db,$changes);
    dn_world_schema($db,$changes);
    dn_seed_bots($db,1000);
    $changes[]='Verified 1,000 persistent rival tamers';
    $legacyBattles=dn_rows($db,'SELECT owner_id,state_json FROM digimon_battle_state');
    foreach($legacyBattles as $legacy) {
        $state=json_decode((string)$legacy['state_json'],true);
        if((int)($state['combat_version']??0)>=2)continue;
        $db->begin_transaction();
        try {
            dn_lock_owner($db,(int)$legacy['owner_id']);
            $current=dn_active_battle($db,(int)$legacy['owner_id']);
            if((int)($current['combat_version']??0)<2) {
                dn_write($db,'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.hp=d.max_hp,d.sp=d.max_sp WHERE d.owner_id=?',[(int)$legacy['owner_id']]);
                dn_write($db,'DELETE FROM digimon_battle_state WHERE owner_id=?',[(int)$legacy['owner_id']]);
            }
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
    }
    if($legacyBattles)$changes[]='Verified team combat saves; retired single-partner battles restore the party without rewards';

    $version=(string)DN_SCHEMA_VERSION; $now=time();
    $stmt=$db->prepare("INSERT INTO schema_meta(meta_key,meta_value,updated_at) VALUES('schema_version',?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at)");
    if($stmt){$stmt->bind_param('si',$version,$now);$stmt->execute();$stmt->close();}
    $changes[]='Schema revision '.DN_SCHEMA_VERSION.' active';
    return $changes;
}
