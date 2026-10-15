<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../public_html/includes/schema.php';
require_once __DIR__.'/../public_html/includes/world_commands.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
set_error_handler(function($severity,$message,$file,$line){if(!(error_reporting()&$severity))return false;throw new ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function wc_check(bool $ok,string $label): void {global$checks;if(!$ok)throw new RuntimeException('FAIL '.$label);++$checks;echo 'PASS '.$label.PHP_EOL;}
function wc_run(mysqli $db,string $line,bool $ok=true,array $extra=[]): array {$r=dn_world_execute($db,array_merge(['line'=>$line],$extra));wc_check($r['ok']===$ok,'command '.$line);return$r;}
function wc_confirm(mysqli $db,string $line,bool $ok=true): array{return wc_run($db,$line,$ok,['confirm'=>true]);}
$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);$db->set_charset('utf8mb4');$db->query("SET time_zone='+00:00'");$name='nxt_world_cmd_test_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$name.'`');$db->select_db($name);$GLOBALS['mysql_connection']=$db;
try{
    dn_apply_schema_migrations($db);
    wc_check((int)dn_row($db,"SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")['meta_value']===8,'fresh schema revision8');
    $secret='Secret_fixture_48!';
    $r=wc_run($db,'account create WorldTest worldtest@example.invalid');wc_check(!empty($r['requires_secret'])&&!dn_row($db,"SELECT id FROM members WHERE username='WorldTest'"),'private prompt creates nothing');
    $r=wc_run($db,'account create WorldTest worldtest@example.invalid agumon',true,['secret'=>$secret]);$uid=$r['data']['account_id'];
    $owner=dn_world_account($db,'WorldTest');wc_check(password_verify($secret,$owner['password']),'account password hashed');
    wc_check(count(dn_party_rows($db,$uid))===3,'created account has playable three-partner squad');
    wc_run($db,'account create WorldTest duplicate@example.invalid',false,['secret'=>$secret]);
    wc_run($db,'account password WorldTest inlineSecret_should_not_log',false);
    wc_run($db,'account password WorldTest',false,['secret'=>'short']);
    wc_run($db,'account create WorldAdmin worldadmin@example.invalid',true,['secret'=>$secret]);
    wc_confirm($db,'account role WorldAdmin admin');
    wc_confirm($db,'account role WorldAdmin tamer',false);
    wc_confirm($db,'account ban WorldAdmin permanent "Last admin guard"',false);
    wc_run($db,'account create WorldSecond second@example.invalid',true,['secret'=>$secret]);wc_confirm($db,'account role WorldSecond admin');
    wc_confirm($db,'account role WorldAdmin moderator');
    dn_write($db,"INSERT INTO world_map_positions(user_id,world_key,area_key,x,y,updated_at) VALUES(?,'cyberspace','terminal-plaza',8,9,?)",[$uid,time()]);
    wc_run($db,'account kick WorldTest "Test revoke"');wc_check((int)dn_world_account($db,'WorldTest')['session_version']===1,'kick revokes sessions');wc_check((int)dn_row($db,'SELECT COUNT(*) n FROM world_map_positions WHERE user_id=?',[$uid])['n']===1,'kick preserves saved map position');
    wc_run($db,'account password WorldTest',true,['secret'=>'New_fixture_123!']);wc_check(password_verify('New_fixture_123!',dn_world_account($db,'WorldTest')['password']),'password replaced');
    wc_check((int)dn_world_account($db,'WorldTest')['session_version']===2,'password revokes sessions');
    $r=wc_run($db,'account ban WorldTest 5 "Test reason"');wc_check(!empty($r['requires_confirmation'])&&!(int)dn_world_account($db,'WorldTest')['banned'],'ban waits for confirmation');
    wc_confirm($db,'account ban WorldTest 5 "Test reason"');wc_check((int)dn_world_account($db,'WorldTest')['banned']===1,'ban changes account');wc_run($db,'account unban WorldTest');wc_check(!(int)dn_world_account($db,'WorldTest')['banned'],'unban changes account');
    wc_run($db,'givemoney WorldTest 1000');wc_check((int)dn_world_account($db,'WorldTest')['money']===6000,'grant updates exact funds');
    wc_confirm($db,'takemoney WorldTest 7000',false);wc_check((int)dn_world_account($db,'WorldTest')['money']===6000,'insufficient funds leaves balance');
    wc_confirm($db,'takemoney WorldTest 1000');wc_confirm($db,'setmoney WorldTest 999999999');wc_run($db,'givemoney WorldTest 1',false);wc_confirm($db,'setmoney WorldTest 5000');
    $r=wc_run($db,'givedigimon WorldTest agumon 50 100');$dig=$r['data']['instance_id'];$d=dn_owned_instance($db,$uid,$dig);wc_check((int)$d['level']===50&&(int)$d['abi']===100&&(int)$d['hp']===(int)$d['max_hp'],'grant constructs valid owned instance');
    wc_confirm($db,'digimon level WorldTest '.$dig.' 99');$d=dn_owned_instance($db,$uid,$dig);$expected=dn_level_stats($d,99);wc_check((int)$d['max_hp']===$expected['max_hp']&&(int)$d['exp']===0,'level command uses real species stat curve');
    wc_confirm($db,'digimon abi WorldTest '.$dig.' 200');wc_run($db,'digimon cam WorldTest '.$dig.' 100');wc_run($db,'digimon cam WorldTest '.$dig.' 101',false);
    wc_run($db,'party set WorldTest 4 '.$dig);wc_confirm($db,'removedigimon WorldTest '.$dig,false);wc_confirm($db,'party remove WorldTest 4');wc_confirm($db,'removedigimon WorldTest '.$dig);wc_check(!dn_owned_instance($db,$uid,$dig),'reserve deletion applied');
    wc_confirm($db,'party remove WorldTest 3');wc_confirm($db,'party remove WorldTest 2');$party=dn_party_rows($db,$uid);$sole=(int)$party[1]['id'];wc_run($db,'party set WorldTest 4 '.$sole,false);wc_confirm($db,'party remove WorldTest 1',false);wc_check(count(dn_party_rows($db,$uid))===1,'last active partner protected');
    dn_write($db,'INSERT INTO digimon_battle_state(owner_id,state_json,updated_at) VALUES(?,?,?)',[$uid,json_encode(['finished'=>false]),time()]);
    wc_run($db,'givedigimon WorldTest gabumon',false);wc_run($db,'heal WorldTest',false);wc_run($db,'scan give WorldTest agumon 50',false);wc_run($db,'giveitem WorldTest hp-capsule-s 1',false);wc_confirm($db,'digimon level WorldTest '.$sole.' 25',false);
    dn_write($db,'DELETE FROM digimon_battle_state WHERE owner_id=?',[$uid]);
    wc_run($db,'heal WorldTest all');wc_run($db,'giveitem WorldTest hp-capsule-s 94');wc_run($db,'giveitem WorldTest hp-capsule-s 1',false);wc_confirm($db,'takeitem WorldTest hp-capsule-s 100',false);wc_confirm($db,'takeitem WorldTest hp-capsule-s 99');
    wc_run($db,'scan give WorldTest agumon 150');wc_run($db,'scan give WorldTest agumon 100');$s=dn_world_species($db,'agumon');wc_check((int)dn_scan_row($db,$uid,(int)$s['id'])['scan_percent']===200,'scan caps at200');wc_confirm($db,'scan set WorldTest agumon 20');
    wc_run($db,'mail WorldTest "Support reply" "Hello tamer"');wc_check((int)dn_row($db,"SELECT COUNT(*) n FROM notifications WHERE owner_id=? AND title='Support reply'",[$uid])['n']===1,'support notification delivered');
    wc_confirm($db,'announce "Scheduled event" 600');$notice=dn_world_setting($db,'announcement',null,true);wc_check($notice['message']==='Scheduled event'&&$notice['expires_at']>time()+590,'live announcement persisted');wc_run($db,'announce clear');
    wc_confirm($db,'maintenance on "Test maintenance"');wc_check(dn_world_setting($db,'world.maintenance',null,true)['enabled']===true,'maintenance persisted');wc_run($db,'bots run 1',false);wc_run($db,'maintenance off');wc_run($db,'bots pause');wc_run($db,'bots run 1',false);wc_run($db,'bots resume');
    $bot=dn_row($db,'SELECT m.username FROM nxt_bots b JOIN members m ON m.id=b.owner_id ORDER BY bot_no LIMIT 1')['username'];wc_run($db,'bots disable '.$bot);wc_run($db,'bots enable '.$bot);wc_run($db,'bots disable WorldTest',false);wc_run($db,'account password '.$bot,false,['secret'=>$secret]);
    wc_confirm($db,'ip ban 127.0.0.1 permanent "loopback test"',false);wc_confirm($db,'ip ban ::1 permanent "loopback test"',false);wc_confirm($db,'ip ban 0.0.0.0 permanent "invalid target"',false);wc_confirm($db,'ip ban 224.1.1.1 permanent "multicast test"',false);
    wc_confirm($db,'ip ban 2001:0db8:0000:0000:0000:0000:0000:0001 5 "test"');wc_check((bool)dn_row($db,"SELECT ip FROM nxt_world_ip_bans WHERE ip='2001:db8::1'"),'IPv6 stored canonically');wc_run($db,'ip unban 2001:db8::1');
    foreach(['help','help account','status','online','account list World','account info WorldTest','account unlock WorldTest','banlist','lookup species agumon','lookup items capsule','digimon list WorldTest','digimon info WorldTest '.$sole,'party WorldTest','inventory WorldTest','scan list WorldTest','maintenance','bots status','audit 20 WorldTest','logs 10','ip list'] as $command)wc_run($db,$command);
    wc_run($db,"account info \"' OR 1=1 --\"",false);wc_run($db,'givemoney WorldTest -1',false);wc_run($db,'givemoney WorldTest 100000000000',false);wc_run($db,'unknown secret-must-not-log',false);wc_run($db,"help\nstatus",false);
    $audit=json_encode(dn_rows($db,'SELECT * FROM nxt_world_admin_audit'));wc_check(!str_contains($audit,$secret)&&!str_contains($audit,'New_fixture_123!')&&!str_contains($audit,'inlineSecret_should_not_log')&&!str_contains($audit,'secret-must-not-log'),'successful and failed audits never store passwords or raw rejected lines');
    wc_check((int)dn_row($db,'SELECT COUNT(*) n FROM nxt_world_admin_audit WHERE success=0')['n']>10,'failed mutations and validation are audited');
    $before=(int)dn_row($db,'SELECT COUNT(*) n FROM members')['n'];dn_apply_schema_migrations($db);wc_check((int)dn_row($db,'SELECT COUNT(*) n FROM members')['n']===$before,'repeat repair preserves console-created accounts');
    echo json_encode(['ok'=>true,'checks'=>$checks,'commands'=>count(dn_world_commands())],JSON_PRETTY_PRINT).PHP_EOL;
}finally{$db->query('DROP DATABASE `'.$name.'`');}
