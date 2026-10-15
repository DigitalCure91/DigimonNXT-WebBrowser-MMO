<?php
declare(strict_types=1);
/** Exact supplied v0.4.0/schema-6 upgrade gate. Creates/removes a separate random database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../public_html/includes/bootstrap.php';
require_once __DIR__.'/../public_html/includes/schema.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_error_handler(function(int $n,string $s,string $f,int $l): never { throw new ErrorException($s,0,$n,$f,$l); });
function v6_check(bool $ok,string $label): void { if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label,PHP_EOL; }
function v6_snapshot(mysqli $db,int $uid): array {
    $out=[];
    foreach(['members'=>'id','members_options'=>'id','digimon_instances'=>'owner_id','digimon_party'=>'owner_id','member_items'=>'owner_id','digimon_scan'=>'owner_id','arena_progress'=>'owner_id','world_map_positions'=>'user_id'] as $table=>$key)
        $out[$table]=dn_rows($db,"SELECT * FROM `$table` WHERE `$key`=? ORDER BY 1,2",[$uid]);
    $out['learned']=dn_rows($db,'SELECT l.* FROM digimon_learned_moves l JOIN digimon_instances d ON d.id=l.digimon_id WHERE d.owner_id=? ORDER BY l.digimon_id,l.move_slug',[$uid]);
    return $out;
}
$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);$db->set_charset('utf8mb4');
$name='digimon_nxt_v6_upgrade_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$name.'`');$db->select_db($name);$GLOBALS['mysql_connection']=$db;
try {
    $fixture=__DIR__.'/fixtures/schema_v6.sql';
    v6_check(hash_file('sha256',$fixture)==='a8804e68b64b3324ec331ede9603609124cad215047ae20eead32f7ccdc82781','fixture exactly matches SQL in supplied v0.4.0 ZIP');
    $sql=(string)file_get_contents($fixture);
    $sql=preg_replace('/^(CREATE DATABASE[^\n]*|USE `digimon_nxt`;)\r?\n/m','',$sql);
    $db->multi_query($sql);do{if($r=$db->store_result())$r->free();}while($db->more_results()&&$db->next_result());
    v6_check((int)dn_row($db,"SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")['meta_value']===6,'original schema-6 database imported');
    $catalog=dn_rows($db,'SELECT id,slug FROM digimon_species ORDER BY id');v6_check(count($catalog)===1000,'original 1000 species IDs captured');
    $password=pv_password_hash('Upgrade-fixture-only-2026');
    dn_write($db,'INSERT INTO members(username,password,email,money,totalexp,wins,losses,battle,registered,created_avatar,bio) VALUES(?,?,?,?,?,?,?,?,?,?,?)',['v6_save_fixture',$password,'v6-save@example.invalid',76543,987654,11,3,14,1700000000,4,'Existing v0.4.0 tamer']);$uid=(int)$db->insert_id;
    dn_write($db,'INSERT INTO members_options(id,tamer,memonmap,messonoff,messnotifyonoff,battle_animation,compact_ui) VALUES(?,4,1,0,1,0,1)',[$uid]);
    $agumon=dn_species_by_slug($db,'agumon');$gabumon=dn_species_by_slug($db,'gabumon');
    $partner=dn_create_instance($db,$uid,(int)$agumon['id'],'starter',37,77);
    $reserve=dn_create_instance($db,$uid,(int)$gabumon['id'],'materialized',23,41);
    $stored=dn_create_instance($db,$uid,(int)$agumon['id'],'materialized',12,19);
    dn_write($db,'UPDATE digimon_instances SET nickname=?,exp=?,cam=?,training_json=?,hp=?,sp=?,personality=? WHERE id=?',['Saved Partner',321,83,'{"hp":4,"atk":3,"int":2}',71,7,'Loyal',$partner]);
    dn_write($db,'UPDATE digimon_instances SET nickname=?,exp=?,cam=?,hp=0,sp=0 WHERE id=?',['Saved Reserve',123,66,$reserve]);
    dn_write($db,'UPDATE digimon_instances SET nickname=?,hp=31,sp=3 WHERE id=?',['Stored Partner',$stored]);
    dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?),(?,?,?)',[$uid,1,$partner,$uid,4,$reserve]);
    dn_write($db,'INSERT IGNORE INTO digimon_learned_moves(digimon_id,move_slug) VALUES(?,?)',[$partner,'heal']);
    dn_write($db,'UPDATE digimon_scan SET scan_percent=185,encounters=12,wins=7,first_seen=100,last_seen=200 WHERE owner_id=? AND species_id=?',[$uid,(int)$agumon['id']]);
    dn_write($db,'INSERT INTO member_items(owner_id,item_id,quantity) VALUES(?,?,?)',[$uid,1,17]);
    dn_write($db,'INSERT INTO arena_progress(owner_id,rating,wins,losses,streak,best_streak,updated_at) VALUES(?,1450,9,3,2,5,1700000001)',[$uid]);
    dn_write($db,'INSERT INTO world_map_positions(user_id,world_key,area_key,x,y,updated_at) VALUES(?,?,?,?,?,?)',[$uid,'cyberspace','packet-meadow',19,18,1700000002]);
    $legacy=['token'=>str_repeat('a',48),'area'=>'packet-meadow','started_at'=>time()-30,'turn'=>3,'player_id'=>$partner,'enemy_species_id'=>(int)$gabumon['id'],'enemy_name'=>'Gabumon','enemy_level'=>12,'enemy'=>['hp'=>50,'max_hp'=>200,'sp'=>10,'effects'=>[]],'units'=>[(string)$partner=>['hp'=>71,'sp'=>7,'effects'=>[]]],'finished'=>false,'result'=>'','scan_reward'=>0,'exp_gain'=>0,'bits_gain'=>0,'log'=>['Previous-version active encounter.']];
    dn_write($db,'INSERT INTO digimon_battle_state(owner_id,state_json,updated_at) VALUES(?,?,?)',[$uid,json_encode($legacy,JSON_THROW_ON_ERROR),time()]);
    $expected=v6_snapshot($db,$uid);
    // Revision 8 adds only the default session revocation counter to old accounts.
    foreach($expected['members'] as &$member)$member['session_version']=0;unset($member);
    // The only expected save change is restoring current party vitals when retiring the old encounter.
    foreach($expected['digimon_instances'] as &$d)if(in_array((int)$d['id'],[$partner,$reserve],true)){$d['hp']=$d['max_hp'];$d['sp']=$d['max_sp'];}unset($d);
    dn_apply_schema_migrations($db);
    v6_check(v6_snapshot($db,$uid)===$expected,'account/password, IDs, nicknames, ABI/CAM/training, levels/EXP, moves, party, wallet, items, scans, old arena and map saved exactly');
    v6_check(dn_active_battle($db,$uid)===null,'old single-partner battle safely retired');
    v6_check((int)dn_row($db,'SELECT COUNT(*) n FROM battle_log WHERE owner_id=?',[$uid])['n']===0&&(int)dn_row($db,'SELECT COUNT(*) n FROM nxt_ranked_matches WHERE attacker_id=? OR defender_id=?',[$uid,$uid])['n']===0,'retired encounter grants no battle record or rewards');
    v6_check(dn_rows($db,'SELECT id,slug FROM digimon_species ORDER BY id')===$catalog,'all original species IDs unchanged');
    v6_check((int)dn_row($db,"SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")['meta_value']===DN_SCHEMA_VERSION&&(int)dn_row($db,'SELECT COUNT(*) n FROM nxt_bots')['n']===1000,'schema upgraded to current revision and exactly 1000 persistent bots added');
    $bot=(int)dn_row($db,'SELECT owner_id FROM nxt_bots ORDER BY bot_no LIMIT 1')['owner_id'];
    $result=dn_bots_tick($db,1);v6_check($result['processed']===1&&!$result['errors'],'one persistent bot completes genuine activity before repeat repair');
    $botsBefore=dn_rows($db,'SELECT * FROM nxt_bots ORDER BY bot_no');$botBefore=v6_snapshot($db,$bot);$botRankBefore=dn_row($db,'SELECT * FROM nxt_ranked_profiles WHERE owner_id=?',[$bot]);
    $totalMembers=(int)dn_row($db,'SELECT COUNT(*) n FROM members')['n'];$totalInstances=(int)dn_row($db,'SELECT COUNT(*) n FROM digimon_instances')['n'];
    dn_apply_schema_migrations($db);
    v6_check(v6_snapshot($db,$uid)===$expected&&dn_active_battle($db,$uid)===null,'repeat repair preserves upgraded human save without additional restoration or rewards');
    v6_check(dn_rows($db,'SELECT * FROM nxt_bots ORDER BY bot_no')===$botsBefore&&v6_snapshot($db,$bot)===$botBefore&&dn_row($db,'SELECT * FROM nxt_ranked_profiles WHERE owner_id=?',[$bot])===$botRankBefore,'repeat repair preserves bot identity, activity, battle progress, party and rank profile');
    v6_check((int)dn_row($db,'SELECT COUNT(*) n FROM members')['n']===$totalMembers&&(int)dn_row($db,'SELECT COUNT(*) n FROM digimon_instances')['n']===$totalInstances,'repeat repair creates no duplicate tamers or partners');
    echo "SUPPLIED V0.4.0 / SCHEMA 6 TO CURRENT UPGRADE: PASS\n";
} finally {$db->select_db('information_schema');$db->query('DROP DATABASE `'.$name.'`');}
