<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../public_html/includes/bootstrap.php';require_once __DIR__.'/../public_html/includes/schema.php';require_once __DIR__.'/../public_html/includes/digimon.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);set_error_handler(function($n,$s,$f,$l){throw new ErrorException($s,0,$n,$f,$l);});
$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);$db->set_charset('utf8mb4');$fresh=null;$name='digimon_nxt_upgrade_test_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$name.'`');$db->select_db($name);
function check_upgrade(bool $ok,string $message): void {if(!$ok)throw new RuntimeException('FAIL: '.$message);}
try {
 $sql=file_get_contents(__DIR__.'/fixtures/schema_v5.sql');$db->multi_query($sql);do{if($r=$db->store_result())$r->free();}while($db->more_results()&&$db->next_result());
 check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_species')['n']===48,'authentic v5 fixture has 48 species');
 $original=dn_rows($db,'SELECT id,slug FROM digimon_species ORDER BY id');$pw=pv_password_hash('Migration-fixture-only');
 dn_write($db,'INSERT INTO members(username,password,email,money,totalexp,registered) VALUES(?,?,?,?,?,?)',['upgrade_test',$pw,'upgrade@example.invalid',5678,987654,time()]);$uid=(int)$db->insert_id;$sid=(int)$original[0]['id'];
 dn_write($db,'INSERT INTO members_options(id,tamer,memonmap) VALUES(?,?,?)',[$uid,4,1]);
 dn_write($db,'INSERT INTO digimon_instances(owner_id,species_id,nickname,level,exp,hp,max_hp,sp,max_sp,attack_stat,defense_stat,speed_stat,personality,source,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$uid,$sid,'Saved Partner',20,15000,90,180,20,40,80,60,70,'Loyal','starter',time()]);$id=(int)$db->insert_id;
 dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$uid,2,$id]);
 dn_write($db,'INSERT INTO digimon_instances(owner_id,species_id,nickname,level,exp,hp,max_hp,sp,max_sp,attack_stat,defense_stat,speed_stat,personality,source,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$uid,$sid,'Saved KO',10,0,0,100,0,20,40,20,30,'Calm','materialized',time()]);$ko=(int)$db->insert_id;
 dn_write($db,'INSERT INTO digimon_scan(owner_id,species_id,scan_percent,encounters,wins,first_seen,last_seen) VALUES(?,?,?,?,?,?,?)',[$uid,$sid,185,12,7,100,200]);dn_write($db,'INSERT INTO member_items(owner_id,item_id,quantity) VALUES(?,?,?)',[$uid,1,17]);dn_write($db,'INSERT INTO world_map_positions(user_id,world_key,area_key,x,y) VALUES(?,?,?,?,?)',[$uid,'cyberspace','packet-meadow',19,18]);
 dn_apply_schema_migrations($db);$after=dn_owned_instance($db,$uid,$id);$st=dn_level_stats($after,20);
 check_upgrade($after['nickname']==='Saved Partner'&&(int)$after['level']===20&&$after['personality']==='Loyal','identity and level preserved');
 check_upgrade((int)$after['hp']===(int)round($st['max_hp']*.5)&&(int)$after['sp']===(int)round($st['max_sp']*.5),'vitals migrated proportionally');
 check_upgrade((int)$after['exp']===(int)floor(.5*dn_level_exp_required(20)),'EXP progress preserved proportionally');
 check_upgrade((int)dn_owned_instance($db,$uid,$ko)['hp']===0,'KO stays defeated');check_upgrade(count(dn_instance_moves($db,$after))>1,'current-level inherited skills migrated');
 $member=dn_row($db,'SELECT * FROM members WHERE id=?',[$uid]);check_upgrade($member['password']===$pw&&(int)$member['money']===5678&&(int)$member['totalexp']===987654,'account, wallet and lifetime EXP preserved');
 check_upgrade((int)dn_scan_row($db,$uid,$sid)['scan_percent']===185,'scan data preserved');check_upgrade((int)dn_row($db,'SELECT quantity FROM member_items WHERE owner_id=?',[$uid])['quantity']===17,'inventory preserved');check_upgrade((int)dn_party_rows($db,$uid)[2]['id']===$id,'party slot preserved');
 check_upgrade(dn_rows($db,'SELECT id,slug FROM digimon_species ORDER BY id LIMIT 48')===$original,'original species IDs preserved');
 dn_apply_schema_migrations($db);check_upgrade(dn_owned_instance($db,$uid,$id)===$after,'repeat migration is idempotent');
 check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_species')['n']===1000,'catalog expanded');
 // Old single-partner battle states cannot be resumed in the new engine.
 dn_write($db,'INSERT INTO digimon_battle_state(owner_id,state_json,updated_at) VALUES(?,?,?)',[$uid,json_encode(['token'=>'legacy','finished'=>false,'player_id'=>$id]),time()]);
 dn_apply_schema_migrations($db);
 check_upgrade(dn_active_battle($db,$uid)===null,'legacy battle retired');
 $restored=dn_owned_instance($db,$uid,$id);check_upgrade((int)$restored['hp']===(int)$restored['max_hp']&&(int)$restored['sp']===(int)$restored['max_sp'],'legacy active party restored');
 check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM battle_log WHERE owner_id=?',[$uid])['n']===0,'legacy retirement grants no battle reward');
 check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM nxt_bots')['n']===1000,'exactly 1000 bots after repeated upgrade');
 echo "UPGRADE INTEGRATION: PASS (v5 → current schema, 48 → 1000; accounts, IDs, inventory, party, scans, vitals, EXP and repeat migration)\n";
 $fresh='digimon_nxt_sql_test_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$fresh.'`');$db->select_db($fresh);
 $sql=(string)file_get_contents(__DIR__.'/../database/digimon_nxt_full.sql');$sql=preg_replace('/^(CREATE DATABASE.*|USE `digimon_nxt`;)\n/m','',$sql);$db->multi_query($sql);do{if($result=$db->store_result())$result->free();}while($db->more_results()&&$db->next_result());
 check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_species')['n']===1000,'SQL import has all forms');check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM nxt_bots')['n']===1000,'SQL has all AI rivals');check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM members m LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE b.owner_id IS NULL')['n']===0,'SQL has no human/test users');dn_apply_schema_migrations($db);check_upgrade((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_species')['n']===1000,'repair after SQL preserves catalog');echo "FRESH SQL IMPORT: PASS\n";
} finally {$db->select_db('information_schema');if($fresh)$db->query('DROP DATABASE IF EXISTS `'.$fresh.'`');$db->query('DROP DATABASE `'.$name.'`');}

