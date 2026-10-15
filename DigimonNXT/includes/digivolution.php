<?php
declare(strict_types=1);
require_once __DIR__.'/digimon.php';
require_once __DIR__.'/community.php';

function dn_digivolution_rules(): array {
    static $rules; if($rules===null)$rules=json_decode((string)file_get_contents(dirname(__DIR__).'/config/digivolution_rules.json'),true,512,JSON_THROW_ON_ERROR);return $rules;
}
function dn_digivolution_options(string $slug): array {
    $options=[];
    foreach(dn_digivolution_rules() as $rule){
        if($rule['from']===$slug){$rule['direction']=!empty($rule['mode_change'])?'mode':'up';$rule['target']=$rule['to'];$options[]=$rule;}
        if($rule['to']===$slug)$options[]=['from'=>$slug,'to'=>$rule['from'],'target'=>$rule['from'],'direction'=>!empty($rule['mode_change'])?'mode':'down','mode_change'=>!empty($rule['mode_change']),'origin'=>$rule['origin']??'Cyber Sleuth','level'=>1,'abi'=>0,'cam'=>0,'stats'=>[],'bits'=>0];
    }
    return $options;
}
function dn_digivolution_rule_for(string $slug): ?array {return dn_digivolution_options($slug)[0]??null;}
function dn_evolution_requirements(mysqli $db,int $uid,array $d,array $rule): array {
    $requirements=[];
    foreach(['level'=>'Level','abi'=>'ABI','cam'=>'CAM'] as $key=>$label)if((int)($rule[$key]??0)>0)$requirements[]=['label'=>$label,'value'=>(int)$d[$key],'need'=>(int)$rule[$key],'ready'=>(int)$d[$key]>=(int)$rule[$key]];
    $map=['hp'=>'max_hp','sp'=>'max_sp','atk'=>'attack_stat','def'=>'defense_stat','int'=>'intelligence_stat','spd'=>'speed_stat'];
    foreach($rule['stats']??[] as $key=>$need)$requirements[]=['label'=>strtoupper($key),'value'=>(int)$d[$map[$key]],'need'=>(int)$need,'ready'=>(int)$d[$map[$key]]>=(int)$need];
    if($rule['direction']==='down'||!empty($rule['discovery_required'])){
        $to=dn_species_by_slug($db,$rule['target']);$scan=$to?dn_scan_row($db,$uid,(int)$to['id']):[];
        $requirements[]=['label'=>'Target discovered','value'=>(int)($scan['first_seen']??0)>0?1:0,'need'=>1,'ready'=>(int)($scan['first_seen']??0)>0];
    }
    $requirements[]=['label'=>'Conscious and available','value'=>(int)$d['hp']>0&&!(int)$d['locked_trade']?1:0,'need'=>1,'ready'=>(int)$d['hp']>0&&!(int)$d['locked_trade']];
    return $requirements;
}
function dn_digivolve(mysqli $db,int $uid,int $digimonId,string $target='',string $direction='up',int $partnerId=0): array {
    $db->begin_transaction();try{
        dn_lock_owner($db,$uid);dn_require_idle($db,$uid);$d=dn_owned_instance($db,$uid,$digimonId,true);
        if(!$d)throw new RuntimeException('Digimon not found.');
        $rule=null;foreach(dn_digivolution_options($d['slug']) as $option)if($option['target']===$target&&$option['direction']===$direction){$rule=$option;break;}
        if(!$rule)throw new RuntimeException('That evolution route is unavailable.');
        foreach(dn_evolution_requirements($db,$uid,$d,$rule) as $r)if(!$r['ready'])throw new RuntimeException($r['label'].' requirement is not met ('.$r['value'].' / '.$r['need'].').');
        $to=dn_species_by_slug($db,$target);if(!$to)throw new RuntimeException('Evolution target is unavailable.');
        $partner=null;
        if(!empty($rule['partner'])){
            $partner=dn_owned_instance($db,$uid,$partnerId,true);
            if(!$partner||$partnerId===$digimonId||$partner['slug']!==$rule['partner']||(int)$partner['cam']<100||(int)$partner['locked_trade']||(int)$partner['hp']<=0)throw new RuntimeException('DNA evolution requires the specified conscious partner at 100 CAM, available in your collection.');
        }
        dn_learn_moves($db,$digimonId,$d['slug'],(int)$d['level']);
        if($partner){
            dn_learn_moves($db,$partnerId,$partner['slug'],(int)$partner['level']);
            dn_write($db,'INSERT IGNORE INTO digimon_learned_moves(digimon_id,move_slug) SELECT ?,move_slug FROM digimon_learned_moves WHERE digimon_id=?',[$digimonId,$partnerId]);
            dn_write($db,'DELETE FROM digimon_instances WHERE id=? AND owner_id=?',[$partnerId,$uid]);
        }
        $mode=!empty($rule['mode_change']);$newLevel=$mode?(int)$d['level']:1;$newExp=$mode?(int)$d['exp']:0;
        $gain=$mode?0:min(DN_ABI_MAX-(int)$d['abi'],dn_abi_gain($d['stage'],$to['stage'],(int)$d['level'],$direction));$abi=(int)$d['abi']+$gain;
        $st=dn_level_stats($to,$newLevel,0,dn_training($d));
        $hp=$mode?max(1,(int)floor((int)$d['hp']*$st['max_hp']/max(1,(int)$d['max_hp']))):$st['max_hp'];$sp=$mode?(int)floor((int)$d['sp']*$st['max_sp']/max(1,(int)$d['max_sp'])):$st['max_sp'];
        dn_write($db,'UPDATE digimon_instances SET species_id=?,level=?,exp=?,abi=?,hp=?,max_hp=?,sp=?,max_sp=?,attack_stat=?,defense_stat=?,intelligence_stat=?,speed_stat=? WHERE id=? AND owner_id=?',[(int)$to['id'],$newLevel,$newExp,$abi,$hp,$st['max_hp'],$sp,$st['max_sp'],$st['attack'],$st['defense'],$st['intelligence'],$st['speed'],$digimonId,$uid]);
        dn_learn_moves($db,$digimonId,$target,$newLevel);dn_discover_species($db,$uid,(int)$to['id']);
        dn_write($db,'INSERT INTO digivolution_log(owner_id,digimon_id,from_species_id,to_species_id,level_at_change,bits_spent,created_at,abi_gained,direction) VALUES(?,?,?,?,?,0,?,?,?)',[$uid,$digimonId,(int)$d['species_id'],(int)$to['id'],(int)$d['level'],time(),$gain,$direction]);
        $db->commit();dn_world_game_event($db,$uid,'digimon.evolve',$d['name'].' changed into '.$to['name'].' ('.$direction.')',['digimon_id'=>$digimonId,'species'=>$to['name']]);return ['from'=>$d['name'],'to'=>$to['name'],'cost'=>0,'gain'=>$gain,'abi'=>$abi,'direction'=>$direction,'level'=>$newLevel];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_train_stat(mysqli $db,int $uid,int $id,string $stat,int $delta): void {
    if(!in_array($stat,['hp','sp','atk','def','int','spd'],true)||!in_array($delta,[-5,-1,1,5],true))throw new RuntimeException('Choose a valid training adjustment.');
    $db->begin_transaction();try {
        dn_lock_owner($db,$uid);dn_require_idle($db,$uid);$d=dn_owned_instance($db,$uid,$id,true);
        if(!$d||(int)$d['locked_trade'])throw new RuntimeException('That Digimon is unavailable for training.');
        $training=dn_training($d);$training[$stat]=(int)($training[$stat]??0)+$delta;
        if($training[$stat]<0||array_sum($training)>50+intdiv((int)$d['abi'],2))throw new RuntimeException('Training must stay within your available bonus points.');
        $st=dn_level_stats($d,(int)$d['level'],0,$training);$hp=(int)$d['hp']===0?0:max(1,(int)floor((int)$d['hp']*$st['max_hp']/max(1,(int)$d['max_hp'])));$sp=(int)floor((int)$d['sp']*$st['max_sp']/max(1,(int)$d['max_sp']));
        dn_write($db,'UPDATE digimon_instances SET training_json=?,hp=?,max_hp=?,sp=?,max_sp=?,attack_stat=?,defense_stat=?,intelligence_stat=?,speed_stat=? WHERE id=? AND owner_id=?',[json_encode($training,JSON_THROW_ON_ERROR),$hp,$st['max_hp'],$sp,$st['max_sp'],$st['attack'],$st['defense'],$st['intelligence'],$st['speed'],$id,$uid]);
        $db->commit();dn_world_game_event($db,$uid,'digimon.train','Trained '.$d['name'].' '.$stat.' '.($delta>0?'+':'').$delta,['digimon_id'=>$id]);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
