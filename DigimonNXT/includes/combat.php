<?php
declare(strict_types=1);
require_once __DIR__.'/digimon.php';
require_once __DIR__.'/economy.php';

function dn_combat_log(array &$b,string $message): void {$b['log'][]=$message;$b['log']=array_slice($b['log'],-1800);}
function dn_combat_save(mysqli $db,int $uid,array $b): void {
    // Animation transport is request-local; never grow the persistent combat state.
    unset($b['presentation'],$b['team']['_presentation'],$b['team']['presentation']);
    dn_write($db,'INSERT INTO digimon_battle_state(owner_id,state_json,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),updated_at=VALUES(updated_at)',[$uid,json_encode($b,JSON_THROW_ON_ERROR),time()]);
}
function dn_combat_unit(array $d): array {
    return ['id'=>(int)$d['id'],'species_id'=>(int)($d['species_id']??0),'slug'=>$d['slug'],'name'=>($d['nickname']??'')?:$d['name'],'level'=>(int)($d['level']??1),'stage'=>$d['stage']??'Rookie','sprite'=>$d['sprite']??'','hp'=>(int)$d['hp'],'max_hp'=>(int)$d['max_hp'],'sp'=>(int)$d['sp'],'max_sp'=>(int)$d['max_sp'],'atk'=>(int)$d['attack_stat'],'def'=>(int)$d['defense_stat'],'int'=>(int)$d['intelligence_stat'],'spd'=>(int)$d['speed_stat'],'attribute'=>$d['attribute'],'element'=>$d['element'],'effects'=>[]];
}
function dn_combat_wild_unit(array $species,int $level,int $index=0): array {
    $st=dn_level_stats($species,$level);
    return ['id'=>-$index,'species_id'=>(int)$species['id'],'slug'=>$species['slug'],'name'=>$species['name'],'stage'=>$species['stage'],'sprite'=>$species['sprite'],'level'=>$level,'hp'=>$st['max_hp'],'max_hp'=>$st['max_hp'],'sp'=>$st['max_sp'],'max_sp'=>$st['max_sp'],'atk'=>$st['attack'],'def'=>$st['defense'],'int'=>$st['intelligence'],'spd'=>$st['speed'],'attribute'=>$species['attribute'],'element'=>$species['element'],'effects'=>[],'moves'=>dn_species_moves($species['slug'],$level)];
}
/** Once-per-accepted-encounter scanning does not count an escape as a victory. */
function dn_combat_scan_encounter(mysqli $db,int $uid,array $enemy): array {
    $reward=dn_is_paradox($enemy)?random_int(1,3):dn_scan_reward_for_stage($enemy['stage']);$now=time();
    dn_write($db,'INSERT INTO digimon_scan(owner_id,species_id,scan_percent,encounters,wins,first_seen,last_seen) VALUES(?,?,?,1,0,?,?) ON DUPLICATE KEY UPDATE scan_percent=LEAST(200,scan_percent+VALUES(scan_percent)),encounters=encounters+1,first_seen=IF(first_seen=0,VALUES(first_seen),first_seen),last_seen=VALUES(last_seen)',[$uid,(int)$enemy['species_id'],$reward,$now,$now]);
    return ['species_id'=>(int)$enemy['species_id'],'name'=>$enemy['name'],'reward'=>$reward,'total'=>(int)dn_scan_row($db,$uid,(int)$enemy['species_id'])['scan_percent']];
}
function dn_combat_sync_legacy(array &$b): void {
    $next=dn_team_next($b['team']);$b['player_id']=$next&&$next['side']==='left'?(int)$b['team']['left'][$next['key']]['id']:(int)(reset($b['team']['left'])['id']??0);
    $b['enemy']=reset($b['team']['right']);$b['units']=[];
    foreach($b['team']['left'] as $u)$b['units'][(string)$u['id']]=['effects'=>$u['effects']];
    $b['log']=$b['team']['log'];
}
function dn_combat_advance_enemy(array &$b): void {
    $steps=0;
    while(!$b['team']['finished']&&$steps++<12){$next=dn_team_next($b['team']);if(!$next||$next['side']==='left')break;dn_team_take_turn($b['team'],dn_team_ai_command($b['team'],'right',$next['key']));}
    dn_combat_sync_legacy($b);
}
function dn_combat_start(mysqli $db,int $uid,array $pending,string $token,bool $presentation=false): array {
    if(empty($pending['token'])||!is_string($pending['token'])||!hash_equals($pending['token'],$token)||time()-(int)($pending['created_at']??0)>600)throw new RuntimeException('That Digimon signal has expired.');
    $db->begin_transaction();try {
        dn_lock_owner($db,$uid);$old=dn_active_battle($db,$uid);
        if($old&&empty($old['finished']))throw new RuntimeException('Finish your current wild battle before starting another.');
        if(!empty($old['encounter_hash'])&&hash_equals($old['encounter_hash'],hash('sha256',$token)))throw new RuntimeException('This signal has already been scanned. Find another roaming Digimon.');
        if(isset($_SESSION['world_area'])&&($_SESSION['world_area']!==$pending['area']||($_SESSION['world_key']??'cyberspace')!=='cyberspace'))throw new RuntimeException('Return to the signal’s sector to begin this battle.');
        $current=dn_row($db,'SELECT world_key,map FROM mapusers WHERE id=?',[$uid]);
        if($current&&($current['world_key']!=='cyberspace'||$current['map']!==$pending['area']))throw new RuntimeException('You have left this signal’s sector.');
        $pos=dn_row($db,'SELECT x,y FROM world_map_positions WHERE user_id=? AND world_key=? AND area_key=?',[$uid,'cyberspace',$pending['area']]);
        if(!$pos||(int)$pos['x']!==(int)$pending['x']||(int)$pos['y']!==(int)$pending['y'])throw new RuntimeException('The signal is no longer in range.');
        $party=dn_party_rows($db,$uid);if(!$party)throw new RuntimeException('Assign a Digimon to your party before entering battle.');
        $left=[];foreach($party as $d){if(!empty($d['locked_trade']))continue;$u=dn_combat_unit($d);$u['moves']=dn_instance_moves($db,$d);$left[]=$u;}
        if(!$left)throw new RuntimeException('No linked party is available.');
        // Legacy wounded saves can rejoin without visiting a healing terminal.
        foreach($left as &$u){$u['hp']=$u['max_hp'];$u['sp']=$u['max_sp'];}unset($u);
        $enemy=dn_species_by_id($db,(int)$pending['species_id']);if(!$enemy)throw new RuntimeException('Wild species unavailable.');
        $level=(int)$pending['level'];if($level<1||$level>DN_WILD_MAX_LEVEL)throw new RuntimeException('This signal predates the level update. Scan a new encounter.');
        $right=[dn_combat_wild_unit($enemy,$level)];
        foreach(array_slice((array)($pending['enemies']??[]),1,2) as $extra){$sp=dn_species_by_id($db,(int)($extra['species_id']??0));if($sp)$right[]=dn_combat_wild_unit($sp,max(1,min(DN_WILD_MAX_LEVEL,(int)($extra['level']??$level))),count($right));}
        while(count($right)<3)$right[]=dn_combat_wild_unit($enemy,max(1,$level-random_int(0,2)),count($right));
        $b=['combat_version'=>2,'encounter_hash'=>hash('sha256',$token),'token'=>bin2hex(random_bytes(24)),'area'=>$pending['area'],'started_at'=>time(),'turn'=>0,'player_id'=>(int)$left[0]['id'],'enemy_species_id'=>(int)$enemy['id'],'enemy_name'=>$enemy['name'],'enemy_slug'=>$enemy['slug'],'enemy_stage'=>$enemy['stage'],'enemy_sprite'=>$enemy['sprite'],'enemy_level'=>$level,'team'=>dn_team_create($left,$right),'finished'=>false,'result'=>'','scan_reward'=>0,'scan_total'=>0,'scan_rewards'=>[],'exp_gain'=>0,'bits_gain'=>0,'levels'=>0,'log'=>[]];
        dn_combat_log($b['team'],'Three wild Digimon detected. Scan data secured on contact.');
        foreach($right as $u){$scan=dn_combat_scan_encounter($db,$uid,$u);$b['scan_rewards'][]=$scan;$b['scan_reward']+=$scan['reward'];$b['scan_total']=$scan['total'];dn_combat_log($b['team'],$u['name'].' · +'.$scan['reward'].'% scan ('.$scan['total'].'% total).');}
        $initial=$presentation?dn_team_presentation_snapshot($b['team']):[];if($presentation)dn_team_presentation_begin($b['team']);
        dn_combat_advance_enemy($b);dn_combat_persist_team($db,$uid,$b);
        if($b['team']['finished'])dn_combat_finish($db,$uid,$b,$b['team']['result']);
        $visual=$presentation?(['initial'=>$initial]+dn_team_presentation_end($b['team'])+['final'=>dn_team_presentation_snapshot($b['team'])]):null;
        dn_combat_save($db,$uid,$b);$db->commit();dn_world_game_event($db,$uid,'wild.start','Engaged '.$b['enemy_name'].' in '.$b['area'].'; +'.$b['scan_reward'].'% scan',['species'=>$b['enemy_name'],'area'=>$b['area'],'scan'=>$b['scan_reward']]);if(!empty($b['finished']))dn_world_battle_completed($db,$uid,$b);if($visual!==null)$b['presentation']=['id'=>$b['token']]+$visual;return $b;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_effect_stat(array $unit,string $stat): float {
    $bonus=0;foreach($unit['effects']??[] as $e)if(($e['stat']??'')===$stat)$bonus+=(($e['kind']??'')==='debuff'?-1:1)*(int)$e['amount'];
    return max(1,(float)($unit[$stat]??100)*(1+max(-50,min(100,$bonus))/100));
}
function dn_has_effect(array $unit,string $kind): bool {foreach($unit['effects']??[] as $e)if($e['kind']===$kind)return true;return false;}
function dn_clear_effect(array &$unit,string $kind): void {$unit['effects']=array_values(array_filter($unit['effects']??[],fn($e)=>$e['kind']!==$kind));}
function dn_add_effect(array &$unit,array $effect): void {
    $effect['turns']=$effect['turns']??3;$effect['fresh']=true;
    if(in_array($effect['kind'],['buff','debuff'],true)){
        foreach($unit['effects'] as &$e)if($e['kind']===$effect['kind']&&($e['stat']??'')===($effect['stat']??'')){$e['amount']=min(100,(int)$e['amount']+(int)$effect['amount']);$e['turns']=$effect['turns'];$e['fresh']=true;return;}unset($e);
    }else dn_clear_effect($unit,$effect['kind']);
    $unit['effects'][]=$effect;
}
function dn_attribute_multiplier(array $attacker,array $defender,string $element): float {
    $type=['Vaccine'=>'Virus','Virus'=>'Data','Data'=>'Vaccine'];$a=$attacker['attribute'];$d=$defender['attribute'];
    $m=($type[$a]??'')===$d?2.0:((($type[$d]??'')===$a)?0.5:1.0);
    if(dn_has_effect($attacker,'bug')||dn_has_effect($defender,'bug'))$m=$m===2.0?0.5:($m===0.5?2.0:1.0);
    $adv=['Fire'=>'Plant','Plant'=>'Water','Water'=>'Fire','Electric'=>'Wind','Wind'=>'Earth','Earth'=>'Electric','Light'=>'Dark','Dark'=>'Light'];
    if(($adv[$element]??'')===$defender['element'])$m*=1.5;return $m;
}
function dn_basic_move(): array {return ['name'=>'Attack','sp'=>0,'type'=>'Physical','element'=>'Neutral','power'=>50,'accuracy'=>100,'hits'=>[1,1],'target'=>'foe','effects'=>[]];}
/** Optional hit metadata observes the existing rolls; it never rolls a second result. */
function dn_combat_damage(array &$a,array &$d,array $move,bool $guard=false,?array &$detail=null): int {
    $detail=['missed'=>false,'blocked'=>false,'critical'=>false,'effectiveness'=>1.0,'guarded'=>false,'endured'=>false];
    if(dn_has_effect($d,'invulnerable')){$detail['blocked']=true;return 0;}
    $eva=dn_effect_stat($d,'eva')-100;$accuracy=(float)($move['accuracy']??100)+(dn_effect_stat($a,'acc')-100)-$eva;
    if(empty($move['always_hits'])&&random_int(1,100)>max(0,min(100,$accuracy))){$detail['missed']=true;return 0;}
    if(!empty($move['percent_hp']))$damage=max(1,(int)floor($d['hp']*(int)$move['percent_hp']/100));
    elseif(!empty($move['fixed']))$damage=(int)$move['fixed'];
    elseif(!empty($move['pierce']))$damage=(int)round(dn_effect_stat($a,$move['pierce']==='int'?'int':'atk')*(float)($move['pierce_multiplier']??1.5));
    else {
        $stat=($move['type']??'Physical')==='Magic'?'int':'atk';$def=$stat==='int'?'int':'def';
        $damage=max(1,(int)round(((dn_effect_stat($a,$stat)*1.5)-(dn_effect_stat($d,$def)*0.5))*(float)$move['power']/100));
    }
    if(empty($move['fixed'])&&empty($move['percent_hp'])){
        $detail['effectiveness']=dn_attribute_multiplier($a,$d,$move['element']??'Neutral');
        $damage=(int)round($damage*$detail['effectiveness']*random_int((int)($move['variance'][0]??95),(int)($move['variance'][1]??105))/100);
        if(dn_has_effect($a,'acceleration'))$damage*=2;
        if(dn_has_effect($a,'combo')&&!dn_has_effect($a,'combo_break'))$damage=(int)round($damage*1.5);
        if(random_int(1,100)<=min(100,(int)($move['crit']??5)+(int)dn_effect_stat($a,'crit')-100)){$damage=(int)round($damage*1.5);$detail['critical']=true;}
        if($guard){$damage=max(1,(int)ceil($damage*.45));$detail['guarded']=true;}
    }
    if($damage>=$d['hp']&&$d['hp']>1&&dn_has_effect($d,'endure')){$damage=$d['hp']-1;dn_clear_effect($d,'endure');$detail['endured']=true;}
    return max(1,$damage);
}
function dn_combat_before_turn(array &$unit,array &$b): bool {
    if($unit['hp']<=0)return false;
    foreach($unit['effects'] as &$effect)$effect['fresh']=false;unset($effect);
    if(dn_has_effect($unit,'poison')){
        $before=isset($b['_presentation'])?dn_team_presentation_unit($unit):[];
        $loss=min($unit['hp']-1,max(1,(int)ceil($unit['max_hp']*.1)));$unit['hp']=max(1,$unit['hp']-$loss);dn_combat_log($b,$unit['name'].' lost '.$loss.' HP to poison.');
        dn_team_presentation_effect($b,$unit,$unit,'poison',$before,$before);
    }
    foreach(['recoil','stun','sleep','delay'] as $kind)if(dn_has_effect($unit,$kind)){dn_combat_log($b,$unit['name'].' cannot act ('.$kind.').');dn_team_presentation_emit($b,'skip',['actor'=>(string)($unit['key']??''),'reason'=>$kind],[$unit]);return false;}
    if(dn_has_effect($unit,'paralysis')&&random_int(1,100)<=25){dn_combat_log($b,$unit['name'].' is paralyzed and missed this turn.');dn_team_presentation_emit($b,'skip',['actor'=>(string)($unit['key']??''),'reason'=>'paralysis'],[$unit]);return false;}
    return true;
}
function dn_combat_tick(array &$unit): void {
    foreach($unit['effects'] as &$effect){if(!empty($effect['fresh'])){$effect['fresh']=false;continue;}$effect['turns']--;}unset($effect);
    $unit['effects']=array_values(array_filter($unit['effects'],fn($e)=>(int)$e['turns']>0));
}
function dn_combat_apply_move(array &$a,array &$d,array $move,array &$b,bool $guard=false): void {
    if($a['sp']<(int)$move['sp'])throw new RuntimeException('Not enough SP for '.$move['name'].'.');
    $a['sp']-=(int)$move['sp'];$damage=0;
    $offensive=(int)($move['power']??0)>0||!empty($move['fixed'])||!empty($move['pierce'])||!empty($move['percent_hp']);
    if(!$offensive&&($move['target']??'foe')==='foe'&&random_int(1,100)>(int)($move['accuracy']??100)){dn_combat_log($b,$a['name'].' used '.$move['name'].' · missed.');return;}
    if($offensive){
        $hits=random_int((int)$move['hits'][0],(int)$move['hits'][1]);
        for($i=0;$i<$hits&&$d['hp']>0;$i++){$hit=dn_combat_damage($a,$d,$move,$guard);$damage+=min($d['hp'],$hit);$d['hp']=max(0,$d['hp']-$hit);}
        dn_clear_effect($a,'acceleration');
        if($damage>0)dn_clear_effect($d,'sleep');
        dn_combat_log($b,$a['name'].' used '.$move['name'].' · '.($damage?$damage.' damage.':'missed.'));
        if($damage>0&&((dn_has_effect($d,'counter')&&$move['type']==='Physical')||(dn_has_effect($d,'counter_magic')&&$move['type']==='Magic'))){$counter=max(1,(int)round($damage*.5));$a['hp']=max(0,$a['hp']-$counter);dn_combat_log($b,$d['name'].' countered for '.$counter.' damage.');}
    }else dn_combat_log($b,$a['name'].' used '.$move['name'].'.');
    foreach($move['effects']??[] as $e){
        if(random_int(1,100)>(int)($e['chance']??100))continue;
        $self=in_array($e['target']??'foe',['self','ally','all_allies'],true);if($self)$target=&$a;else $target=&$d;
        if(!$self&&$offensive&&$damage===0)continue;
        switch($e['kind']){
            case 'drain_hp': $a['hp']=min($a['max_hp'],$a['hp']+(int)floor($damage*(int)$e['amount']/100));break;
            case 'drain_sp': $n=min($d['sp'],(int)floor($damage*(int)$e['amount']/100));$d['sp']-=$n;$a['sp']=min($a['max_sp'],$a['sp']+$n);break;
            case 'heal':$a['hp']=min($a['max_hp'],$a['hp']+(int)$e['amount']);break;
            case 'revive':if($a['hp']<=0)$a['hp']=max(1,(int)round($a['max_hp']*(int)$e['amount']/100));break;
            case 'cure':$status=$e['status']==='paralysis'?'paralysis':$e['status'];if($status==='all')$a['effects']=array_values(array_filter($a['effects'],fn($v)=>!in_array($v['kind'],['poison','panic','paralysis','stun','sleep','bug','dot','support_block'],true)));else dn_clear_effect($a,$status);$a['hp']=min($a['max_hp'],$a['hp']+(int)$e['amount']);break;
            case 'recoil_damage':$a['hp']=max(1,$a['hp']-(int)ceil($a['max_hp']*(int)$e['amount']/100));break;
            case 'random_status':$statuses=['poison','panic','paralysis','sleep','bug','dot'];if(!dn_has_effect($target,'barrier'))dn_add_effect($target,['kind'=>$statuses[array_rand($statuses)],'turns'=>3]);break;
            case 'clear_buffs':dn_clear_effect($target,'buff');break;
            case 'clear_debuffs':dn_clear_effect($target,'debuff');break;
            case 'death':if(!dn_has_effect($target,'barrier')&&!dn_has_effect($target,'invulnerable')&&!dn_is_paradox($target)){$target['hp']=0;dn_combat_log($b,$target['name'].' was defeated by the finishing effect.');}break;
            case 'escape':$b['finished']=true;$b['result']='disconnect';break;
            default:
                if(in_array($e['kind'],['poison','panic','paralysis','stun','sleep','bug','dot','support_block'],true)&&dn_has_effect($target,'barrier'))break;
                if($target['hp']>0){dn_add_effect($target,$e);dn_combat_log($b,$target['name'].' · '.ucwords(str_replace('_',' ',$e['kind'])).(!empty($e['stat'])?' '.strtoupper($e['stat']):'').'.');}
        }
        unset($target);
    }
}
function dn_combat_enemy_turn(array &$enemy,array &$player,array &$battle,bool $guard=false): void {
    if(dn_combat_before_turn($enemy,$battle)){
        $moves=dn_has_effect($enemy,'dot')?[]:array_filter(dn_species_moves($enemy['slug'],(int)$battle['enemy_level']),fn($m)=>(int)$m['sp']<=$enemy['sp']&&($m['type']!=='Support'||!dn_has_effect($enemy,'support_block')));
        $damaging=array_filter($moves,fn($m)=>(int)$m['power']>0||!empty($m['fixed'])||!empty($m['pierce'])||!empty($m['percent_hp']));
        $support=array_filter($moves,fn($m)=>$m['type']==='Support'&&!array_filter($m['effects'],fn($e)=>in_array($e['kind'],['revive','escape'],true)));
        $heals=array_filter($support,fn($m)=>(bool)array_filter($m['effects'],fn($e)=>$e['kind']==='heal'));
        if($enemy['hp']<$enemy['max_hp']/2&&$heals&&random_int(1,100)<=65)$move=$heals[array_rand($heals)];
        elseif($support&&random_int(1,100)<=15)$move=$support[array_rand($support)];
        else $move=$damaging&&random_int(1,100)<=65?$damaging[array_rand($damaging)]:dn_basic_move();
        if(dn_has_effect($enemy,'panic')&&random_int(1,100)<=30){$self=$enemy;dn_combat_apply_move($enemy,$self,dn_basic_move(),$battle);$enemy['hp']=$self['hp'];dn_combat_log($battle,'Confusion redirected the wild attack.');}
        else dn_combat_apply_move($enemy,$player,$move,$battle,$guard);
    }
    dn_combat_tick($enemy);
}
function dn_combat_persist_unit(mysqli $db,int $uid,array $unit,array &$b): void {
    if((int)$unit['id']===0){$b['enemy']=$unit;return;}
    dn_write($db,'UPDATE digimon_instances SET hp=LEAST(max_hp,?),sp=LEAST(max_sp,?) WHERE id=? AND owner_id=?',[max(0,(int)$unit['hp']),max(0,(int)$unit['sp']),(int)$unit['id'],$uid]);
    $b['units'][(string)$unit['id']]=['effects'=>$unit['effects']];
}
function dn_combat_load_unit(mysqli $db,int $uid,int $id,array $b): array {
    $d=dn_owned_instance($db,$uid,$id,true);if(!$d)throw new RuntimeException('Your active Digimon is unavailable.');
    $unit=dn_combat_unit($d);$unit['name']=$d['nickname']?:$d['name'];$unit['effects']=$b['units'][(string)$id]['effects']??[];return $unit;
}
function dn_combat_restore_party(mysqli $db,int $uid): void {
    dn_write($db,'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.hp=d.max_hp,d.sp=d.max_sp WHERE d.owner_id=?',[$uid]);
}
function dn_combat_persist_team(mysqli $db,int $uid,array &$b): void {
    foreach($b['team']['left'] as $u)dn_write($db,'UPDATE digimon_instances SET hp=LEAST(max_hp,?),sp=LEAST(max_sp,?) WHERE id=? AND owner_id=?',[max(0,(int)$u['hp']),max(0,(int)$u['sp']),(int)$u['id'],$uid]);
}
function dn_combat_finish(mysqli $db,int $uid,array &$b,string $result): void {
    if(!empty($b['settled']))return;$result=$result==='draw'?'loss':$result;$b['finished']=true;$b['result']=$result;$gain=0;$bits=0;$levels=0;
    if($result==='win'){
        foreach($b['team']['right'] as $enemy){
            dn_write($db,'UPDATE digimon_scan SET wins=wins+1 WHERE owner_id=? AND species_id=?',[$uid,(int)$enemy['species_id']]);
            $bonus=match($enemy['stage']){'Champion','Armor'=>150,'Ultimate'=>500,'Mega'=>1500,'Ultra'=>3000,default=>25};$mult=dn_is_paradox($enemy)?2:1;
            $gain+=(25+(int)$enemy['level']*15+$bonus)*$mult;$bits+=(20+(int)$enemy['level']*5+(int)round($bonus/10))*$mult;
        }
        $perMember=max(1,(int)floor($gain/count($b['team']['left'])));$awarded=0;
        foreach($b['team']['left'] as $u){$exp=dn_award_exp($db,(int)$u['id'],$uid,$perMember);$levels+=$exp['levels'];$awarded+=$exp['gain'];}
        $gain=$awarded;
        dn_write($db,'UPDATE members SET money=money+?,battle=battle+1,wins=wins+1,points=points+1 WHERE id=?',[$bits,$uid]);
        dn_write($db,'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.cam=LEAST(100,d.cam+1) WHERE d.owner_id=?',[$uid]);
        dn_combat_log($b['team'],'Victory · '.$gain.' total party EXP · '.$bits.' Bits.');
    }else{dn_write($db,'UPDATE members SET battle=battle+1,losses=losses+? WHERE id=?',[$result==='loss'?1:0,$uid]);dn_combat_log($b['team'],$result==='loss'?'The party was defeated. Scan data is retained.':'Disconnected safely. Scan data is retained.');}
    $b['exp_gain']=$gain;$b['bits_gain']=$bits;$b['levels']=$levels;$b['settled']=true;
    dn_combat_restore_party($db,$uid);$b['restored']=true;dn_combat_log($b['team'],'Full restoration complete · every party Digimon has full HP and SP.');
    $b['log']=$b['team']['log'];
    dn_record_battle($db,$uid,(int)$b['player_id'],(int)$b['enemy_species_id'],'wild',$result,$b['area'],$gain,(int)$b['scan_reward'],$bits);
}
function dn_combat_action(mysqli $db,int $uid,string $token,string $action,array $input=[],bool $presentation=false): array {
    $db->begin_transaction();try {
        dn_lock_owner($db,$uid);$b=dn_active_battle($db,$uid);
        if(!$b||!empty($b['finished']))throw new RuntimeException('No active wild battle is linked.');
        if(!hash_equals($b['token'],$token))throw new RuntimeException('This turn has already been played. The battle has been refreshed.');
        if(($b['combat_version']??0)!==2)throw new RuntimeException('This battle belongs to an earlier version. Run the database upgrade to restore your party.');
        if(!in_array($action,['attack','skill','guard','flee','item','switch'],true))throw new RuntimeException('Unknown battle action.');
        $next=dn_team_next($b['team']);if(!$next||$next['side']!=='left')throw new RuntimeException('The battle is resolving another turn. Refresh and try again.');
        $actor=$next['key'];$cmd=['action'=>$action,'move_slug'=>(string)($input['move_slug']??'')];
        $targetId=(int)(($action==='switch'?($input['reserve_id']??$input['target_id']??null):($input['target_id']??null))??$b['team']['left'][$actor]['id']);$allyKey='';
        foreach($b['team']['left'] as $k=>$u)if((int)$u['id']===$targetId)$allyKey=$k;
        if($action==='switch')$cmd['reserve']=$allyKey;
        if($action==='skill'){$move=$b['team']['left'][$actor]['moves'][$cmd['move_slug']]??[];$cmd['target']=in_array($move['target']??'foe',['self','ally','all_allies'],true)?$allyKey:(string)($input['enemy_target']??'');}
        elseif($action==='attack')$cmd['target']=(string)($input['enemy_target']??'');
        dn_team_validate($b['team'],'left',$actor,$cmd);
        $initial=$presentation?dn_team_presentation_snapshot($b['team']):[];if($presentation)dn_team_presentation_begin($b['team']);
        if($action==='item'){
            if($allyKey==='')throw new RuntimeException('Choose a party Digimon.');
            $beforeItem=$presentation?dn_team_presentation_unit($b['team']['left'][$allyKey]):[];
            $cmd['message']=dn_use_recovery_item_locked($db,$uid,(int)($input['item_id']??0),$targetId);$fresh=dn_owned_instance($db,$uid,$targetId,true);
            dn_team_presentation_emit($b['team'],'action',['actor'=>$actor,'targets'=>[$allyKey],'move'=>['name'=>'Recovery capsule','type'=>'Support','element'=>'Neutral','target'=>'ally'],'offensive'=>false]);
            $b['team']['left'][$allyKey]['hp']=(int)$fresh['hp'];$b['team']['left'][$allyKey]['sp']=(int)$fresh['sp'];
            dn_team_presentation_effect($b['team'],$b['team']['left'][$allyKey],$b['team']['left'][$allyKey],'item',$beforeItem,$beforeItem);
        }
        dn_team_take_turn($b['team'],$cmd);dn_combat_advance_enemy($b);dn_combat_persist_team($db,$uid,$b);
        if($b['team']['finished'])dn_combat_finish($db,$uid,$b,$b['team']['result']);
        $visual=$presentation?(['initial'=>$initial]+dn_team_presentation_end($b['team'])+['final'=>dn_team_presentation_snapshot($b['team'])]):null;
        $b['turn']++;$b['token']=bin2hex(random_bytes(24));dn_combat_save($db,$uid,$b);$db->commit();if(!empty($b['finished']))dn_world_battle_completed($db,$uid,$b);if($visual!==null)$b['presentation']=['id'=>$b['token']]+$visual;return $b;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
require_once __DIR__.'/team_combat.php';
