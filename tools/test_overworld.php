<?php
/** Isolated integration checks for the visible encounter authority boundary. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../public_html/includes/overworld_encounters.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
function ow_expect(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);}
function ow_reject(callable $fn,string $label): void {try{$fn();}catch(RuntimeException){return;}throw new RuntimeException('FAIL: accepted '.$label);}
$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);
$database='digimon_overworld_test_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$database.'`');$db->select_db($database);$db->set_charset('utf8mb4');
try{
    $db->query('CREATE TABLE digimon_species(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(100) UNIQUE,name VARCHAR(100),stage VARCHAR(40),sprite VARCHAR(200))');
    $db->query('CREATE TABLE world_map_positions(user_id INT,world_key VARCHAR(64),area_key VARCHAR(64),x INT,y INT,PRIMARY KEY(user_id,world_key,area_key))');
    $db->query('CREATE TABLE world_map_blocks(world_key VARCHAR(64),area_key VARCHAR(64),xblock INT,yblock INT)');
    foreach(dn_catalog() as $slug=>$species)dn_write($db,'INSERT INTO digimon_species(slug,name,stage,sprite) VALUES(?,?,?,?)',[$slug,$species['name'],$species['stage'],'images/digimon/'.$slug.'.png']);
    $uid=41;$_SESSION['myid']=$uid;$_SESSION['dn_overworld']=[];$_SESSION['dn_overworld_owner']=$uid;
    $move=function(string $area,int $x,int $y)use($db,$uid):void{$_SESSION['world_area']=$area;dn_write($db,'INSERT INTO world_map_positions(user_id,world_key,area_key,x,y) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE x=VALUES(x),y=VALUES(y)',[$uid,'cyberspace',$area,$x,$y]);};
    $tiny=['columns'=>5,'rows'=>5,'transitions'=>[]];
    ow_expect(dn_overworld_in_range($tiny,[],1,1,3,3),'two-step diagonal path');
    ow_expect(!dn_overworld_in_range($tiny,[],1,1,4,1),'range boundary');
    ow_expect(!dn_overworld_in_range($tiny,['2:1'=>true,'1:2'=>true],1,1,2,2),'no attack through diagonal wall');
    $areas=pv_world_ready_areas();ow_expect(count($areas)===264,'all Dawn maps available');
    // The exhaustive map/landing sweep is test_dawn_world.php; this suite targets token authority.
    $area=pv_world_area('cyberspace','packet-meadow');ow_expect(is_array($area),'legacy route resolves to a Dawn map');
    $key=$area['key'];[$x,$y]=$area['spawn_points'][0];$move($key,$x,$y);
    $wild=dn_overworld_sync($db,$uid,$area,$x,$y);ow_expect(count($wild)>1,'multiple visible squads on the selected route');
    $initial=count($wild);$blocks=pv_world_blocks($db,'cyberspace',$key);$reachable=dn_overworld_reachable($area,$blocks,$x,$y);$coords=[];
    foreach($wild as $signal){ow_expect(isset($reachable[$signal['x'].':'.$signal['y']]),'reachable signal');ow_expect(!isset($coords[$signal['x'].':'.$signal['y']]),'distinct positions');$coords[$signal['x'].':'.$signal['y']]=true;ow_expect($signal['count']===3,'three opponents per squad');}
    $again=dn_overworld_sync($db,$uid,$area,$x,$y);ow_expect(array_column($wild,'id')===array_column($again,'id'),'refresh preserves spawn IDs');
    $spawn=$_SESSION['dn_overworld'][$key]['slots'][0]['spawn'];$id=$spawn['id'];
    $move('dawn-264',19,23);ow_reject(fn()=>dn_overworld_claim($db,$uid,$area,$id),'cross-sector claim');
    $far=null;foreach($reachable as $cell=>$_){[$fx,$fy]=array_map('intval',explode(':',$cell));if(max(abs($fx-$spawn['x']),abs($fy-$spawn['y']))>dn_overworld_range($area)){$far=[$fx,$fy];break;}}ow_expect($far!==null,'route contains a distant legal position');$move($key,$far[0],$far[1]);ow_reject(fn()=>dn_overworld_claim($db,$uid,$area,$id),'distant claim');
    $move($key,$spawn['x'],$spawn['y']);$_SESSION['dn_overworld'][$key]['slots'][0]['spawn']['expires_at']=time()-1;
    ow_reject(fn()=>dn_overworld_claim($db,$uid,$area,$id),'expired spawn');
    $_SESSION['dn_overworld'][$key]['slots'][0]['spawn']['expires_at']=time()+600;
    ow_reject(fn()=>dn_overworld_claim($db,$uid+1,$area,$id),'another owner');
    ow_reject(fn()=>dn_overworld_claim($db,$uid,$area,'../../invalid'),'invalid ID');
    $pending=dn_overworld_claim($db,$uid,$area,$id);ow_expect($pending['x']===$spawn['x']&&$pending['y']===$spawn['y'],'pending player coordinates');
    ow_expect(count($pending['enemies'])===3,'full pending squad');
    ow_expect(dn_overworld_claim($db,$uid,$area,$id)['token']===$pending['token'],'retry reuses exact token');
    unset($_SESSION['dn_pending_encounter']);ow_reject(fn()=>dn_overworld_claim($db,$uid,$area,$id),'consumed spawn');
    $sync=dn_overworld_sync($db,$uid,$area,$spawn['x'],$spawn['y']);ow_expect(count($sync)===$initial-1,'cooldown survives refresh');
    $_SESSION['dn_overworld'][$key]['slots'][0]['ready_at']=time()-1;
    $sync=dn_overworld_sync($db,$uid,$area,$spawn['x'],$spawn['y']);ow_expect(count($sync)===$initial,'cooldown replenishes slot');
    ow_expect(!in_array($id,array_column($sync,'id'),true),'new spawn gets new ID');
    $before=array_column($sync,null,'id');$moved=false;
    for($i=0;$i<15;$i++){
        $_SESSION['dn_overworld'][$key]['last_roam']=time()-6;$sync=dn_overworld_sync($db,$uid,$area,$spawn['x'],$spawn['y']);$occupied=[];
        foreach($sync as $signal){$cell=$signal['x'].':'.$signal['y'];ow_expect(!isset($occupied[$cell]),'roaming collision between signals');$occupied[$cell]=true;ow_expect(!isset(pv_world_blocks($db,'cyberspace',$key)[$cell]),'roaming map collision');if(($before[$signal['id']]['x']??0)!==$signal['x']||($before[$signal['id']]['y']??0)!==$signal['y'])$moved=true;}
    }
    ow_expect($moved,'signals actually roam');
    echo "PASS: Dawn route signals, stable IDs, roaming, collision/range/owner/area checks, expiry, replay and respawn cooldown. See test_dawn_world.php for all 264 areas.\n";
}finally{$db->query('DROP DATABASE `'.$database.'`');}
