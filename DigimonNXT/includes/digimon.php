<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/catalog.php';
require_once __DIR__.'/progression.php';

function dn_species_by_slug(mysqli $db,string $slug): ?array {return dn_row($db,'SELECT * FROM digimon_species WHERE slug=?',[$slug]);}
function dn_species_by_id(mysqli $db,int $id): ?array {return dn_row($db,'SELECT * FROM digimon_species WHERE id=?',[$id]);}

function dn_level_stats(array $s,int $level,int $seed=0,array $training=[]): array {
    $level=max(1,min(99,$level));$meta=dn_species_meta((string)($s['slug']??''));
    $curve=$meta['stats']??$s['stats']??null;
    if (!$curve) throw new RuntimeException('This species has no verified stat data.');
    $low=$level<=50?1:50;$high=$level<=50?50:99;$ratio=($level-$low)/($high-$low);
    $map=['max_hp'=>'hp','max_sp'=>'sp','attack'=>'atk','defense'=>'def','intelligence'=>'int','speed'=>'spd'];$stats=[];
    $multiplier=($meta['variant']??'')==='paradox'?(float)($meta['power_multiplier']??1.75):1.0;
    foreach ($map as $key=>$stat) {
        $value=(float)$curve[(string)$low][$stat]+((float)$curve[(string)$high][$stat]-(float)$curve[(string)$low][$stat])*$ratio;
        $stats[$key]=max(1,(int)floor($value*$multiplier)+(int)($training[$stat]??0)*($stat==='hp'?10:1));
    }
    return $stats;
}
function dn_training(array $d): array {return json_decode((string)($d['training_json']??'{}'),true)?:[];}
function dn_discover_species(mysqli $db,int $ownerId,int $speciesId): void {
    $now=time();dn_write($db,'INSERT INTO digimon_scan(owner_id,species_id,first_seen,last_seen) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE first_seen=IF(first_seen=0,VALUES(first_seen),first_seen),last_seen=VALUES(last_seen)',[$ownerId,$speciesId,$now,$now]);
}
function dn_create_instance(mysqli $db,int $ownerId,int $speciesId,string $source='materialized',int $level=1,int $abi=0): int {
    $s=dn_species_by_id($db,$speciesId);if(!$s)throw new RuntimeException('Digimon species record is unavailable.');
    $level=max(1,min(99,$level));$abi=max(0,min(DN_ABI_MAX,$abi));$st=dn_level_stats($s,$level);
    $personalities=['Balanced','Brave','Calm','Clever','Energetic','Loyal','Mischievous','Steady'];$personality=$personalities[array_rand($personalities)];
    dn_write($db,'INSERT INTO digimon_instances(owner_id,species_id,level,hp,max_hp,sp,max_sp,attack_stat,defense_stat,intelligence_stat,speed_stat,personality,source,created_at,abi) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$ownerId,$speciesId,$level,$st['max_hp'],$st['max_hp'],$st['max_sp'],$st['max_sp'],$st['attack'],$st['defense'],$st['intelligence'],$st['speed'],$personality,$source,time(),$abi]);
    $id=(int)$db->insert_id;dn_learn_moves($db,$id,$s['slug'],$level);dn_discover_species($db,$ownerId,$speciesId);return $id;
}
function dn_instance_select(): string {return 'SELECT d.*,s.slug,s.name,s.stage,s.attribute,s.type,s.element,s.skill_name,s.skill_power,s.sprite FROM digimon_instances d JOIN digimon_species s ON s.id=d.species_id';}
function dn_owned_instance(mysqli $db,int $uid,int $id,bool $lock=false): ?array {return dn_row($db,dn_instance_select().' WHERE d.id=? AND d.owner_id=?'.($lock?' FOR UPDATE':''),[$id,$uid]);}
function dn_party_rows(mysqli $db,int $ownerId): array {
    $rows=dn_rows($db,'SELECT p.slot,d.*,s.slug,s.name,s.stage,s.attribute,s.type,s.element,s.skill_name,s.skill_power,s.sprite FROM digimon_party p JOIN digimon_instances d ON d.id=p.digimon_id AND d.owner_id=p.owner_id JOIN digimon_species s ON s.id=d.species_id WHERE p.owner_id=? ORDER BY p.slot',[$ownerId]);
    $out=[];foreach($rows as $d)$out[(int)$d['slot']]=$d;return $out;
}
function dn_collection_rows(mysqli $db,int $ownerId): array {
    return dn_rows($db,'SELECT d.*,s.slug,s.name,s.stage,s.attribute,s.type,s.element,s.skill_name,s.skill_power,s.sprite,p.slot FROM digimon_instances d JOIN digimon_species s ON s.id=d.species_id LEFT JOIN digimon_party p ON p.owner_id=d.owner_id AND p.digimon_id=d.id WHERE d.owner_id=? ORDER BY COALESCE(p.slot,99),d.level DESC,d.id',[$ownerId]);
}
function dn_assign_party_slot(mysqli $db,int $ownerId,int $slot,int $digimonId): void {
    if($slot<1||$slot>6)throw new RuntimeException('Invalid party slot.');
    $db->begin_transaction();try {
        dn_lock_owner($db,$ownerId);dn_require_idle($db,$ownerId);
        $d=dn_owned_instance($db,$ownerId,$digimonId,true);
        if(!$d)throw new RuntimeException('That Digimon is not in your collection.');
        if((int)$d['locked_trade'])throw new RuntimeException('Cancel the exchange listing before assigning this Digimon.');
        dn_write($db,'DELETE FROM digimon_party WHERE owner_id=? AND (digimon_id=? OR slot=?)',[$ownerId,$digimonId,$slot]);
        dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$ownerId,$slot,$digimonId]);$db->commit();dn_world_game_event($db,$ownerId,'party.assign','Assigned '.$d['name'].' to party slot '.$slot,['digimon_id'=>$digimonId,'slot'=>$slot]);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_remove_party_slot(mysqli $db,int $ownerId,int $slot): void {
    $db->begin_transaction();try {dn_lock_owner($db,$ownerId);dn_require_idle($db,$ownerId);$removed=dn_write($db,'DELETE FROM digimon_party WHERE owner_id=? AND slot=?',[$ownerId,$slot]);$db->commit();if($removed)dn_world_game_event($db,$ownerId,'party.remove','Removed a Digimon from party slot '.$slot,['slot'=>$slot]);}catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_first_battle_ready(mysqli $db,int $ownerId): ?array {
    foreach(dn_party_rows($db,$ownerId) as $d)if((int)$d['hp']>0&&!(int)$d['locked_trade'])return $d;return null;
}
function dn_heal_party(mysqli $db,int $ownerId): int {
    $db->begin_transaction();try {dn_lock_owner($db,$ownerId);dn_require_idle($db,$ownerId);$n=dn_write($db,'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.hp=d.max_hp,d.sp=d.max_sp WHERE d.owner_id=?',[$ownerId]);$db->commit();if($n)dn_world_game_event($db,$ownerId,'party.restore','Party fully restored',['count'=>$n]);return $n;}catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_scan_row(mysqli $db,int $ownerId,int $speciesId): array {
    return dn_row($db,'SELECT * FROM digimon_scan WHERE owner_id=? AND species_id=?',[$ownerId,$speciesId])??['scan_percent'=>0,'encounters'=>0,'wins'=>0,'first_seen'=>0];
}
function dn_register_encounter(mysqli $db,int $ownerId,int $speciesId): void {
    $now=time();dn_write($db,'INSERT INTO digimon_scan(owner_id,species_id,scan_percent,encounters,wins,first_seen,last_seen) VALUES(?,?,0,1,0,?,?) ON DUPLICATE KEY UPDATE encounters=encounters+1,first_seen=IF(first_seen=0,VALUES(first_seen),first_seen),last_seen=VALUES(last_seen)',[$ownerId,$speciesId,$now,$now]);
}
function dn_scan_reward_for_stage(string $stage): int {return match(strtolower($stage)){'baby'=>40,'in-training'=>30,'rookie'=>random_int(20,35),'champion','armor'=>random_int(12,22),'ultimate'=>random_int(8,16),'mega','ultra'=>random_int(5,10),default=>20};}
function dn_award_scan(mysqli $db,int $ownerId,int $speciesId,string $stage): array {
    $species=dn_species_by_id($db,$speciesId);$reward=dn_is_paradox($species??[])?random_int(1,3):dn_scan_reward_for_stage($stage);$now=time();
    dn_write($db,'INSERT INTO digimon_scan(owner_id,species_id,scan_percent,encounters,wins,first_seen,last_seen) VALUES(?,?,?,1,1,?,?) ON DUPLICATE KEY UPDATE scan_percent=LEAST(200,scan_percent+VALUES(scan_percent)),wins=wins+1,first_seen=IF(first_seen=0,VALUES(first_seen),first_seen),last_seen=VALUES(last_seen)',[$ownerId,$speciesId,$reward,$now,$now]);
    return ['reward'=>$reward,'total'=>(int)dn_scan_row($db,$ownerId,$speciesId)['scan_percent']];
}
function dn_materialize(mysqli $db,int $ownerId,int $speciesId): int {
    $db->begin_transaction();try {
        dn_lock_owner($db,$ownerId);dn_require_idle($db,$ownerId);
        $row=dn_row($db,'SELECT scan_percent FROM digimon_scan WHERE owner_id=? AND species_id=? FOR UPDATE',[$ownerId,$speciesId]);$scan=(int)($row['scan_percent']??0);
        if($scan<100)throw new RuntimeException('100% scan data is required to materialize this Digimon.');
        $id=dn_create_instance($db,$ownerId,$speciesId,'materialized',1,dn_conversion_abi($scan));
        dn_write($db,'UPDATE digimon_scan SET scan_percent=0 WHERE owner_id=? AND species_id=?',[$ownerId,$speciesId]);
        dn_write($db,'INSERT INTO materialization_log(owner_id,species_id,digimon_id,scan_spent,created_at) VALUES(?,?,?,?,?)',[$ownerId,$speciesId,$id,$scan,time()]);
        $party=dn_party_rows($db,$ownerId);
        for($slot=1;$slot<=6;$slot++)if(!isset($party[$slot])){dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$ownerId,$slot,$id]);break;}
        $species=dn_species_by_id($db,$speciesId);$db->commit();dn_world_game_event($db,$ownerId,'digimon.materialize','Materialized '.($species['name']??'Digimon').' using '.$scan.'% scan',['digimon_id'=>$id,'species_id'=>$speciesId,'scan'=>$scan]);return $id;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_level_exp_required(int $level): int {return max(100,40*$level+4*$level*$level);}
function dn_award_exp(mysqli $db,int $digimonId,int $ownerId,int $gain): array {
    $d=dn_owned_instance($db,$ownerId,$digimonId,true);if(!$d)return ['gain'=>0,'level'=>0,'levels'=>0];
    $gain=max(0,$gain);$level=(int)$d['level'];$exp=(int)$d['exp']+$gain;$levels=0;
    while($level<99&&$exp>=dn_level_exp_required($level)){$exp-=dn_level_exp_required($level);$level++;$levels++;}
    if($level===99)$exp=0;
    $st=dn_level_stats($d,$level,0,dn_training($d));
    $hp=(int)$d['hp']<=0?0:min($st['max_hp'],(int)$d['hp']+max(0,$st['max_hp']-(int)$d['max_hp']));
    $sp=min($st['max_sp'],(int)$d['sp']+max(0,$st['max_sp']-(int)$d['max_sp']));
    dn_write($db,'UPDATE digimon_instances SET level=?,exp=?,hp=?,max_hp=?,sp=?,max_sp=?,attack_stat=?,defense_stat=?,intelligence_stat=?,speed_stat=? WHERE id=? AND owner_id=?',[$level,$exp,$hp,$st['max_hp'],$sp,$st['max_sp'],$st['attack'],$st['defense'],$st['intelligence'],$st['speed'],$digimonId,$ownerId]);
    dn_learn_moves($db,$digimonId,$d['slug'],$level);dn_write($db,'UPDATE members SET totalexp=totalexp+? WHERE id=?',[$gain,$ownerId]);
    return ['gain'=>$gain,'level'=>$level,'levels'=>$levels];
}
function dn_instance_image(array $row): string {return pv_static((string)($row['sprite']??'images/digimon/agumon.png'));}

function dn_record_battle(mysqli $db,int $ownerId,?int $digimonId,?int $enemySpeciesId,string $type,string $result,string $area,int $expGain=0,int $scanGain=0,int $bitsGain=0): void {
    $type=in_array($type,['wild','arena','event'],true)?$type:'wild';
    $result=in_array($result,['win','loss','disconnect'],true)?$result:'disconnect';
    $now=time();$stmt=$db->prepare('INSERT INTO battle_log(owner_id,digimon_id,enemy_species_id,battle_type,result,area_key,exp_gain,scan_gain,bits_gain,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
    if($stmt){$stmt->bind_param('iiisssiiii',$ownerId,$digimonId,$enemySpeciesId,$type,$result,$area,$expGain,$scanGain,$bitsGain,$now);$stmt->execute();$stmt->close();}
    if($result==='win'&&$area!==''){
        require_once __DIR__.'/world_maps.php';
        $definition=pv_world_area('cyberspace',$area);
        $areaName=$type==='wild'?match((string)($definition['event_group']??'')){'trails'=>'Dawn Trails','frozen'=>'Frozen Reaches','deep'=>'Deep Network',default=>''}:'';
        if($areaName!==''){
            $stmt=$db->prepare('SELECT id FROM events WHERE is_active=1 AND area_name=? LIMIT 1');if($stmt){$stmt->bind_param('s',$areaName);$stmt->execute();$e=$stmt->get_result()->fetch_assoc();$stmt->close();if($e){$eid=(int)$e['id'];$step=10;$now=time();$stmt=$db->prepare('INSERT INTO event_progress(owner_id,event_id,progress,completed,updated_at) VALUES(?,?,?,0,?) ON DUPLICATE KEY UPDATE completed=IF(progress+VALUES(progress)>=100,1,completed),progress=LEAST(100,progress+VALUES(progress)),updated_at=VALUES(updated_at)');if($stmt){$stmt->bind_param('iiii',$ownerId,$eid,$step,$now);$stmt->execute();$stmt->close();}}}
        }
    }
}
