<?php
/** Exhaustive Dawn runtime checks in a new, disposable database. Never uses the installed database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../public_html/includes/schema.php';
require_once __DIR__.'/../public_html/includes/overworld_encounters.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
set_error_handler(static function(int $n,string $s,string $f,int $l): never { throw new ErrorException($s,0,$n,$f,$l); });
$checks=0;
function dawn_check(bool $ok,string $label): void { global $checks; $checks++; if (!$ok) throw new RuntimeException('FAIL: '.$label); }
function dawn_reject(callable $fn,string $label): void { try { $fn(); } catch (RuntimeException) { dawn_check(true,$label); return; } throw new RuntimeException('FAIL: accepted '.$label); }
function dawn_position(mysqli $db,int $uid,string $area,int $x,int $y): void {
    $_SESSION['world_area']=$area;
    dn_write($db,'INSERT INTO world_map_positions(user_id,world_key,area_key,x,y,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE x=VALUES(x),y=VALUES(y),updated_at=VALUES(updated_at)',[$uid,'cyberspace',$area,$x,$y,time()]);
}
$cfg=pv_config('db');
$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);
$db->set_charset('utf8mb4');
$database='dn_dawn_test_'.bin2hex(random_bytes(5));
$db->query('CREATE DATABASE `'.$database.'`');$db->select_db($database);$GLOBALS['mysql_connection']=$db;
$started=microtime(true);$summary=['maps'=>0,'landings'=>0,'safe_landings'=>0,'battle_landings'=>0,'squads'=>0,'bot_visits'=>0,'gates'=>0,'max_sync_ms'=>0];
try {
    dn_apply_schema_migrations($db);
    $name='DawnFixture_'.bin2hex(random_bytes(4));
    dn_write($db,'INSERT INTO members(username,password,email,registered,money) VALUES(?,?,?,?,?)',[$name,pv_password_hash('FixtureOnly123!'),$name.'@example.invalid',time(),54321]);$uid=(int)$db->insert_id;
    $bot=(int)dn_row($db,'SELECT owner_id FROM nxt_bots ORDER BY bot_no LIMIT 1')['owner_id'];
    $_SESSION=['myid'=>$uid,'myuser'=>$name,'world_key'=>'cyberspace','dn_overworld_owner'=>$uid,'map_preferences'=>[0,1,1]];
    $areas=pv_world_ready_areas();dawn_check(count($areas)===264,'264 areas load through production runtime');
    $species=dn_species_by_slug($db,'agumon');$partner=dn_create_instance($db,$uid,(int)$species['id'],'test',15);dn_assign_party_slot($db,$uid,1,$partner);
    $savedMember=dn_row($db,'SELECT * FROM members WHERE id=?',[$uid]);$savedPartner=dn_owned_instance($db,$uid,$partner);
    $gateFixture=null;$wildFixture=null;
    foreach ($areas as $key=>$area) {
        $summary['maps']++;
        dawn_check($area['tile_size']===4&&$area['display_tile_size']===8,'native 2x scale '.$key);
        dawn_check($area['width']===$area['columns']*8&&$area['height']===$area['rows']*8,'native canvas '.$key);
        $collision=json_decode((string)file_get_contents($area['collision_asset_abs']),true,512,JSON_THROW_ON_ERROR);
        $blocks=pv_world_blocks($db,'cyberspace',$key);
        // Every runtime cell must agree with the imported raster, including wall and void cells.
        foreach ($collision['walkable_rows'] as $y=>$row) for ($x=0;$x<strlen($row);$x++) {
            dawn_check(isset($blocks[($x+1).':'.($y+1)])===($row[$x]==='0'),'runtime collision '.$key.' '.($x+1).':'.($y+1));
        }
        dawn_check(pv_world_step_blocked($area,$blocks,1,1,0,1)&&pv_world_step_blocked($area,$blocks,1,1,1,0)&&pv_world_step_blocked($area,$blocks,1,1,$area['columns']+1,1)&&pv_world_step_blocked($area,$blocks,1,1,1,$area['rows']+1),'all four outer bounds '.$key);
        dawn_check(pv_world_landing($area,$blocks,0)===null&&pv_world_landing($area,$blocks,999999)===null,'unknown landing rejected '.$key);
        foreach ($area['landing_points'] as $landing) {
            $summary['landings']++;$x=(int)$landing['x'];$y=(int)$landing['y'];
            $resolved=pv_world_landing($area,$blocks,(int)$landing['id']);
            dawn_check($resolved!==null&&$resolved['x']===$x&&$resolved['y']===$y,'curated landing '.$key);
            dawn_check((int)$collision['component_rows'][$y-1][$x-1]===(int)$landing['component'],'landing matches floor component '.$key);
            dawn_position($db,$uid,$key,$x,$y);unset($_SESSION['dn_overworld'][$key]);
            dawn_check(pv_world_position($db,$uid,'cyberspace',$key,$area['spawn_points'])===[$x,$y],'saved landing retained '.$key);
            $time=microtime(true);$wild=dn_overworld_sync($db,$uid,$area,$x,$y);$summary['max_sync_ms']=max($summary['max_sync_ms'],round((microtime(true)-$time)*1000,2));
            if ($area['encounter_profile']==='') { $summary['safe_landings']++; dawn_check($wild===[],'safe hub has no wild squads '.$key); continue; }
            $summary['battle_landings']++;dawn_check(count($wild)>0&&count($wild)<=DN_OVERWORLD_SPAWNS,'battle landing has reachable squads '.$key.' #'.$landing['id']);
            $summary['squads']+=count($wild);$seen=[];$reachable=dn_overworld_reachable($area,$blocks,$x,$y);
            foreach ($wild as $signal) {
                $cell=$signal['x'].':'.$signal['y'];
                dawn_check(isset($reachable[$cell])&&!isset($blocks[$cell])&&!pv_world_transition($area,(int)$signal['x'],(int)$signal['y']),'squad is reachable and off gates '.$key);
                dawn_check(!isset($seen[$cell]),'distinct squad position '.$key);$seen[$cell]=true;
                dawn_check($signal['count']===3&&$signal['level']>=1&&$signal['level']<=24,'real three-enemy encounter '.$key);
                dawn_check((int)$collision['component_rows'][$signal['y']-1][$signal['x']-1]===(int)$landing['component'],'no signal on disconnected island '.$key);
            }
            $again=dn_overworld_sync($db,$uid,$area,$x,$y);
            dawn_check(array_column($wild,'id')===array_column($again,'id'),'refresh preserves signal identity '.$key);
            if ($wildFixture===null&&count($wild)>=3) $wildFixture=$key;
        }
        foreach ($area['transitions'] as $gate) {
            $summary['gates']++;$target=$areas[$gate['target_area']]??null;
            dawn_check($target!==null&&!isset($blocks[$gate['x'].':'.$gate['y']]),'gate source available '.$key);
            $targetBlocks=pv_world_blocks($db,'cyberspace',$target['key']);
            dawn_check(!pv_world_step_blocked($target,$targetBlocks,$gate['target_x'],$gate['target_y'],$gate['target_x'],$gate['target_y']),'gate arrival available '.$key);
            if ($gateFixture===null) foreach ([5=>[-1,-1],6=>[-1,1],7=>[1,-1],8=>[1,1]] as $direction=>[$dx,$dy]) {
                $fromX=$gate['x']-$dx;$fromY=$gate['y']-$dy;
                if (!pv_world_step_blocked($area,$blocks,$fromX,$fromY,$fromX,$fromY)&&!pv_world_step_blocked($area,$blocks,$fromX,$fromY,$gate['x'],$gate['y'])) {$gateFixture=[$area,$gate,$direction,$fromX,$fromY,$gate['x'],$fromY];break;}
            }
        }
        dn_bot_visit($db,$bot,$key);$summary['bot_visits']++;
        $presence=pv_world_players($db,$uid,'cyberspace',$key);$visible=array_values(array_filter($presence,fn($p)=>(int)$p['id']===$bot));
        dawn_check(count($visible)===1&&!empty($visible[0]['is_bot']),'real bot presence on '.$key);
        dawn_check(!isset($blocks[$visible[0]['x'].':'.$visible[0]['y']]),'bot stands on legal ground '.$key);
    }
    dawn_check($summary['landings']===481,'every imported walkable component has a tested entry');
    // Existing bookmarks map to safe Dawn entries without rewriting or deleting old save records.
    $aliases=(array)pv_world_manifest()['legacy_aliases'];dawn_check(count($aliases)===11,'all 11 legacy bookmarks have a destination');
    foreach ($aliases as $old=>$new) {
        dn_write($db,'INSERT INTO world_map_positions(user_id,world_key,area_key,x,y,updated_at) VALUES(?,?,?,?,?,?)',[$uid,'cyberspace',$old,19,18,123]);
        $before=dn_row($db,'SELECT * FROM world_map_positions WHERE user_id=? AND area_key=?',[$uid,$old]);
        $area=pv_world_area('cyberspace',$old);dawn_check($area!==null&&$area['key']===$new,'legacy bookmark resolves '.$old);
        [$x,$y]=pv_world_position($db,$uid,'cyberspace',$old,$area['spawn_points']);
        dawn_check(!pv_world_step_blocked($area,pv_world_blocks($db,'cyberspace',$new),$x,$y,$x,$y),'legacy bookmark enters safe floor '.$old);
        dawn_check(dn_row($db,'SELECT * FROM world_map_positions WHERE user_id=? AND area_key=?',[$uid,$old])===$before,'legacy coordinate row preserved '.$old);
    }
    dawn_check(dn_row($db,'SELECT * FROM members WHERE id=?',[$uid])===$savedMember&&dn_owned_instance($db,$uid,$partner)===$savedPartner,'map migration preserves account, wallet and partner');
    $area=$areas[$wildFixture];$key=$area['key'];[$sx,$sy]=$area['spawn_points'][0];
    // Corrupt/outdated coordinates recover deterministically and discard their stale signal token.
    dawn_position($db,$uid,$key,-100,999999);$_SESSION['dn_pending_encounter']=['token'=>'stale'];
    $repaired=pv_world_position($db,$uid,'cyberspace',$key,$area['spawn_points']);
    dawn_check($repaired===$area['spawn_points'][0]&&!isset($_SESSION['dn_pending_encounter']),'invalid saved location safely repaired');
    // Operator blocks override ROM floor, including an otherwise valid landing.
    dn_write($db,'INSERT INTO world_map_blocks(world_key,area_key,xblock,yblock) VALUES(?,?,?,?)',['cyberspace',$key,$sx,$sy]);
    $overlay=pv_world_blocks($db,'cyberspace',$key);dawn_check(isset($overlay[$sx.':'.$sy]),'live admin collision overlay applied');
    dawn_check(pv_world_landing($area,$overlay,(int)$area['landing_points'][0]['id'])===null,'blocked curated entry rejected');
    $safe=pv_world_position($db,$uid,'cyberspace',$key,$area['spawn_points']);dawn_check($safe!==[$sx,$sy]&&!isset($overlay[$safe[0].':'.$safe[1]]),'blocked saved spawn repaired to safe ground');
    dn_write($db,'DELETE FROM world_map_blocks WHERE area_key=?',[$key]);
    // A diagonal step into a gate must obey the source-side corner blockers.
    dawn_check($gateFixture!==null,'a real diagonal gate approach is available for regression');
    [$ga,$gate,$direction,$fromX,$fromY,$wallX,$wallY]=$gateFixture;
    dn_write($db,'INSERT INTO world_map_blocks(world_key,area_key,xblock,yblock) VALUES(?,?,?,?)',['cyberspace',$ga['key'],$wallX,$wallY]);
    dawn_check(in_array($direction,pv_world_blocked_directions($db,$ga,$fromX,$fromY),true),'diagonal portal corner cutting rejected');
    dn_write($db,'DELETE FROM world_map_blocks WHERE area_key=?',[$ga['key']]);
    // Actual map signal -> persistent three-versus-three battle -> scan and restoration.
    dawn_position($db,$uid,$key,$sx,$sy);unset($_SESSION['dn_overworld'][$key]);$wild=dn_overworld_sync($db,$uid,$area,$sx,$sy);$signal=$wild[0];
    dawn_position($db,$uid,$key,(int)$signal['x'],(int)$signal['y']);
    $pending=dn_overworld_claim($db,$uid,$area,$signal['id']);$battle=dn_combat_start($db,$uid,$pending,$pending['token']);
    dawn_check(count($battle['team']['right'])===3&&!empty($battle['scan_rewards']),'clicked Dawn signal produces real battle and scan');
    dawn_reject(fn()=>dn_require_idle($db,$uid),'landing or travel while battling');
    dn_write($db,'UPDATE digimon_instances SET hp=1,sp=0 WHERE id=?',[$partner]);
    $battle=dn_combat_action($db,$uid,$battle['token'],'flee');$restored=dn_owned_instance($db,$uid,$partner);
    dawn_check($battle['finished']&&$battle['restored']&&(int)$restored['hp']===(int)$restored['max_hp']&&(int)$restored['sp']===(int)$restored['max_sp'],'map battle settles with full restoration');
    dawn_reject(fn()=>dn_combat_start($db,$uid,$pending,$pending['token']),'map signal settlement replay');
    $cooldown=(int)$_SESSION['dn_overworld'][$key]['slots'][0]['ready_at'];$visited=0;
    foreach($areas as $otherKey=>$otherArea){if($otherKey===$key||$otherArea['encounter_profile']==='')continue;[$ox,$oy]=$otherArea['spawn_points'][0];dn_overworld_sync($db,$uid,$otherArea,$ox,$oy);if(++$visited>=28)break;}
    dawn_check(!isset($_SESSION['dn_overworld'][$key])&&((int)($_SESSION['dn_overworld_cooldowns'][$key][0]['ready_at']??0))===$cooldown,'evicted map retains consumed-signal cooldown');
    dn_overworld_sync($db,$uid,$area,(int)$signal['x'],(int)$signal['y']);
    dawn_check(empty($_SESSION['dn_overworld'][$key]['slots'][0]['spawn'])&&(int)$_SESSION['dn_overworld'][$key]['slots'][0]['ready_at']===$cooldown,'revisit cannot bypass consumed-signal cooldown');
    dawn_check(count($_SESSION['dn_overworld']??[])<=24,'map browsing keeps session snapshots bounded');
    $summary['checks']=$checks;$summary['elapsed_seconds']=round(microtime(true)-$started,2);$summary['peak_memory_mb']=round(memory_get_peak_usage(true)/1048576,2);
    echo 'DAWN WORLD INTEGRATION: PASS '.json_encode($summary,JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally { $db->select_db('information_schema');$db->query('DROP DATABASE `'.$database.'`'); }
