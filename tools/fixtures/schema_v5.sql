-- Digimon NXT v0.2.0 Full Database
-- Standalone phpMyAdmin / MariaDB / MySQL import.
-- Safe for a fresh database; CREATE TABLE IF NOT EXISTS preserves existing account data.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS schema_meta (
 meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
 meta_value VARCHAR(255) NOT NULL,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members (
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
 created_avatar TINYINT UNSIGNED NOT NULL DEFAULT 1,
 KEY idx_members_username(username), KEY idx_members_registered(registered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS members_options (
 id INT UNSIGNED NOT NULL PRIMARY KEY,
 tamer TINYINT UNSIGNED NOT NULL DEFAULT 1,
 memonmap TINYINT(1) NOT NULL DEFAULT 1,
 messonoff TINYINT(1) NOT NULL DEFAULT 1,
 messnotifyonoff TINYINT(1) NOT NULL DEFAULT 1,
 battle_animation TINYINT(1) NOT NULL DEFAULT 1,
 compact_ui TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_options_member FOREIGN KEY(id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_trys (
 ip VARCHAR(64) NOT NULL PRIMARY KEY,
 username VARCHAR(45) NOT NULL DEFAULT '',
 time INT UNSIGNED NOT NULL DEFAULT 0,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS online (
 id INT UNSIGNED NOT NULL PRIMARY KEY,
 username VARCHAR(45) NOT NULL,
 activity VARCHAR(120) NOT NULL DEFAULT 'Dashboard',
 time INT UNSIGNED NOT NULL DEFAULT 0,
 useragent VARCHAR(250) NOT NULL DEFAULT '',
 server VARCHAR(45) NOT NULL DEFAULT 'local',
 CONSTRAINT fk_online_member FOREIGN KEY(id) REFERENCES members(id) ON DELETE CASCADE,
 KEY idx_online_time(time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mapusers (
 id INT UNSIGNED NOT NULL PRIMARY KEY,
 username VARCHAR(45) NOT NULL,
 tamer TINYINT UNSIGNED NOT NULL DEFAULT 1,
 world_key VARCHAR(24) NOT NULL DEFAULT 'cyberspace',
 map VARCHAR(64) NOT NULL DEFAULT 'terminal-plaza',
 x INT NOT NULL DEFAULT 25,
 y INT NOT NULL DEFAULT 18,
 time TIME NULL,
 KEY idx_map_area(world_key,map),
 CONSTRAINT fk_mapuser_member FOREIGN KEY(id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS world_map_positions (
 user_id INT UNSIGNED NOT NULL,
 world_key VARCHAR(24) NOT NULL,
 area_key VARCHAR(64) NOT NULL,
 x INT NOT NULL,
 y INT NOT NULL,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(user_id,world_key,area_key),
 KEY idx_area(world_key,area_key,updated_at),
 CONSTRAINT fk_worldpos_member FOREIGN KEY(user_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS world_map_blocks (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 world_key VARCHAR(24) NOT NULL,
 area_key VARCHAR(64) NOT NULL,
 xblock INT NOT NULL,
 yblock INT NOT NULL,
 UNIQUE KEY uq_block(world_key,area_key,xblock,yblock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digimon_species (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digimon_instances (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digimon_party (
 owner_id INT UNSIGNED NOT NULL,
 slot TINYINT UNSIGNED NOT NULL,
 digimon_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(owner_id,slot),
 UNIQUE KEY uq_party_digimon(owner_id,digimon_id),
 CONSTRAINT fk_party_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_party_instance FOREIGN KEY(digimon_id) REFERENCES digimon_instances(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digimon_scan (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS materialization_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS digivolution_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS battle_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(100) NOT NULL UNIQUE,
 category VARCHAR(40) NOT NULL,
 description VARCHAR(255) NOT NULL,
 price INT UNSIGNED NOT NULL DEFAULT 0,
 stack_limit INT UNSIGNED NOT NULL DEFAULT 99,
 icon_code VARCHAR(16) NOT NULL DEFAULT 'ITEM',
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_items (
 owner_id INT UNSIGNED NOT NULL,
 item_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(owner_id,item_id),
 CONSTRAINT fk_member_item_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_member_item_item FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(100) NOT NULL UNIQUE,
 title VARCHAR(160) NOT NULL,
 body TEXT NOT NULL,
 category VARCHAR(32) NOT NULL DEFAULT 'system',
 published_at INT UNSIGNED NOT NULL,
 is_published TINYINT(1) NOT NULL DEFAULT 1,
 KEY idx_news_publish(is_published,published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS friends (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clans (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(80) NOT NULL UNIQUE,
 tag VARCHAR(8) NOT NULL UNIQUE,
 description VARCHAR(500) NOT NULL DEFAULT '',
 owner_id INT UNSIGNED NOT NULL,
 created_at INT UNSIGNED NOT NULL,
 CONSTRAINT fk_clan_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clan_members (
 clan_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NOT NULL UNIQUE,
 member_rank ENUM('leader','officer','member') NOT NULL DEFAULT 'member',
 joined_at INT UNSIGNED NOT NULL,
 PRIMARY KEY(clan_id,user_id),
 CONSTRAINT fk_clan_member_clan FOREIGN KEY(clan_id) REFERENCES clans(id) ON DELETE CASCADE,
 CONSTRAINT fk_clan_member_user FOREIGN KEY(user_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trade_listings (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arena_progress (
 owner_id INT UNSIGNED NOT NULL PRIMARY KEY,
 rating INT UNSIGNED NOT NULL DEFAULT 1000,
 wins INT UNSIGNED NOT NULL DEFAULT 0,
 losses INT UNSIGNED NOT NULL DEFAULT 0,
 streak INT UNSIGNED NOT NULL DEFAULT 0,
 best_streak INT UNSIGNED NOT NULL DEFAULT 0,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0,
 CONSTRAINT fk_arena_progress_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arena_battles (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(100) NOT NULL UNIQUE,
 name VARCHAR(140) NOT NULL,
 area_name VARCHAR(100) NOT NULL,
 description VARCHAR(500) NOT NULL,
 starts_at INT UNSIGNED NOT NULL DEFAULT 0,
 ends_at INT UNSIGNED NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_progress (
 owner_id INT UNSIGNED NOT NULL,
 event_id INT UNSIGNED NOT NULL,
 progress INT UNSIGNED NOT NULL DEFAULT 0,
 completed TINYINT(1) NOT NULL DEFAULT 0,
 updated_at INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(owner_id,event_id),
 CONSTRAINT fk_event_progress_owner FOREIGN KEY(owner_id) REFERENCES members(id) ON DELETE CASCADE,
 CONSTRAINT fk_event_progress_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_id INT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 details VARCHAR(500) NOT NULL DEFAULT '',
 ip VARCHAR(64) NOT NULL DEFAULT '',
 created_at INT UNSIGNED NOT NULL,
 KEY idx_audit_actor(actor_id,created_at),
 CONSTRAINT fk_audit_actor FOREIGN KEY(actor_id) REFERENCES members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

INSERT INTO schema_meta(meta_key,meta_value,updated_at) VALUES('schema_version','5',UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at=VALUES(updated_at);

INSERT INTO digimon_species (slug,name,stage,attribute,type,element,base_hp,base_attack,base_defense,base_speed,skill_name,skill_power,sprite) VALUES
('agumon','Agumon','Rookie','Vaccine','Reptile','Fire',86,25,18,20,'Pepper Breath',34,'images/digimon/agumon.png'),
('gabumon','Gabumon','Rookie','Data','Reptile','Ice',84,23,19,22,'Blue Blaster',33,'images/digimon/gabumon.png'),
('tentomon','Tentomon','Rookie','Vaccine','Insect','Electric',82,22,20,21,'Super Shocker',33,'images/digimon/tentomon.png'),
('palmon','Palmon','Rookie','Data','Plant','Nature',85,21,20,20,'Poison Ivy',32,'images/digimon/palmon.png'),
('patamon','Patamon','Rookie','Data','Mammal','Light',80,21,17,25,'Boom Bubble',31,'images/digimon/patamon.png'),
('gomamon','Gomamon','Rookie','Vaccine','Sea Animal','Water',88,21,22,18,'Marching Fishes',32,'images/digimon/gomamon.png'),
('veemon','Veemon','Rookie','Free','Dragon','Neutral',87,24,18,23,'Vee Headbutt',33,'images/digimon/veemon.png'),
('guilmon','Guilmon','Rookie','Virus','Reptile','Fire',90,26,18,20,'Pyro Sphere',35,'images/digimon/guilmon.png'),
('terriermon','Terriermon','Rookie','Vaccine','Beast','Wind',81,22,18,26,'Bunny Blast',32,'images/digimon/terriermon.png'),
('lopmon','Lopmon','Rookie','Data','Beast','Dark',81,22,18,25,'Blazing Ice',32,'images/digimon/lopmon.png'),
('hagurumon','Hagurumon','Rookie','Virus','Machine','Electric',86,20,25,17,'Darkness Gear',32,'images/digimon/hagurumon.png'),
('impmon','Impmon','Rookie','Virus','Demon','Dark',78,25,16,27,'Bada Boom',34,'images/digimon/impmon.png'),
('keramon','Keramon','Rookie','Free','Unidentified','Dark',83,24,17,26,'Crazy Giggle',33,'images/digimon/keramon.png'),
('biyomon','Biyomon','Rookie','Vaccine','Bird','Fire',80,22,17,27,'Spiral Twister',32,'images/digimon/biyomon.png'),
('betamon','Betamon','Rookie','Virus','Amphibian','Water',88,23,21,17,'Electric Shock',32,'images/digimon/betamon.png'),
('goblimon','Goblimon','Rookie','Virus','Demon','Earth',89,25,19,18,'Goblin Strike',34,'images/digimon/goblimon.png'),
('dracmon','Dracmon','Rookie','Virus','Undead','Dark',82,24,17,27,'Undead Fang',34,'images/digimon/dracmon.png'),
('lunamon','Lunamon','Rookie','Data','Mammal','Water',82,22,18,26,'Lop-Eared Ripple',33,'images/digimon/lunamon.png'),
('greymon','Greymon','Champion','Vaccine','Dinosaur','Fire',128,36,29,24,'Mega Flame',48,'images/digimon/greymon.png'),
('garurumon','Garurumon','Champion','Vaccine','Beast','Ice',122,34,26,31,'Fox Fire',46,'images/digimon/garurumon.png'),
('kabuterimon','Kabuterimon','Champion','Vaccine','Insect','Electric',126,33,31,25,'Electro Shocker',47,'images/digimon/kabuterimon.png'),
('angemon','Angemon','Champion','Vaccine','Angel','Light',118,35,25,31,'Hand of Fate',48,'images/digimon/angemon.png'),
('ikkakumon','Ikkakumon','Champion','Vaccine','Sea Animal','Ice',132,34,33,20,'Harpoon Torpedo',47,'images/digimon/ikkakumon.png'),
('exveemon','ExVeemon','Champion','Free','Dragon','Neutral',127,38,27,28,'Vee Laser',49,'images/digimon/exveemon.png'),
('growlmon','Growlmon','Champion','Virus','Dragon','Fire',130,39,27,25,'Exhaust Flame',50,'images/digimon/growlmon.png'),
('stingmon','Stingmon','Champion','Free','Insect','Nature',120,37,25,34,'Spiking Strike',49,'images/digimon/stingmon.png'),
('birdramon','Birdramon','Champion','Vaccine','Bird','Fire',121,35,24,34,'Meteor Wing',47,'images/digimon/birdramon.png'),
('gatomon','Gatomon','Champion','Vaccine','Holy Beast','Light',116,34,24,36,'Lightning Paw',47,'images/digimon/gatomon.png'),
('wizardmon','Wizardmon','Champion','Data','Wizard','Dark',115,36,23,35,'Thunder Cloud',48,'images/digimon/wizardmon.png'),
('tyrannomon','Tyrannomon','Champion','Data','Dinosaur','Fire',134,39,30,21,'Blaze Blast',50,'images/digimon/tyrannomon.png'),
('metalgreymon','MetalGreymon','Ultimate','Vaccine','Cyborg','Fire',176,49,39,29,'Giga Destroyer',65,'images/digimon/metalgreymon.png'),
('weregarurumon','WereGarurumon','Ultimate','Vaccine','Beast Man','Ice',164,47,34,42,'Wolf Claw',63,'images/digimon/weregarurumon.png'),
('megakabuterimon','MegaKabuterimon','Ultimate','Vaccine','Insect','Electric',171,45,42,32,'Horn Buster',64,'images/digimon/megakabuterimon.png'),
('magnaangemon','MagnaAngemon','Ultimate','Vaccine','Archangel','Light',158,46,34,43,'Gate of Destiny',64,'images/digimon/magnaangemon.png'),
('zudomon','Zudomon','Ultimate','Vaccine','Sea Animal','Ice',182,48,44,27,'Vulcan Hammer',65,'images/digimon/zudomon.png'),
('wargrowlmon','WarGrowlmon','Ultimate','Virus','Cyborg','Fire',174,52,38,33,'Atomic Blaster',67,'images/digimon/wargrowlmon.png'),
('paildramon','Paildramon','Ultimate','Free','Dragon Man','Neutral',169,50,37,38,'Desperado Blaster',66,'images/digimon/paildramon.png'),
('cyberdramon','Cyberdramon','Ultimate','Vaccine','Cyborg','Dark',168,51,38,37,'Desolation Claw',66,'images/digimon/cyberdramon.png'),
('lillymon','Lillymon','Ultimate','Data','Fairy','Nature',154,44,31,47,'Flower Cannon',63,'images/digimon/lillymon.png'),
('ladydevimon','LadyDevimon','Ultimate','Virus','Fallen Angel','Dark',157,49,32,45,'Darkness Wave',65,'images/digimon/ladydevimon.png'),
('wargreymon','WarGreymon','Mega','Vaccine','Dragon Man','Fire',225,64,51,43,'Terra Force',84,'images/digimon/wargreymon.png'),
('metalgarurumon','MetalGarurumon','Mega','Data','Cyborg','Ice',214,60,47,54,'Cocytus Breath',82,'images/digimon/metalgarurumon.png'),
('herculeskabuterimon','HerculesKabuterimon','Mega','Vaccine','Insect','Electric',223,60,55,42,'Mega Electro Shocker',83,'images/digimon/herculeskabuterimon.png'),
('seraphimon','Seraphimon','Mega','Vaccine','Seraph','Light',206,61,46,55,'Seven Heavens',83,'images/digimon/seraphimon.png'),
('vikemon','Vikemon','Mega','Free','Beast Man','Ice',236,63,57,36,'Arctic Blizzard',84,'images/digimon/vikemon.png'),
('gallantmon','Gallantmon','Mega','Virus','Holy Knight','Light',218,65,50,48,'Shield of the Just',85,'images/digimon/gallantmon.png'),
('imperialdramon-fighter','Imperialdramon Fighter Mode','Mega','Free','Ancient Dragon','Neutral',228,66,52,49,'Positron Laser',86,'images/digimon/imperialdramon-fighter.png'),
('sakuyamon','Sakuyamon','Mega','Data','Shaman','Light',205,62,45,58,'Amethyst Mandala',84,'images/digimon/sakuyamon.png')
ON DUPLICATE KEY UPDATE name=VALUES(name),stage=VALUES(stage),attribute=VALUES(attribute),type=VALUES(type),element=VALUES(element),base_hp=VALUES(base_hp),base_attack=VALUES(base_attack),base_defense=VALUES(base_defense),base_speed=VALUES(base_speed),skill_name=VALUES(skill_name),skill_power=VALUES(skill_power),sprite=VALUES(sprite);

INSERT INTO items(slug,name,category,description,price,stack_limit,icon_code) VALUES
('hp-capsule-s','Small HP Capsule','Recovery','Restores 35 HP to one Digimon.',120,99,'HP'),
('hp-capsule-m','Medium HP Capsule','Recovery','Restores 90 HP to one Digimon.',300,99,'HP'),
('hp-capsule-l','Large HP Capsule','Recovery','Restores 220 HP to one Digimon.',720,99,'HP'),
('sp-capsule-s','Small SP Capsule','Recovery','Restores 15 SP to one Digimon.',140,99,'SP'),
('sp-capsule-m','Medium SP Capsule','Recovery','Restores 35 SP to one Digimon.',340,99,'SP'),
('sp-capsule-l','Large SP Capsule','Recovery','Restores 75 SP to one Digimon.',780,99,'SP'),
('network-key','Network Key','Utility','A stabilized access key used by special network events.',1200,25,'KEY'),
('digicore-fragment','DigiCore Fragment','Material','Condensed Digimon data recovered from difficult battles.',850,99,'CORE')
ON DUPLICATE KEY UPDATE name=VALUES(name),category=VALUES(category),description=VALUES(description),price=VALUES(price),stack_limit=VALUES(stack_limit),icon_code=VALUES(icon_code),is_active=1;

INSERT INTO news(slug,title,body,category,published_at,is_published) VALUES
('launch-1','Welcome to Digimon NXT','The NXT Network is online. Explore the eleven native-resolution Cyberspace sectors, battle wild Digimon, recover scan data and materialize new partners.','system',UNIX_TIMESTAMP(),1),
('launch-2','DigiLab Materialization Protocol','Scan data is species-specific. Reach 100% scan data to materialize a persistent Digimon instance. Excess scan data remains available for additional materializations.','research',UNIX_TIMESTAMP(),1),
('launch-3','Multiplayer Presence Active','Tamers sharing the same Cyberspace sector are synchronized through the map presence service. Movement remains server validated against the sector collision grid.','network',UNIX_TIMESTAMP(),1)
ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),category=VALUES(category),is_published=1;

INSERT INTO events(slug,name,area_name,description,starts_at,ends_at,is_active) VALUES
('cyber-route-sweep','Cyber Route Sweep','Cyber Route','Defeat wild Digimon along Cyber Route to stabilize corrupted packet clusters.',0,0,1),
('icewall-breach','Icewall Breach','Icewall','Push through Icewall and recover DigiCore Fragments from hostile signatures.',0,0,1),
('the-core-protocol','The Core Protocol','The Core','A high-level network challenge centered on the deepest accessible sector of Cyberspace.',0,0,1)
ON DUPLICATE KEY UPDATE name=VALUES(name),area_name=VALUES(area_name),description=VALUES(description),is_active=1;
