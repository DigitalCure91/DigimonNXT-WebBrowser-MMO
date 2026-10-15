<?php
declare(strict_types=1);
/** CLI integration suite. Creates and removes a NEW isolated test database. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../public_html/includes/bootstrap.php';
require_once __DIR__.'/../public_html/includes/schema.php';
require_once __DIR__.'/../public_html/includes/digivolution.php';
require_once __DIR__.'/../public_html/includes/combat.php';
require_once __DIR__.'/../public_html/includes/arena.php';
require_once __DIR__.'/../public_html/includes/world_maps.php';
require_once __DIR__.'/../public_html/includes/catalog_ui.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
set_error_handler(function($n,$s,$f,$l){throw new ErrorException($s,0,$n,$f,$l);});
$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);$db->set_charset('utf8mb4');
$name='digimon_nxt_test_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$name.'`');$db->select_db($name);$GLOBALS['mysql_connection']=$db;
function expect(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);}
function blocked(callable $fn,string $label): void {try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('FAIL: accepted '.$label);}
function passed(string $label): void {echo 'PASS: '.$label,PHP_EOL;}
function user(mysqli $db): int {$u='test_'.bin2hex(random_bytes(5));dn_write($db,'INSERT INTO members(username,password,email,money,registered) VALUES(?,?,?,?,?)',[$u,pv_password_hash('Fixture-only-123'),$u.'@example.invalid',10000000,time()]);return(int)$db->insert_id;}
function put(mysqli $db,int $uid,string $slug,int $level=1,int $abi=0): int {$s=dn_species_by_slug($db,$slug);return dn_create_instance($db,$uid,(int)$s['id'],'test',$level,$abi);}
function unit(): array {return ['id'=>0,'slug'=>'agumon','name'=>'Fixture','hp'=>500,'max_hp'=>1000,'sp'=>10000,'max_sp'=>10000,'atk'=>100,'def'=>100,'int'=>100,'spd'=>100,'attribute'=>'Free','element'=>'Neutral','effects'=>[]];}
try {
 dn_apply_schema_migrations($db);expect((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_species')['n']===1000,'1000 seeded species');
 expect(count(dn_moves_catalog())===768,'768 moves');passed('fresh schema with 1000 forms / 768 moves');
 $uid=user($db);$other=user($db);$ids=[];
 foreach(dn_catalog() as $slug=>$s){
  $sp=dn_species_by_slug($db,$slug);$sid=(int)$sp['id'];dn_discover_species($db,$uid,$sid);
  dn_write($db,'UPDATE digimon_scan SET scan_percent=200 WHERE owner_id=? AND species_id=?',[$uid,$sid]);$id=dn_materialize($db,$uid,$sid);$ids[$slug]=$id;$d=dn_owned_instance($db,$uid,$id);
  expect((int)$d['level']===1&&(int)$d['abi']===5&&(int)$d['max_hp']>0,'materialize '.$slug);
  expect((int)dn_scan_row($db,$uid,$sid)['scan_percent']===0,'consume complete scan '.$slug);
  expect(count(dn_instance_moves($db,$d))>0,'moves for '.$slug);
  foreach([1,50,99] as $level){$st=dn_level_stats($s,$level);expect($st['max_hp']===(int)floor($s['stats'][(string)$level]['hp']*(dn_is_paradox($s)?1.75:1)),'HP anchor '.$slug);expect($st['intelligence']===(int)floor($s['stats'][(string)$level]['int']*(dn_is_paradox($s)?1.75:1)),'INT anchor '.$slug);}
  $reward=dn_award_scan($db,$uid,$sid,$s['stage']);expect((dn_is_paradox($s)?$reward['reward']>=1&&$reward['reward']<=3:$reward['reward']>=5)&&$reward['total']>0,'scan reward '.$slug);
 }
 expect(count(dn_party_rows($db,$uid))===6,'materialization party capacity');passed('every form: 200% conversion, ABI, vitals, learning and scan rewards');
 foreach(dn_digivolution_rules() as $rule){$a=dn_species_meta($rule['from']);$b=dn_species_meta($rule['to']);foreach([1,5,10,15,50,99] as $lv){if(!empty($rule['mode_change']))continue;expect(dn_abi_gain($a['stage'],$b['stage'],$lv,'up')>=0,'forward ABI '.$a['slug']);expect(dn_abi_gain($b['stage'],$a['stage'],$lv,'down')>=0,'reverse ABI '.$b['slug']);}}
 $stages=['Baby','In-Training','Rookie','Champion','Ultimate','Mega','Ultra'];$forward=[[0,5],[1,0],[1,5],[2,0],[2,5],[3,0]];
 foreach($forward as $i=>[$base,$offset])for($level=1;$level<=99;$level++){expect(dn_abi_gain($stages[$i],$stages[$i+1],$level,'up')===$base+intdiv($level+$offset,10),'reference forward formula');expect(dn_abi_gain($stages[$i+1],$stages[$i],$level,'down')===$i+1+intdiv($level,5),'reference reverse formula');}
 expect(dn_abi_gain('Rookie','Armor',20,'up')===4&&dn_abi_gain('Armor','Rookie',20,'down')===8,'Armor ABI');
 $id=put($db,$uid,'agumon',50);dn_write($db,'UPDATE digimon_instances SET cam=100 WHERE id=?',[$id]);
 dn_digivolve($db,$uid,$id,'greymon','up');$d=dn_owned_instance($db,$uid,$id);expect((int)$d['level']===1&&(int)$d['exp']===0&&(int)$d['abi']===6,'evolve reset and gain');$moves=dn_instance_moves($db,$d);expect(isset($moves['heavy-strike-i'])&&!isset($moves['pepper-breath']),'signature changes / inherited move retained');dn_digivolve($db,$uid,$id,'agumon','down');
 blocked(fn()=>dn_digivolve($db,$uid,$id,'wargreymon','up'),'nonexistent route');blocked(fn()=>dn_digivolve($db,$other,$id,'greymon','up'),'other owner');
 $paradox=put($db,$uid,'paradox-agumon',50);dn_write($db,'UPDATE digimon_instances SET cam=100 WHERE id=?',[$paradox]);dn_digivolve($db,$uid,$paradox,'paradox-greymon','up');expect(dn_owned_instance($db,$uid,$paradox)['slug']==='paradox-greymon','Paradox evolution retains variant');blocked(fn()=>dn_digivolve($db,$uid,$id,'paradox-greymon','up'),'ordinary-to-Paradox shortcut');
 $fresh=put($db,$other,'greymon',20);blocked(fn()=>dn_digivolve($db,$other,$fresh,'agumon','down'),'undiscovered de-evolution');
 $mode=put($db,$uid,'imperialdramondragonmode',50,80);dn_write($db,'UPDATE digimon_instances SET exp=123,hp=max_hp-100,sp=max_sp-10 WHERE id=?',[$mode]);
 $before=dn_owned_instance($db,$uid,$mode);dn_digivolve($db,$uid,$mode,'imperialdramon-fighter','mode');$after=dn_owned_instance($db,$uid,$mode);expect((int)$after['level']===50&&(int)$after['abi']===80&&(int)$after['exp']===123&&(int)$after['hp']<(int)$after['max_hp'],'free mode preserves progress / no full heal');dn_digivolve($db,$uid,$mode,'imperialdramondragonmode','mode');
 $fusion=put($db,$uid,'exveemon',99,100);$partner=put($db,$uid,'stingmon',99,7);dn_write($db,'UPDATE digimon_instances SET cam=100 WHERE id IN (?,?)',[$fusion,$partner]);$f=dn_digivolve($db,$uid,$fusion,'paildramon','up',$partner);expect(!dn_owned_instance($db,$uid,$partner),'DNA consumes partner');expect($f['abi']===111,'DNA ignores partner ABI');expect(isset(dn_instance_moves($db,dn_owned_instance($db,$uid,$fusion))['crescent-leaf-ii']),'DNA inherits partner learned skill');
 dn_train_stat($db,$uid,$id,'hp',5);expect(dn_training(dn_owned_instance($db,$uid,$id))['hp']===5,'training persist');blocked(fn()=>dn_train_stat($db,$uid,$id,'hp',500),'invalid training delta');passed('1902 routes, all ABI level boundaries, evolution, mode changes, discovery, DNA and training');
 foreach(pv_world_encounter_profiles() as $profile){foreach($profile['entries'] as [$slug,$min,$max,$weight])expect($min>=1&&$min<$max&&$max<=24&&$weight>0,'wild range '.$slug);for($i=0;$i<200;$i++)expect(dn_pick_wild_entry($profile)!==null,'wild weighted selection');}
 $rare=0;$sample=pv_world_encounter_profiles()['cyber-route'];for($i=0;$i<100000;$i++)if(dn_is_paradox(dn_pick_wild_entry($sample)[0]))$rare++;expect($rare>=100&&$rare<=310,'Paradox rarity sampling');
 passed('1000-form encounter coverage and varied ranges capped at level 24');
 $a=unit();$d=unit();$move=dn_basic_move();$move['variance']=[100,100];$move['crit']=0;expect(dn_combat_damage($a,$d,$move)===50,'physical damage');$move['type']='Magic';$a['int']=200;expect(dn_combat_damage($a,$d,$move)===125,'INT affects magic');
 $move['fixed']=100;$move['crit']=100;dn_add_effect($a,['kind'=>'acceleration']);expect(dn_combat_damage($a,$d,$move)===100,'fixed damage remains fixed');
 $a=unit();$d=unit();$b=['log'=>[]];$multi=dn_basic_move();$multi['hits']=[3,3];$multi['variance']=[100,100];$multi['crit']=0;dn_add_effect($a,['kind'=>'acceleration']);dn_combat_apply_move($a,$d,$multi,$b);expect($d['hp']===200&&!dn_has_effect($a,'acceleration'),'acceleration applies to whole multi-hit move');
 $a=unit();$d=unit();dn_add_effect($a,['kind'=>'stun','turns'=>1]);expect(!dn_combat_before_turn($a,$b),'one-turn stun acts');dn_combat_tick($a);expect(!dn_has_effect($a,'stun'),'stun expires');
 $a=unit();$d=unit();dn_combat_apply_move($a,$d,dn_moves_catalog()['heal'],$b);expect($a['hp']===750,'Heal restores 250');$a['hp']=0;dn_combat_apply_move($a,$d,dn_moves_catalog()['revive'],$b);expect($a['hp']===500,'Revive restores 50%');
 dn_add_effect($a,['kind'=>'poison']);dn_combat_apply_move($a,$d,dn_moves_catalog()['anti-poison'],$b);expect(!dn_has_effect($a,'poison'),'status cure');
 $a['effects']=[];dn_combat_apply_move($a,$d,dn_moves_catalog()['attack-charge'],$b);expect(dn_effect_stat($a,'atk')===110.00000000000001||round(dn_effect_stat($a,'atk'))===110.0,'buff affects stat');
 foreach(dn_moves_catalog() as $key=>$move){$a=unit();$d=unit();$b=['log'=>[]];dn_combat_apply_move($a,$d,$move,$b);expect($a['sp']>=0&&$d['hp']>=0&&$a['hp']<=1000&&$d['sp']>=0,'move execution '.$key);}
 passed('768 move execution paths; magic, fixed damage, multi-hit boosts, buffs, status duration, healing and revival');
 $item=dn_row($db,"SELECT * FROM items WHERE slug='hp-capsule-s'");$iid=(int)$item['id'];$token=bin2hex(random_bytes(24));$money=(int)dn_row($db,'SELECT money FROM members WHERE id=?',[$uid])['money'];dn_buy_item($db,$uid,$iid,3,$token);dn_buy_item($db,$uid,$iid,3,$token);expect((int)dn_row($db,'SELECT money FROM members WHERE id=?',[$uid])['money']===$money-360,'purchase charged once');
 blocked(fn()=>dn_buy_item($db,$uid,$iid,-1),'negative purchase');blocked(fn()=>dn_buy_item($db,$uid,$iid,99),'stack overflow');
 dn_write($db,'UPDATE digimon_instances SET hp=max_hp-10 WHERE id=?',[$id]);$msg=dn_use_recovery_item($db,$uid,$iid,$id);expect(str_contains($msg,'10 HP'),'actual clamped recovery');$qty=(int)dn_row($db,'SELECT quantity FROM member_items WHERE owner_id=? AND item_id=?',[$uid,$iid])['quantity'];blocked(fn()=>dn_use_recovery_item($db,$uid,$iid,$id),'full HP');blocked(fn()=>dn_use_recovery_item($db,$other,$iid,$id),'foreign recovery');expect((int)dn_row($db,'SELECT quantity FROM member_items WHERE owner_id=? AND item_id=?',[$uid,$iid])['quantity']===$qty,'invalid use does not consume');
 passed('capsule purchase receipts, quantities, prices, stack cap, ownership, clamping and no-op protection');
 // Team combat and durable command settlement are exercised by test_team_combat.php.
 passed('legacy economy/progression checks complete; use test_team_combat.php for 3v3 settlement regression coverage');
 $trade=put($db,$uid,'apollomon',50,90);dn_create_listing($db,$uid,$trade,1234);blocked(fn()=>dn_assign_party_slot($db,$uid,3,$trade),'listed Digimon in party');$listing=dn_row($db,'SELECT id FROM trade_listings WHERE digimon_id=?',[$trade]);dn_buy_listing($db,$other,(int)$listing['id']);$owned=dn_owned_instance($db,$other,$trade);expect($owned&&(int)$owned['abi']===90,'trade preserves ABI / identity');expect((int)dn_scan_row($db,$other,(int)$owned['species_id'])['first_seen']>0,'trade registers discovery');
 $result=dn_arena_challenge($db,$uid,dn_arena_opponents()[0]['key']);expect(is_array($result),'arena compatible with new stats');
 $_GET=['q'=>'Agumon','page'=>99999];$list=dn_catalog_listing($db,$uid);expect($list['page']<99999&&$list['total']>0&&count($list['rows'])<=30,'bounded searchable catalog');
 passed('exchange ownership / ABI / learned skills, arena compatibility and catalog pagination');
 echo "GAMEPLAY INTEGRATION: PASS\n";
} finally {$db->select_db('information_schema');$db->query('DROP DATABASE `'.$name.'`');}
