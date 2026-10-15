<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/digimon.php';
require_once __DIR__.'/presentation.php';

function pv_world_normalize_key(string $world): string {
    $world=strtolower(trim($world));
    return $world==='cyberspace'?'cyberspace':'cyberspace';
}
function pv_world_area_key(string $area): string {
    $area=strtolower(trim($area));
    return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/',$area)?$area:'';
}
/** Old bookmarks remain valid while new positions use the Dawn map's canonical key. */
function pv_world_resolve_area_key(string $area): string {
    $area=pv_world_area_key($area);$aliases=(array)(pv_world_manifest()['legacy_aliases']??[]);
    return isset($aliases[$area])?pv_world_area_key((string)$aliases[$area]):$area;
}
function pv_world_default_area(): string {
    $manifest=pv_world_manifest();$key=pv_world_area_key((string)($manifest['default_area']??''));
    if($key!==''&&isset($manifest['areas'][$key]))return $key;
    return (string)(array_key_first((array)$manifest['areas'])??'');
}
function pv_world_region_keys(): array { return ['cyberspace']; }
function pv_world_is_region_world(string $world): bool { return pv_world_normalize_key($world)==='cyberspace'; }
function pv_world_manifest(string $world='cyberspace'): array {
    static $cache=null;
    if(is_array($cache))return $cache;
    $file=dirname(__DIR__).'/config/worlds/cyberspace.php';
    $loaded=is_file($file)?require $file:[];
    $cache=is_array($loaded)?$loaded:[];
    if(!isset($cache['areas'])||!is_array($cache['areas']))$cache['areas']=[];
    return $cache;
}
function pv_world_catalog(): array {
    $manifest=pv_world_manifest();$ready=0;
    foreach(array_keys((array)$manifest['areas']) as $key)if(pv_world_area('cyberspace',(string)$key)!==null)$ready++;
    return ['cyberspace'=>[
        'key'=>'cyberspace','label'=>(string)($manifest['label']??'Cyberspace Network'),'subtitle'=>(string)($manifest['subtitle']??'DIGITAL WORLD // NXT LAYER'),
        'description'=>(string)($manifest['description']??'Persistent digital exploration network.'),'status'=>$ready>0?'active':'prepared',
        'area_count'=>count((array)$manifest['areas']),'ready_count'=>$ready,
    ]];
}
function pv_world_definition(string $world='cyberspace'): ?array { $c=pv_world_catalog();return$c['cyberspace']??null; }

function pv_world_area(string $world,string $area): ?array {
    $world=pv_world_normalize_key($world);$area=pv_world_resolve_area_key($area);
    static $cache=[];if(array_key_exists($area,$cache))return $cache[$area];
    if($area==='')return null;
    $manifest=pv_world_manifest($world);$raw=$manifest['areas'][$area]??null;
    if(!is_array($raw)||empty($raw['ready']))return null;
    $name=trim((string)($raw['name']??''));$category=trim((string)($raw['category']??'Sector'));
    $asset=str_replace('\\','/',ltrim((string)($raw['asset']??''),'/'));
    if($name===''||$asset===''||str_contains($asset,'..'))return null;
    $root=realpath(dirname(__DIR__).'/html/static');$assetAbs=$root?realpath($root.'/'.$asset):false;
    if(!$root||!$assetAbs||!str_starts_with($assetAbs,$root.DIRECTORY_SEPARATOR)||!is_file($assetAbs))return null;
    $tileSize=max(1,min(64,(int)($raw['tile_size']??$manifest['tile_size']??16)));
    $displayTileSize=max($tileSize,min(128,(int)($raw['display_tile_size']??$manifest['display_tile_size']??$tileSize)));
    $columns=max(0,(int)($raw['columns']??0));$rows=max(0,(int)($raw['rows']??0));$info=@getimagesize($assetAbs);
    $width=is_array($info)?(int)($info[0]??0):0;$height=is_array($info)?(int)($info[1]??0):0;
    if($columns<=0&&$width>0&&$width%$displayTileSize===0)$columns=intdiv($width,$displayTileSize);
    if($rows<=0&&$height>0&&$height%$displayTileSize===0)$rows=intdiv($height,$displayTileSize);
    if($columns<=0||$rows<=0||$width!==$columns*$displayTileSize||$height!==$rows*$displayTileSize)return null;
    $spawn=[];foreach((array)($raw['spawn_points']??[]) as $p){if(!is_array($p)||count($p)<2)continue;$x=(int)$p[0];$y=(int)$p[1];if($x>=1&&$x<=$columns&&$y>=1&&$y<=$rows)$spawn[]=[$x,$y];}
    if($spawn===[])return null;
    $collisionRel=str_replace('\\','/',ltrim(trim((string)($raw['collision_asset']??'')),'/'));$collisionAbs='';if($collisionRel==='')return null;
    if($collisionRel!==''){
        if(str_contains($collisionRel,'..'))return null;$publicRoot=realpath(dirname(__DIR__));$resolved=$publicRoot?realpath($publicRoot.'/'.$collisionRel):false;
        if(!$publicRoot||!$resolved||!str_starts_with($resolved,$publicRoot.DIRECTORY_SEPARATOR)||!is_file($resolved))return null;
        // Decode collision only for an opened map, never every atlas card.
        $collisionAbs=$resolved;
    }
    $transitions=[];foreach((array)($raw['transitions']??[]) as $t){if(!is_array($t))continue;$tx=(int)($t['x']??0);$ty=(int)($t['y']??0);$ta=pv_world_area_key((string)($t['target_area']??''));$tw='cyberspace';$targetX=(int)($t['target_x']??0);$targetY=(int)($t['target_y']??0);if($tx>=1&&$tx<=$columns&&$ty>=1&&$ty<=$rows&&$ta!==''&&$targetX>=1&&$targetY>=1)$transitions[]=['x'=>$tx,'y'=>$ty,'target_world'=>$tw,'target_area'=>$ta,'target_x'=>$targetX,'target_y'=>$targetY];}
    return $cache[$area]=['world'=>'cyberspace','key'=>$area,'name'=>$name,'category'=>$category,'description'=>(string)($raw['description']??''),'asset'=>$asset,'asset_abs'=>$assetAbs,
        'tile_size'=>$tileSize,'display_tile_size'=>$displayTileSize,'columns'=>$columns,'rows'=>$rows,'width'=>$width,'height'=>$height,'spawn_points'=>$spawn,'transitions'=>$transitions,
        'encounter_profile'=>trim((string)($raw['encounter_profile']??'')),'collision_asset'=>$collisionRel,'collision_asset_abs'=>$collisionAbs,
        'connections'=>array_values(array_filter(array_map('pv_world_resolve_area_key',(array)($raw['connections']??[])))),
        'region'=>(string)($raw['region']??$category),'region_key'=>(string)($raw['region_key']??''),'map_id'=>(int)($raw['map_id']??$raw['source_id']??0),
        'thumbnail'=>(string)($raw['thumbnail']??$asset),'music'=>(string)($raw['music']??''),'music_track'=>(string)($raw['music_track']??$raw['music']??''),'event_group'=>(string)($raw['event_group']??''),'level_min'=>(int)($raw['level_min']??0),'level_max'=>(int)($raw['level_max']??0),'encounter_species_count'=>(int)($raw['encounter_species_count']??0),'revision'=>(string)($raw['revision']??$manifest['revision']??'1'),
        'landing_points'=>(array)($raw['landing_points']??[]),'actor_width'=>max(16,min(96,(int)($raw['actor_width']??32))),'interaction_range'=>max(2,(int)ceil(32/$tileSize)),
        'components'=>(array)($raw['components']??[]),'source_layers'=>(array)($raw['source_layers']??[]),'event_area'=>(string)($raw['event_area']??'')];
}
function pv_world_ready_areas(string $world='cyberspace'): array { $out=[];foreach(array_keys((array)pv_world_manifest()['areas']) as $key){$a=pv_world_area('cyberspace',(string)$key);if($a)$out[$a['key']]=$a;}return$out; }
function pv_world_area_url(string $world,string $area): string { return pv_url('world_map.php?world=cyberspace&area='.rawurlencode(pv_world_resolve_area_key($area))); }
function pv_world_map_column_available(mysqli $db): bool {
    static $cache=[];$key=spl_object_id($db);if(array_key_exists($key,$cache))return$cache[$key];
    $stmt=$db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mapusers' AND column_name='world_key' LIMIT 1");if(!$stmt)return$cache[$key]=false;$stmt->execute();$ok=(bool)$stmt->get_result()->fetch_row();$stmt->close();return$cache[$key]=$ok;
}
function pv_world_presence_upsert(mysqli $db,int $uid,string $world,string $area,int $x,int $y): void {
    $world='cyberspace';$area=substr(pv_world_resolve_area_key($area),0,64);if($uid<=0||$area==='')throw new RuntimeException('Invalid network presence state.');
    $username=substr((string)($_SESSION['myuser']??'Tamer'),0,45);$tamer=nxt_skin_id((int)($_SESSION['map_preferences'][2]??1));
    $stmt=$db->prepare('INSERT INTO mapusers (id,username,tamer,world_key,map,x,y,time) VALUES (?,?,?,?,?,?,?,CURTIME()) ON DUPLICATE KEY UPDATE username=VALUES(username),tamer=VALUES(tamer),world_key=VALUES(world_key),map=VALUES(map),x=VALUES(x),y=VALUES(y),time=CURTIME()');
    if(!$stmt)throw new RuntimeException('Network presence is unavailable.');$stmt->bind_param('isissii',$uid,$username,$tamer,$world,$area,$x,$y);if(!$stmt->execute()){$e=$stmt->error;$stmt->close();throw new RuntimeException('Could not update network presence: '.$e);}$stmt->close();
    $now=time();$stmt=$db->prepare('INSERT INTO world_map_positions (user_id,world_key,area_key,x,y,updated_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE x=VALUES(x),y=VALUES(y),updated_at=VALUES(updated_at)');if($stmt){$stmt->bind_param('issiii',$uid,$world,$area,$x,$y,$now);$stmt->execute();$stmt->close();}
    $activity='Exploring Cyberspace / '.$area;$stmt=$db->prepare('INSERT INTO online(id,username,activity,time,useragent,server) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE username=VALUES(username),activity=VALUES(activity),time=VALUES(time),useragent=VALUES(useragent)');if($stmt){$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,250);$server='local';$stmt->bind_param('ississ',$uid,$username,$activity,$now,$ua,$server);$stmt->execute();$stmt->close();}
    $changedArea=(string)($_SESSION['world_area']??'')!==$area;
    $_SESSION['world_key']=$world;$_SESSION['world_area']=$area;$_SESSION['mapx']=$x;$_SESSION['mapy']=$y;
    if($changedArea&&$uid===(int)($_SESSION['myid']??0))dn_world_event('game','world.travel','Entered sector '.$area,['area'=>$area]);
}
/** Validate positions on every API request, including saves from an older map revision. */
function pv_world_position(mysqli $db,int $uid,string $world,string $area,array $spawnPoints): array {
    $world='cyberspace';$area=pv_world_resolve_area_key($area);$definition=pv_world_area($world,$area);
    if(!$definition)throw new RuntimeException('This destination is unavailable.');
    $blocks=pv_world_blocks($db,$world,$area);$position=null;
    $stmt=$db->prepare('SELECT x,y FROM world_map_positions WHERE user_id=? AND world_key=? AND area_key=? LIMIT 1');
    if($stmt){$stmt->bind_param('iss',$uid,$world,$area);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();if($row)$position=[(int)$row['x'],(int)$row['y']];}
    if($position===null){$stmt=$db->prepare('SELECT x,y FROM mapusers WHERE id=? AND world_key=? AND map=? LIMIT 1');if($stmt){$stmt->bind_param('iss',$uid,$world,$area);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();if($row)$position=[(int)$row['x'],(int)$row['y']];}}
    if($position!==null&&!pv_world_step_blocked($definition,$blocks,$position[0],$position[1],$position[0],$position[1]))return $position;
    $safe=pv_world_safe_spawn($definition,$blocks);
    if($position!==null){
        [$x,$y]=$safe;$now=time();
        dn_write($db,'UPDATE world_map_positions SET x=?,y=?,updated_at=? WHERE user_id=? AND world_key=? AND area_key=?',[$x,$y,$now,$uid,$world,$area]);
        dn_write($db,'UPDATE mapusers SET x=?,y=? WHERE id=? AND world_key=? AND map=?',[$x,$y,$uid,$world,$area]);
        if($uid===(int)($_SESSION['myid']??0)){unset($_SESSION['dn_pending_encounter'],$_SESSION['dn_overworld'][$area]);$_SESSION['mapx']=$x;$_SESSION['mapy']=$y;}
    }
    return $safe;
}
/** A curated entry is preferred; a nearby safe floor is used if an admin blocks it. */
function pv_world_safe_spawn(array $area,array $blocks): array {
    foreach((array)$area['spawn_points'] as [$x,$y])if(!pv_world_step_blocked($area,$blocks,$x,$y,$x,$y)&&!pv_world_transition($area,$x,$y))return [(int)$x,(int)$y];
    [$sx,$sy]=$area['spawn_points'][0];$best=null;$distance=PHP_INT_MAX;
    for($y=1;$y<=(int)$area['rows'];$y++)for($x=1;$x<=(int)$area['columns'];$x++){
        if(isset($blocks[$x.':'.$y])||pv_world_transition($area,$x,$y))continue;
        $d=abs($sx-$x)+abs($sy-$y);if($d<$distance){$best=[$x,$y];$distance=$d;}
    }
    if($best===null)throw new RuntimeException('No safe arrival point is available in this area.');
    return $best;
}
/** Never accept arbitrary coordinates or a landing supplied by a browser. */
function pv_world_landing(array $area,array $blocks,int $id): ?array {
    foreach((array)($area['landing_points']??[]) as $landing){
        if(!is_array($landing)||(int)($landing['id']??0)!==$id)continue;
        $x=(int)($landing['x']??0);$y=(int)($landing['y']??0);
        if(pv_world_step_blocked($area,$blocks,$x,$y,$x,$y)||pv_world_transition($area,$x,$y))return null;
        return ['id'=>$id,'x'=>$x,'y'=>$y,'label'=>(string)($landing['label']??'Landing '.$id),'component'=>(int)($landing['component']??0)];
    }
    return null;
}
function pv_world_players(mysqli $db,int $uid,string $world,string $area): array {
    $show=1;$stmt=$db->prepare('SELECT COALESCE(o.memonmap,m.memonmap,1) AS memonmap FROM members m LEFT JOIN members_options o ON o.id=m.id WHERE m.id=? LIMIT 1');if($stmt){$stmt->bind_param('i',$uid);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if($r)$show=(int)$r['memonmap']!==0?1:0;}if(!$show)return[];
    $area=pv_world_resolve_area_key($area);$definition=pv_world_area('cyberspace',$area);if(!$definition)return [];$blocks=pv_world_blocks($db,'cyberspace',$area);$cutoff=time()-1800;$world='cyberspace';$stmt=$db->prepare('SELECT m.id,m.username,m.tamer,m.x,m.y,IF(b.owner_id IS NULL,0,1) is_bot FROM mapusers m INNER JOIN online o ON o.id=m.id LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE m.world_key=? AND m.map=? AND m.id<>? AND CAST(o.time AS UNSIGNED)>=? ORDER BY is_bot,CAST(o.time AS UNSIGNED) DESC,m.username LIMIT 60');if(!$stmt)return[];$stmt->bind_param('ssii',$world,$area,$uid,$cutoff);$stmt->execute();$r=$stmt->get_result();$players=[];while($row=$r->fetch_assoc()){if(pv_world_step_blocked($definition,$blocks,(int)$row['x'],(int)$row['y'],(int)$row['x'],(int)$row['y']))continue;$players[]=['id'=>(int)$row['id'],'username'=>(string)$row['username'],'is_bot'=>(bool)$row['is_bot'],'tamer'=>nxt_skin_id((int)$row['tamer']),'x'=>(int)$row['x'],'y'=>(int)$row['y']];}$stmt->close();return$players;
}
/** Only a few collision rasters stay resident in a long-running tamer worker. */
function pv_world_collision_data(array $area): array {
    static $cache=[];$abs=(string)($area['collision_asset_abs']??'');if($abs==='')return [];
    if(isset($cache[$abs]))return $cache[$abs];
    $data=json_decode((string)file_get_contents($abs),true);
    if(!is_array($data)||(int)($data['columns']??0)!==(int)$area['columns']||(int)($data['rows']??0)!==(int)$area['rows'])throw new RuntimeException('Map collision data is unavailable.');
    if(isset($data['walkable_rows'])){
        if(!is_array($data['walkable_rows'])||count($data['walkable_rows'])!==(int)$area['rows'])throw new RuntimeException('Map collision data is incomplete.');
        foreach($data['walkable_rows'] as $row)if(!is_string($row)||strlen($row)!==(int)$area['columns']||strspn($row,'01')!==strlen($row))throw new RuntimeException('Map collision data is invalid.');
    }elseif(!isset($data['blocked'])||!is_array($data['blocked']))throw new RuntimeException('Map collision data is missing.');
    if(count($cache)>=4)array_shift($cache);return $cache[$abs]=$data;
}
function pv_world_blocks(mysqli $db,string $world,string $area): array {
    $area=pv_world_resolve_area_key($area);$definition=pv_world_area('cyberspace',$area);if(!$definition)throw new RuntimeException('This destination is unavailable.');
    $data=pv_world_collision_data($definition);$blocks=[];
    if(isset($data['walkable_rows'])){
        foreach($data['walkable_rows'] as $y=>$row)for($x=0,$length=strlen($row);$x<$length;$x++)if($row[$x]!=='1')$blocks[($x+1).':'.($y+1)]=true;
    }else foreach((array)($data['blocked']??[]) as $p)if(is_array($p)&&count($p)>=2)$blocks[(int)$p[0].':'.(int)$p[1]]=true;
    $world='cyberspace';$stmt=$db->prepare('SELECT xblock,yblock FROM world_map_blocks WHERE world_key=? AND area_key=?');
    if($stmt){$stmt->bind_param('ss',$world,$area);$stmt->execute();$r=$stmt->get_result();while($row=$r->fetch_assoc())$blocks[(int)$row['xblock'].':'.(int)$row['yblock']]=true;$stmt->close();}
    return $blocks;
}
function pv_world_component_at(array $area,int $x,int $y): int {
    if($x<1||$x>(int)$area['columns']||$y<1||$y>(int)$area['rows'])return 0;
    $data=pv_world_collision_data($area);$row=(string)($data['component_rows'][$y-1]??'');
    return isset($row[$x-1])?(int)$row[$x-1]:0;
}
function pv_world_encounter_cells(array $area): array {
    $data=pv_world_collision_data($area);$cells=[];
    foreach((array)($data['encounter']??[]) as $p)if(is_array($p)&&count($p)>=2)$cells[(int)$p[0].':'.(int)$p[1]]=true;
    foreach((array)($data['components']??[]) as $component)foreach((array)($component['encounter_cells']??[]) as $p)if(is_array($p)&&count($p)>=2)$cells[(int)$p[0].':'.(int)$p[1]]=true;
    if(!$cells&&isset($data['walkable_rows'])){
        // A coarse candidate lattice avoids allocating every fine collision cell as a spawn.
        $stride=max(1,(int)ceil(16/max(1,(int)$area['tile_size'])));
        foreach($data['walkable_rows'] as $y=>$row)if($y%$stride===0)for($x=0,$length=strlen($row);$x<$length;$x+=$stride)if($row[$x]==='1')$cells[($x+1).':'.($y+1)]=true;
    }
    return $cells;
}
function pv_world_step_blocked(array $area,array $blocks,int $x,int $y,int $tx,int $ty): bool {
    $columns=(int)$area['columns'];$rows=(int)$area['rows'];if($tx<1||$tx>$columns||$ty<1||$ty>$rows||isset($blocks[$tx.':'.$ty]))return true;$dx=$tx-$x;$dy=$ty-$y;
    if(abs($dx)===1&&abs($dy)===1){if(isset($blocks[($x+$dx).':'.$y])||isset($blocks[$x.':'.($y+$dy)]))return true;}return false;
}
function pv_world_connected_areas(array $area): array { $out=[];foreach((array)($area['connections']??[]) as $key){$a=pv_world_area('cyberspace',(string)$key);if($a)$out[$a['key']]=$a;}return$out; }
function pv_world_encounter_profiles(string $world='cyberspace'): array { static $cache=null;if(is_array($cache))return$cache;$file=dirname(__DIR__).'/config/worlds/cyberspace_encounters.php';$loaded=is_file($file)?require$file:[];return$cache=is_array($loaded)?$loaded:[]; }
/** Paradox weights are separate so roster expansion never dilutes their 0.2% signal rate. */
function dn_pick_wild_entry(array $profile): ?array {
    static $paradox;
    if($paradox===null)$paradox=json_decode((string)file_get_contents(dirname(__DIR__).'/config/paradox_encounters.json'),true,512,JSON_THROW_ON_ERROR);
    static $rare;if($rare===null)$rare=array_values(array_filter($paradox['entries']??[],fn($slug)=>dn_is_paradox((string)$slug)));
    if($rare&&random_int(1,1000000)<=(int)round((float)$paradox['chance_per_signal']*1000000))return [$rare[random_int(0,count($rare)-1)],(int)$paradox['min_level'],(int)$paradox['max_level'],1];
    $entries=(array)($profile['entries']??[]);$total=0;
    foreach($entries as $e)if(is_array($e)&&count($e)>=4)$total+=max(0,(int)$e[3]);
    if($total<=0)return null;$roll=random_int(1,$total);
    foreach($entries as $e){if(!is_array($e)||count($e)<4)continue;$roll-=max(0,(int)$e[3]);if($roll<=0)return $e;}
    return null;
}
function pv_world_encounter_html(mysqli $db,array $area,int $x,int $y): string {
    return '<div class="pv-map-quiet"><strong>Roaming signals are visible on your map.</strong><span>Choose a Digimon, approach along clear ground and select Battle.</span></div>';
}
function pv_world_transition(array $area,int $x,int $y): ?array { foreach((array)$area['transitions'] as $t)if((int)$t['x']===$x&&(int)$t['y']===$y)return$t;return null; }
function pv_world_blocked_directions(mysqli $db,array $area,int $x,int $y): array {
    $directions=[1=>[0,-1],2=>[0,1],3=>[-1,0],4=>[1,0],5=>[-1,-1],6=>[-1,1],7=>[1,-1],8=>[1,1]];
    $blocks=pv_world_blocks($db,'cyberspace',(string)$area['key']);$out=[];
    foreach($directions as $direction=>[$dx,$dy]){
        $tx=$x+$dx;$ty=$y+$dy;
        if(pv_world_step_blocked($area,$blocks,$x,$y,$tx,$ty)){$out[]=$direction;continue;}
        $transition=pv_world_transition($area,$tx,$ty);if(!$transition)continue;
        $target=pv_world_area('cyberspace',(string)$transition['target_area']);if(!$target){$out[]=$direction;continue;}
        $tb=pv_world_blocks($db,'cyberspace',(string)$target['key']);$qx=(int)$transition['target_x'];$qy=(int)$transition['target_y'];
        if(pv_world_step_blocked($target,$tb,$qx,$qy,$qx,$qy))$out[]=$direction;
    }
    return $out;
}
