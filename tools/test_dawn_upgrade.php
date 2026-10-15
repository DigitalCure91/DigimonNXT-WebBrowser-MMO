<?php
/** Exact blank-password v0.6.0 console baseline -> Dawn maps; disposable database only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../public_html/includes/schema.php';
require_once __DIR__.'/../public_html/includes/world_maps.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
set_error_handler(static function(int $n,string $s,string $f,int $l):never{throw new ErrorException($s,0,$n,$f,$l);});
$checks=0;
function du_check(bool $ok,string $label):void{global $checks;$checks++;if(!$ok)throw new RuntimeException('FAIL: '.$label);}
function du_snapshot(mysqli $db):array{
    $out=[];
    foreach(dn_rows($db,'SHOW TABLES') as $row){$table=(string)array_values($row)[0];
        // Species/item/event/catalog metadata are intentionally repaired; all account state must remain byte-identical.
        if(in_array($table,['schema_meta','digimon_species','items','events','news'],true))continue;
        $rows=dn_rows($db,'SELECT * FROM `'.$table.'`');$encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR),$rows);sort($encoded,SORT_STRING);$out[$table]=hash('sha256',implode("\n",$encoded));
    }return$out;
}
$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);$db->set_charset('utf8mb4');
$database='dn_dawn_upgrade_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$database.'`');$db->select_db($database);$GLOBALS['mysql_connection']=$db;
try{
    $fixture=__DIR__.'/fixtures/schema_v8_console.sql';
    du_check(hash_file('sha256',$fixture)==='939d0c45c70572eb378df335c4d52e94c95e61a5ea7dd53e07634ca287478032','exact prior console release SQL fixture');
    $sql=preg_replace('/^(CREATE DATABASE[^\n]*|USE `digimon_nxt`;)\r?\n/m','',(string)file_get_contents($fixture));
    $db->multi_query($sql);do{if($r=$db->store_result())$r->free();}while($db->more_results()&&$db->next_result());
    du_check((int)dn_row($db,"SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")['meta_value']===8,'prior schema revision imported');
    dn_write($db,'INSERT INTO members(username,password,email,money,totalexp,wins,losses,registered) VALUES(?,?,?,?,?,?,?,?)',['DawnUpgrade',pv_password_hash('FixtureOnly123!'),'dawn-upgrade@example.invalid',76543,123456,12,4,1700000000]);$uid=(int)$db->insert_id;
    $species=dn_species_by_slug($db,'agumon');$partner=dn_create_instance($db,$uid,(int)$species['id'],'starter',37,77);dn_assign_party_slot($db,$uid,1,$partner);
    dn_write($db,'UPDATE digimon_instances SET nickname=?,exp=321,cam=83,training_json=?,hp=71,sp=7 WHERE id=?',['Saved Partner','{"hp":4,"atk":3,"int":2}',$partner]);
    dn_write($db,'UPDATE digimon_scan SET scan_percent=185,encounters=12,wins=7 WHERE owner_id=? AND species_id=?',[$uid,(int)$species['id']]);
    dn_write($db,'INSERT INTO member_items(owner_id,item_id,quantity) VALUES(?,?,?)',[$uid,1,17]);
    dn_write($db,'INSERT INTO event_progress(owner_id,event_id,progress,completed,updated_at) VALUES(?,1,70,0,123)',[$uid]);
    dn_write($db,'INSERT INTO world_map_positions(user_id,world_key,area_key,x,y,updated_at) VALUES(?,?,?,?,?,?)',[$uid,'cyberspace','packet-meadow',19,18,123]);
    dn_write($db,'INSERT INTO mapusers(id,username,tamer,world_key,map,x,y,time) VALUES(?,?,?,?,?,?,?,CURTIME())',[$uid,'DawnUpgrade',1,'cyberspace','packet-meadow',19,18]);
    dn_write($db,'INSERT INTO nxt_world_settings(setting_key,value_json,updated_at) VALUES(?,?,?)',['upgrade.fixture','{"kept":true}',123]);
    $before=du_snapshot($db);dn_apply_schema_migrations($db);
    du_check(du_snapshot($db)===$before,'all account, bot, ranking, console and progress tables preserved through upgrade');
    dn_apply_schema_migrations($db);du_check(du_snapshot($db)===$before,'repeat repair preserves every account table');
    $_SESSION=['myid'=>$uid,'myuser'=>'DawnUpgrade','world_key'=>'cyberspace','world_area'=>'packet-meadow','map_preferences'=>[0,1,1]];
    $area=pv_world_area('cyberspace','packet-meadow');[$x,$y]=pv_world_position($db,$uid,'cyberspace','packet-meadow',$area['spawn_points']);
    pv_world_presence_upsert($db,$uid,'cyberspace',$area['key'],$x,$y);
    du_check($_SESSION['world_area']===$area['key']&&str_starts_with($area['key'],'dawn-'),'legacy session enters canonical Dawn map');
    du_check(!pv_world_step_blocked($area,pv_world_blocks($db,'cyberspace',$area['key']),$x,$y,$x,$y),'legacy entry lands on valid Dawn floor');
    du_check(dn_row($db,'SELECT x,y,updated_at FROM world_map_positions WHERE user_id=? AND area_key=?',[$uid,'packet-meadow'])===['x'=>19,'y'=>18,'updated_at'=>123],'old location record remains preserved');
    $now=du_snapshot($db);foreach($before as $table=>$hash)if(!in_array($table,['mapusers','world_map_positions','online'],true))du_check($now[$table]===$hash,'first map visit leaves '.$table.' unchanged');
    // Event names update without changing IDs/progress, and wins in all three new groupings still advance them.
    $expected=['trails'=>1,'frozen'=>2,'deep'=>3];
    foreach($expected as $group=>$eventId){$target=null;foreach(pv_world_ready_areas() as $candidate)if($candidate['event_group']===$group){$target=$candidate;break;}du_check($target!==null,'Dawn event group '.$group.' has playable maps');
        $prior=(int)(dn_row($db,'SELECT progress FROM event_progress WHERE owner_id=? AND event_id=?',[$uid,$eventId])['progress']??0);
        dn_record_battle($db,$uid,$partner,(int)$species['id'],'wild','win',$target['key'],100,10,20);
        du_check((int)dn_row($db,'SELECT progress FROM event_progress WHERE owner_id=? AND event_id=?',[$uid,$eventId])['progress']===min(100,$prior+10),'wild win advances preserved '.$group.' event');
    }
    echo 'DAWN V0.6.0 UPGRADE: PASS '.json_encode(['checks'=>$checks,'preserved_tables'=>count($before),'legacy_alias'=>$area['key'],'event_groups'=>3],JSON_UNESCAPED_SLASHES).PHP_EOL;
}finally{$db->select_db('information_schema');$db->query('DROP DATABASE `'.$database.'`');}
