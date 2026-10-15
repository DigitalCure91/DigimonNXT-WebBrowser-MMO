<?php
/** Session-owned roaming encounters. The client submits only an opaque spawn ID. */
declare(strict_types=1);
require_once __DIR__.'/world_maps.php';

const DN_OVERWORLD_SPAWNS = 8;
const DN_OVERWORLD_RANGE = 2;
const DN_OVERWORLD_RESPAWN = 35;

/** Keep the original 32 native-pixel approach distance on fine Dawn collision grids. */
function dn_overworld_range(array $area): int {return max(DN_OVERWORLD_RANGE,(int)ceil(32/max(1,(int)($area['tile_size']??16))));}

/** Integer queue and byte raster keep fine-grid flood fills bounded in memory. */
function dn_overworld_reachable(array $area,array $blocks,int $x,int $y): array {
    $columns=(int)$area['columns'];$rows=(int)$area['rows'];
    if($x<1||$x>$columns||$y<1||$y>$rows||isset($blocks[$x.':'.$y]))return [];
    $floor=str_repeat('1',$columns*$rows);
    foreach($blocks as $key=>$_){[$bx,$by]=array_map('intval',explode(':',(string)$key));if($bx>=1&&$bx<=$columns&&$by>=1&&$by<=$rows)$floor[($by-1)*$columns+$bx-1]='0';}
    foreach((array)($area['transitions']??[]) as $gate){$gx=(int)$gate['x'];$gy=(int)$gate['y'];if($gx>=1&&$gx<=$columns&&$gy>=1&&$gy<=$rows)$floor[($gy-1)*$columns+$gx-1]='0';}
    $start=($y-1)*$columns+$x-1;$queue=[$start];$floor[$start]='0';$seen=[];$size=$columns*$rows;
    for($i=0;$i<count($queue);$i++){
        $at=$queue[$i];$cx=$at%$columns;$cy=intdiv($at,$columns);$seen[($cx+1).':'.($cy+1)]=true;
        $neighbors=[];if($cx>0)$neighbors[]=$at-1;if($cx+1<$columns)$neighbors[]=$at+1;if($at>=$columns)$neighbors[]=$at-$columns;if($at+$columns<$size)$neighbors[]=$at+$columns;
        foreach($neighbors as $next)if($floor[$next]==='1'){$floor[$next]='0';$queue[]=$next;}
    }
    return $seen;
}

/** A bounded legal path is required; proximity never permits battling through a wall. */
function dn_overworld_in_range(array $area,array $blocks,int $x,int $y,int $tx,int $ty): bool {
    $range=dn_overworld_range($area);if(max(abs($tx-$x),abs($ty-$y))>$range||pv_world_step_blocked($area,$blocks,$x,$y,$x,$y)||pv_world_step_blocked($area,$blocks,$tx,$ty,$tx,$ty))return false;
    $queue=[[$x,$y,0]];$seen=[$x.':'.$y=>true];
    for($i=0;$i<count($queue);$i++){
        [$cx,$cy,$distance]=$queue[$i];if($cx===$tx&&$cy===$ty)return true;
        if($distance>=$range)continue;
        foreach([[0,1],[1,0],[0,-1],[-1,0],[1,1],[1,-1],[-1,1],[-1,-1]] as [$dx,$dy]){
            $nx=$cx+$dx;$ny=$cy+$dy;$key=$nx.':'.$ny;
            if(isset($seen[$key])||pv_world_step_blocked($area,$blocks,$cx,$cy,$nx,$ny)||pv_world_transition($area,$nx,$ny))continue;
            $seen[$key]=true;$queue[]=[$nx,$ny,$distance+1];
        }
    }
    return false;
}

/** Sprite spacing uses pixels, independent of the fine collision cell size. */
function dn_overworld_space_clear(array $area,int $x,int $y,array $occupied,?string $ignore=null): bool {
    $gap=max(1,(int)ceil(48/max(1,(int)$area['display_tile_size'])));
    foreach($occupied as $cell=>$_){if($cell===$ignore)continue;[$ox,$oy]=array_map('intval',explode(':',$cell));if(max(abs($x-$ox),abs($y-$oy))<$gap)return false;}
    foreach((array)$area['transitions'] as $gate)if(max(abs($x-(int)$gate['x']),abs($y-(int)$gate['y']))<2)return false;
    foreach((array)$area['spawn_points'] as $point)if(max(abs($x-(int)$point[0]),abs($y-(int)$point[1]))<2)return false;
    return true;
}

function dn_overworld_species(mysqli $db,array $profile): ?array {
    $entry=dn_pick_wild_entry($profile);if(!$entry)return null;
    $species=dn_species_by_slug($db,(string)$entry[0]);if(!$species)return null;
    [$min,$max]=dn_wild_level_bounds((int)$entry[1],(int)$entry[2]);
    return ['species_id'=>(int)$species['id'],'slug'=>(string)$species['slug'],'name'=>(string)$species['name'],
        'stage'=>(string)$species['stage'],'sprite'=>pv_static((string)$species['sprite']),'level'=>random_int($min,$max)];
}

/** Slots and cooldowns survive refreshes and travel; only the server moves or replaces them. */
function dn_overworld_sync(mysqli $db,int $uid,array $area,int $x,int $y): array {
    $profile=pv_world_encounter_profiles()[(string)$area['encounter_profile']]??null;
    if(!is_array($profile))return [];
    if((int)($_SESSION['dn_overworld_owner']??0)!==$uid){$_SESSION['dn_overworld']=[];$_SESSION['dn_overworld_cooldowns']=[];$_SESSION['dn_overworld_owner']=$uid;}
    $key=(string)$area['key'];$now=time();$blocks=pv_world_blocks($db,'cyberspace',$key);
    $reachable=dn_overworld_reachable($area,$blocks,$x,$y);$cells=[];
    foreach(pv_world_encounter_cells($area) as $cell=>$_){
        [$cx,$cy]=array_map('intval',explode(':',$cell));
        if(isset($reachable[$cell])&&!isset($blocks[$cell])&&!pv_world_transition($area,$cx,$cy))$cells[$cell]=[$cx,$cy];
    }
    if(!$cells)return [];
    $revision=(string)($area['revision']??'1');
    if((string)($_SESSION['dn_overworld'][$key]['revision']??'')!==$revision)unset($_SESSION['dn_overworld'][$key]);
    foreach((array)($_SESSION['dn_overworld_cooldowns']??[]) as $old=>$slots){$live=array_filter($slots,static fn($slot)=>(int)($slot['ready_at']??0)>$now);if($live)$_SESSION['dn_overworld_cooldowns'][$old]=$live;else unset($_SESSION['dn_overworld_cooldowns'][$old]);}
    if(!isset($_SESSION['dn_overworld'][$key])){$_SESSION['dn_overworld'][$key]=['slots'=>(array)($_SESSION['dn_overworld_cooldowns'][$key]??[]),'last_roam'=>$now,'revision'=>$revision];unset($_SESSION['dn_overworld_cooldowns'][$key]);}
    $_SESSION['dn_overworld'][$key]['last_visit']=$now;
    // A long atlas session cannot accumulate hundreds of encounter snapshots.
    if(count($_SESSION['dn_overworld'])>24){uasort($_SESSION['dn_overworld'],static fn($a,$b)=>(int)($a['last_visit']??0)<=>(int)($b['last_visit']??0));while(count($_SESSION['dn_overworld'])>24){$old=(string)array_key_first($_SESSION['dn_overworld']);if($old===$key)break;$cooldowns=[];foreach((array)($_SESSION['dn_overworld'][$old]['slots']??[]) as $index=>$slot)if(empty($slot['spawn'])&&(int)($slot['ready_at']??0)>$now)$cooldowns[$index]=['ready_at'=>(int)$slot['ready_at']];if($cooldowns)$_SESSION['dn_overworld_cooldowns'][$old]=$cooldowns;unset($_SESSION['dn_overworld'][$old]);}}
    $state=&$_SESSION['dn_overworld'][$key];$occupied=[];
    foreach($state['slots'] as $index=>$slot)if(!empty($slot['spawn'])){
        $cell=$slot['spawn']['x'].':'.$slot['spawn']['y'];
        if(!isset($reachable[$cell])){$state['slots'][$index]=['ready_at'=>0];continue;}
        $occupied[$cell]=true;
    }
    $roam=$now-(int)$state['last_roam']>=5;
    for($i=0;$i<DN_OVERWORLD_SPAWNS;$i++){
        $slot=$state['slots'][$i]??['ready_at'=>0];$spawn=$slot['spawn']??null;
        if(is_array($spawn)){
            // Expired IDs are retired before a replacement receives a different random token.
            if((int)$spawn['expires_at']<=$now){unset($occupied[$spawn['x'].':'.$spawn['y']]);$state['slots'][$i]=['ready_at'=>$now+DN_OVERWORLD_RESPAWN];continue;}
            if($roam&&random_int(0,2)>0){
                $dx=random_int(-1,1);$dy=random_int(-1,1);$nx=(int)$spawn['x']+$dx;$ny=(int)$spawn['y']+$dy;$cell=$nx.':'.$ny;
                if(isset($reachable[$cell])&&!isset($occupied[$cell])&&dn_overworld_space_clear($area,$nx,$ny,$occupied,$spawn['x'].':'.$spawn['y'])&&max(abs($nx-$x),abs($ny-$y))>1&&!pv_world_step_blocked($area,$blocks,(int)$spawn['x'],(int)$spawn['y'],$nx,$ny)){
                    unset($occupied[$spawn['x'].':'.$spawn['y']]);$spawn['x']=$nx;$spawn['y']=$ny;$occupied[$cell]=true;$state['slots'][$i]['spawn']=$spawn;
                }
            }
            continue;
        }
        if((int)($slot['ready_at']??0)>$now)continue;
        $free=array_diff_key($cells,$occupied);$free=array_filter($free,fn($point)=>dn_overworld_space_clear($area,$point[0],$point[1],$occupied)&&max(abs($point[0]-$x),abs($point[1]-$y))>1);if(!$free)continue;
        // Prefer nearby visible ground for the first few signals without changing the species pool.
        if($i<3){$nearRange=max(8,(int)ceil(128/max(1,(int)$area['tile_size'])));$near=array_filter($free,fn($p)=>max(abs($p[0]-$x),abs($p[1]-$y))<=$nearRange);if($near)$free=$near;}
        [$sx,$sy]=$free[array_rand($free)];$primary=dn_overworld_species($db,$profile);if(!$primary)continue;
        $enemies=[['species_id'=>$primary['species_id'],'level'=>$primary['level']]];
        for($j=1;$j<3;$j++){$other=dn_overworld_species($db,$profile)??$primary;$enemies[]=['species_id'=>$other['species_id'],'level'=>$other['level']];}
        $spawn=$primary+['id'=>bin2hex(random_bytes(16)),'x'=>$sx,'y'=>$sy,'created_at'=>$now,'expires_at'=>$now+600,'enemies'=>$enemies];
        $state['slots'][$i]=['spawn'=>$spawn,'ready_at'=>0];$occupied[$sx.':'.$sy]=true;
    }
    if($roam)$state['last_roam']=$now;
    $out=[];
    foreach($state['slots'] as $slot){if(empty($slot['spawn']))continue;$spawn=$slot['spawn'];
        $out[]=['id'=>$spawn['id'],'x'=>$spawn['x'],'y'=>$spawn['y'],'name'=>$spawn['name'],'slug'=>$spawn['slug'],'level'=>$spawn['level'],
            'stage'=>$spawn['stage'],'sprite'=>$spawn['sprite'],'nearby'=>dn_overworld_in_range($area,$blocks,$x,$y,(int)$spawn['x'],(int)$spawn['y']),'count'=>count($spawn['enemies'])];
    }
    return $out;
}

function dn_overworld_claim(mysqli $db,int $uid,array $area,string $spawnId): array {
    if(!preg_match('/^[a-f0-9]{32}$/',$spawnId))throw new RuntimeException('Choose a roaming Digimon on your current map.');
    if((int)($_SESSION['dn_overworld_owner']??0)!==$uid)throw new RuntimeException('Your scanner needs to reconnect. Refresh the sector.');
    $key=(string)$area['key'];
    if((string)($_SESSION['world_area']??'')!==$key)throw new RuntimeException('This signal is in another sector.');
    [$x,$y]=pv_world_position($db,$uid,'cyberspace',$key,(array)$area['spawn_points']);
    $pending=$_SESSION['dn_pending_encounter']??[];
    // A dropped response can be retried; it does not create an additional encounter.
    if(($pending['spawn_id']??'')===$spawnId&&($pending['area']??'')===$key&&(int)($pending['created_at']??0)>time()-120&&(int)($pending['x']??0)===$x&&(int)($pending['y']??0)===$y)return $pending;
    foreach((array)($_SESSION['dn_overworld'][$key]['slots']??[]) as $i=>$slot){
        $spawn=$slot['spawn']??null;if(!is_array($spawn)||!hash_equals((string)$spawn['id'],$spawnId))continue;
        if((int)$spawn['expires_at']<=time())throw new RuntimeException('That Digimon has moved on. Choose another signal.');
        $blocks=pv_world_blocks($db,'cyberspace',$key);
        if(!dn_overworld_in_range($area,$blocks,$x,$y,(int)$spawn['x'],(int)$spawn['y']))throw new RuntimeException('Move closer along clear ground, then choose Battle.');
        $encounter=['token'=>bin2hex(random_bytes(24)),'species_id'=>$spawn['species_id'],'slug'=>$spawn['slug'],'name'=>$spawn['name'],
            'level'=>$spawn['level'],'world'=>'cyberspace','area'=>$key,'x'=>$x,'y'=>$y,'spawn_x'=>$spawn['x'],'spawn_y'=>$spawn['y'],
            'spawn_id'=>$spawnId,'created_at'=>time(),'enemies'=>$spawn['enemies']];
        $_SESSION['dn_pending_encounter']=$encounter;
        $_SESSION['dn_overworld'][$key]['slots'][$i]=['ready_at'=>time()+DN_OVERWORLD_RESPAWN];
        return $encounter;
    }
    throw new RuntimeException('That signal has already been claimed. Choose another roaming Digimon.');
}
