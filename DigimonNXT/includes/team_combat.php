<?php
declare(strict_types=1);
/** Shared, database-free three-active combat rules used by wild, ranked and bot battles.
 * Load combat.php first for the established damage/status primitives.
 */
/** Opt-in transient presentation: enabled only when a battle needs playback. */
function dn_team_presentation_begin(array &$s,int $limit=1024): void {
    $s['_presentation']=['events'=>[],'limit'=>max(16,min(2048,$limit)),'truncated'=>false];
}
function dn_team_presentation_end(array &$s): array {
    $p=$s['_presentation']??['events'=>[],'truncated'=>false];unset($s['_presentation']);
    return ['events'=>$p['events'],'truncated'=>(bool)$p['truncated']];
}
function dn_team_presentation_unit(array $u): array {
    return ['key'=>(string)($u['key']??''),'hp'=>(int)$u['hp'],'max_hp'=>(int)$u['max_hp'],'sp'=>(int)$u['sp'],'max_sp'=>(int)$u['max_sp'],'effects'=>array_values($u['effects']??[]),'guarding'=>(bool)($u['guarding']??false)];
}
function dn_team_presentation_snapshot(array $s): array {
    $snapshot=['left'=>[],'right'=>[],'active_left'=>$s['active_left'],'active_right'=>$s['active_right'],'finished'=>(bool)$s['finished'],'result'=>(string)$s['result'],'round'=>(int)$s['round']];
    foreach(['left','right'] as $side)foreach($s[$side] as $key=>$u){
        $snapshot[$side][$key]=dn_team_presentation_unit($u)+['id'=>(int)($u['id']??0),'slug'=>(string)($u['slug']??''),'name'=>(string)($u['name']??'Digimon'),'sprite'=>(string)($u['sprite']??''),'level'=>(int)($u['level']??1),'attribute'=>(string)($u['attribute']??'Free'),'element'=>(string)($u['element']??'Neutral')];
    }
    return $snapshot;
}
function dn_team_presentation_emit(array &$s,string $type,array $data=[],array $units=[]): void {
    if(!isset($s['_presentation']))return;
    $p=&$s['_presentation'];if(count($p['events'])>=$p['limit']){$p['truncated']=true;return;}
    $patch=[];foreach($units as $u)if(!empty($u['key']))$patch[$u['key']]=dn_team_presentation_unit($u);
    $p['events'][]=['type'=>$type,'sequence'=>count($p['events'])]+$data+['units'=>$patch];
}
function dn_team_presentation_effect(array &$s,array $actor,array $target,string $kind,array $beforeActor,array $beforeTarget): void {
    if(!isset($s['_presentation']))return;
    $actorAfter=dn_team_presentation_unit($actor);$targetAfter=dn_team_presentation_unit($target);
    if($actorAfter===$beforeActor&&$targetAfter===$beforeTarget)return;
    $changes=[];
    foreach([[$beforeActor,$actorAfter],[$beforeTarget,$targetAfter]] as [$before,$after]){
        $key=$after['key'];if($key===''||isset($changes[$key])||$before===$after)continue;
        $changes[$key]=['hp_change'=>$after['hp']-$before['hp'],'sp_change'=>$after['sp']-$before['sp'],'effects_before'=>$before['effects'],'effects_after'=>$after['effects']];
    }
    dn_team_presentation_emit($s,'effect',['actor'=>$actorAfter['key'],'target'=>$targetAfter['key'],'effect'=>$kind,'changes'=>$changes],[$actor,$target]);
    foreach($changes as $key=>$change){$before=$key===$beforeActor['key']?$beforeActor:$beforeTarget;$after=$key===$actorAfter['key']?$actorAfter:$targetAfter;if($before['hp']>0&&$after['hp']<=0)dn_team_presentation_emit($s,'ko',['target'=>$key]);}
}
function dn_team_create(array $left,array $right): array {
    $s=['version'=>1,'left'=>[],'right'=>[],'active_left'=>[],'active_right'=>[],'queue'=>[],'round'=>0,'log'=>[],'finished'=>false,'result'=>''];
    foreach(['left'=>$left,'right'=>$right] as $side=>$units){
        foreach(array_slice(array_values($units),0,6) as $i=>$u){
            $key=substr($side,0,1).$i;$u['key']=$key;$u['effects']=$u['effects']??[];$u['guarding']=false;
            $u['level']=(int)($u['level']??1);$u['moves']=$u['moves']??dn_species_moves((string)$u['slug'],$u['level']);
            $s[$side][$key]=$u;
        }
    }
    dn_team_refill($s);return $s;
}
function dn_team_refill(array &$s): void {
    $oldLeft=$s['active_left'];$oldRight=$s['active_right'];$wasFinished=$s['finished'];
    foreach(['left','right'] as $side){
        $active=array_values(array_filter($s['active_'.$side],fn($k)=>isset($s[$side][$k])&&$s[$side][$k]['hp']>0));
        foreach($s[$side] as $key=>$u){if(count($active)>=3)break;if($u['hp']>0&&!in_array($key,$active,true))$active[]=$key;}
        $s['active_'.$side]=$active;
    }
    if($oldLeft!==$s['active_left']||$oldRight!==$s['active_right'])dn_team_presentation_emit($s,'lineup',['active_left'=>$s['active_left'],'active_right'=>$s['active_right']],array_merge(array_values($s['left']),array_values($s['right'])));
    if(!$s['active_left']||!$s['active_right']){$s['finished']=true;$s['result']=!$s['active_left']?(!$s['active_right']?'draw':'loss'):'win';}
    if(!$wasFinished&&$s['finished'])dn_team_presentation_emit($s,'complete',['result'=>$s['result']]);
}
function dn_team_next(array &$s): ?array {
    dn_team_refill($s);if($s['finished'])return null;
    for(;;){
        if(!$s['queue']){
            $s['round']++;$queue=[];
            foreach(['left','right'] as $side)foreach($s['active_'.$side] as $key)$queue[]=['side'=>$side,'key'=>$key,'speed'=>dn_effect_stat($s[$side][$key],'spd'),'tie'=>random_int(0,PHP_INT_MAX)];
            usort($queue,fn($a,$b)=>($b['speed']<=>$a['speed'])?:($a['tie']<=>$b['tie']));$s['queue']=$queue;
        }
        $next=$s['queue'][0];
        if($s[$next['side']][$next['key']]['hp']>0&&in_array($next['key'],$s['active_'.$next['side']],true))return $next;
        array_shift($s['queue']);
    }
}
function dn_team_is_damage(array $move): bool {return (int)($move['power']??0)>0||!empty($move['fixed'])||!empty($move['pierce'])||!empty($move['percent_hp']);}
function dn_team_is_revive(array $move): bool {return (bool)array_filter($move['effects']??[],fn($e)=>$e['kind']==='revive');}
function dn_team_targets(array $s,string $side,string $actor,array $move,string $requested=''): array {
    $kind=$move['target']??'foe';$friend=in_array($kind,['self','ally','all_allies'],true);$targetSide=$friend?$side:($side==='left'?'right':'left');
    $revive=dn_team_is_revive($move);$pool=$revive&&$friend?array_keys($s[$targetSide]):$s['active_'.$targetSide];
    $pool=array_values(array_filter($pool,fn($k)=>$revive&&$friend?$s[$targetSide][$k]['hp']<=0:$s[$targetSide][$k]['hp']>0));
    if($kind==='self')$keys=[$actor];
    elseif(in_array($kind,['all_foes','all_allies'],true))$keys=$pool;
    else {$target=$requested!==''?$requested:($friend&&in_array($actor,$pool,true)?$actor:($pool[0]??''));$keys=in_array($target,$pool,true)?[$target]:[];}
    if(!$keys)throw new RuntimeException($friend?'Choose an eligible allied Digimon.':'Choose a conscious enemy Digimon.');
    return ['side'=>$targetSide,'keys'=>$keys];
}
function dn_team_validate(array $s,string $side,string $key,array $cmd): array {
    $a=$s[$side][$key];$action=$cmd['action']??'attack';
    if(!in_array($action,['attack','skill','guard','switch','flee','item'],true))throw new RuntimeException('Unknown battle command.');
    if($action==='switch'){
        $reserve=(string)($cmd['reserve']??'');
        if(!isset($s[$side][$reserve])||$s[$side][$reserve]['hp']<=0||in_array($reserve,$s['active_'.$side],true))throw new RuntimeException('Choose a conscious reserve Digimon.');
    }
    $move=$action==='skill'?($a['moves'][(string)($cmd['move_slug']??'')]??null):dn_basic_move();
    if($action==='skill'){
        if(!$move)throw new RuntimeException('That skill has not been learned by this Digimon.');
        if(dn_has_effect($a,'dot'))throw new RuntimeException('Skills are unavailable while this Digimon is dotted.');
        if(($move['type']??'')==='Support'&&dn_has_effect($a,'support_block'))throw new RuntimeException('Support skills are currently blocked.');
        if($a['sp']<(int)$move['sp'])throw new RuntimeException('Not enough SP for '.$move['name'].'.');
    }
    $targets=in_array($action,['attack','skill'],true)?dn_team_targets($s,$side,$key,$move,(string)($cmd['target']??'')):[];
    return ['move'=>$move,'targets'=>$targets];
}
function dn_team_effect(array &$actor,array &$target,array $e,int $damage,array &$s): void {
    if(random_int(1,100)>(int)($e['chance']??100))return;
    $beforeActor=isset($s['_presentation'])?dn_team_presentation_unit($actor):[];$beforeTarget=isset($s['_presentation'])?dn_team_presentation_unit($target):[];
    $amount=(int)($e['amount']??0);
    switch($e['kind']){
        case 'drain_hp':$actor['hp']=min($actor['max_hp'],$actor['hp']+(int)floor($damage*$amount/100));break;
        case 'drain_sp':$n=min($target['sp'],(int)floor($damage*$amount/100));$target['sp']-=$n;$actor['sp']=min($actor['max_sp'],$actor['sp']+$n);break;
        case 'heal':if($target['hp']>0)$target['hp']=min($target['max_hp'],$target['hp']+$amount);break;
        case 'revive':if($target['hp']<=0)$target['hp']=max(1,(int)round($target['max_hp']*$amount/100));break;
        case 'cure':$status=(string)($e['status']??'all');if($status==='all')$target['effects']=array_values(array_filter($target['effects'],fn($v)=>!in_array($v['kind'],['poison','panic','paralysis','stun','sleep','bug','dot','support_block'],true)));else dn_clear_effect($target,$status);if($target['hp']>0)$target['hp']=min($target['max_hp'],$target['hp']+$amount);break;
        case 'recoil_damage':$actor['hp']=max(1,$actor['hp']-(int)ceil($actor['max_hp']*$amount/100));break;
        case 'random_status':$statuses=['poison','panic','paralysis','sleep','bug','dot'];if(!dn_has_effect($target,'barrier'))dn_add_effect($target,['kind'=>$statuses[array_rand($statuses)],'turns'=>3]);break;
        case 'clear_buffs':dn_clear_effect($target,'buff');break;
        case 'clear_debuffs':dn_clear_effect($target,'debuff');break;
        case 'death':if(!dn_has_effect($target,'barrier')&&!dn_has_effect($target,'invulnerable')&&!dn_is_paradox($target))$target['hp']=0;break;
        case 'escape':$s['finished']=true;$s['result']='disconnect';break;
        default:
            if(in_array($e['kind'],['poison','panic','paralysis','stun','sleep','bug','dot','support_block'],true)&&dn_has_effect($target,'barrier'))break;
            if($target['hp']>0)dn_add_effect($target,$e);
    }
    dn_team_presentation_effect($s,$actor,$target,(string)$e['kind'],$beforeActor,$beforeTarget);
}
function dn_team_apply_move(array &$s,string $side,string $key,array $move,array $targets): void {
    $actor=&$s[$side][$key];$actor['sp']-=(int)$move['sp'];$offensive=dn_team_is_damage($move);$totalDamage=0;$supportTarget=in_array($move['target']??'foe',['ally','all_allies'],true);
    dn_team_presentation_emit($s,'action',['actor'=>$key,'targets'=>$targets['keys'],'move'=>['name'=>(string)$move['name'],'type'=>(string)($move['type']??'Physical'),'element'=>(string)($move['element']??'Neutral'),'target'=>(string)($move['target']??'foe')],'offensive'=>$offensive],[$actor]);
    foreach($targets['keys'] as $targetKey){
        $target=&$s[$targets['side']][$targetKey];$damage=0;$hit=true;
        if(!$offensive&&!in_array($move['target']??'foe',['self','ally','all_allies'],true))$hit=random_int(1,100)<=(int)($move['accuracy']??100);
        if(!$hit)dn_team_presentation_emit($s,'hit',['actor'=>$key,'target'=>$targetKey,'damage'=>0,'rolled_damage'=>0,'critical'=>false,'effectiveness'=>1.0,'missed'=>true,'blocked'=>false,'guarded'=>false,'endured'=>false,'hit_index'=>1,'hit_count'=>1],[$target]);
        if($offensive){
            $hits=random_int((int)($move['hits'][0]??1),(int)($move['hits'][1]??1));
            for($i=0;$i<$hits&&$target['hp']>0;$i++){
                $detail=null;$beforeHp=(int)$target['hp'];$amount=dn_combat_damage($actor,$target,$move,!empty($target['guarding']),$detail);$actual=min($target['hp'],$amount);$damage+=$actual;$target['hp']=max(0,$target['hp']-$amount);
                dn_team_presentation_emit($s,'hit',['actor'=>$key,'target'=>$targetKey,'damage'=>$actual,'rolled_damage'=>$amount,'hp_before'=>$beforeHp,'hit_index'=>$i+1,'hit_count'=>$hits]+$detail,[$target]);
                if($target['hp']<=0)dn_team_presentation_emit($s,'ko',['target'=>$targetKey]);
            }
            $hit=$damage>0;if($hit)dn_clear_effect($target,'sleep');
            if($hit&&((dn_has_effect($target,'counter')&&$move['type']==='Physical')||(dn_has_effect($target,'counter_magic')&&$move['type']==='Magic'))){
                $counter=max(1,(int)round($damage*.5));$beforeHp=(int)$actor['hp'];$actor['hp']=max(0,$actor['hp']-$counter);dn_combat_log($s,$target['name'].' countered for '.$counter.' damage.');
                dn_team_presentation_emit($s,'action',['actor'=>$targetKey,'targets'=>[$key],'move'=>['name'=>'Counter','type'=>(string)$move['type'],'element'=>'Neutral','target'=>'foe'],'offensive'=>true,'reason'=>'counter']);
                dn_team_presentation_emit($s,'hit',['actor'=>$targetKey,'target'=>$key,'damage'=>$beforeHp-$actor['hp'],'rolled_damage'=>$counter,'hp_before'=>$beforeHp,'critical'=>false,'effectiveness'=>1.0,'missed'=>false,'blocked'=>false,'guarded'=>false,'endured'=>false,'hit_index'=>1,'hit_count'=>1,'reason'=>'counter'],[$actor]);
                if($beforeHp>0&&$actor['hp']<=0)dn_team_presentation_emit($s,'ko',['target'=>$key]);
            }
        }
        dn_combat_log($s,$actor['name'].' → '.$target['name'].' · '.$move['name'].($offensive?' · '.($hit?$damage.' damage':'missed'):($hit?'':' · missed')).'.');
        $totalDamage+=$damage;
        foreach($move['effects']??[] as $e){
            if((!$supportTarget&&($e['target']??'foe')==='self'&&$e['kind']!=='drain_sp')||in_array($e['kind'],['drain_hp','recoil_damage'],true))continue;
            if(!$hit)continue;
            dn_team_effect($actor,$target,$e,$damage,$s);
        }
        unset($target);
    }
    if($offensive)dn_clear_effect($actor,'acceleration');
    foreach($move['effects']??[] as $e)if((!$supportTarget&&($e['target']??'foe')==='self'&&$e['kind']!=='drain_sp')||in_array($e['kind'],['drain_hp','recoil_damage'],true))dn_team_effect($actor,$actor,$e,$totalDamage,$s);
    unset($actor);
}
function dn_team_take_turn(array &$s,array $cmd): void {
    $next=dn_team_next($s);if(!$next)throw new RuntimeException('This battle is already complete.');
    $side=$next['side'];$key=$next['key'];$validated=dn_team_validate($s,$side,$key,$cmd);$action=$cmd['action']??'attack';array_shift($s['queue']);
    $a=&$s[$side][$key];$a['guarding']=false;
    if($action==='flee'){$s['finished']=true;$s['result']='disconnect';dn_combat_log($s,'The tamer disconnected from the wild signal.');dn_team_presentation_emit($s,'flee',['actor'=>$key]);}
    elseif($action==='item'){dn_combat_log($s,(string)($cmd['message']??'A recovery capsule was used.'));}
    elseif(dn_combat_before_turn($a,$s)){
        if($action==='guard'){$a['guarding']=true;dn_combat_log($s,$a['name'].' guards until its next action.');dn_team_presentation_emit($s,'guard',['actor'=>$key],[$a]);}
        elseif($action==='switch'){$reserve=(string)$cmd['reserve'];$slot=array_search($key,$s['active_'.$side],true);$s['active_'.$side][$slot]=$reserve;dn_combat_log($s,$a['name'].' withdraws; '.$s[$side][$reserve]['name'].' enters.');dn_team_presentation_emit($s,'switch',['actor'=>$key,'target'=>$reserve,'side'=>$side,'slot'=>$slot,'active_left'=>$s['active_left'],'active_right'=>$s['active_right']],[$a,$s[$side][$reserve]]);}
        elseif(dn_has_effect($a,'panic')&&random_int(1,100)<=30){
            $self=$a;$basic=dn_basic_move();$detail=null;$beforeHp=(int)$a['hp'];$damage=dn_combat_damage($a,$self,$basic,false,$detail);$a['hp']=max(0,$a['hp']-$damage);dn_combat_log($s,$a['name'].' hurt itself in confusion · '.$damage.' damage.');
            dn_team_presentation_emit($s,'action',['actor'=>$key,'targets'=>[$key],'move'=>['name'=>'Confusion','type'=>'Physical','element'=>'Neutral','target'=>'self'],'offensive'=>true,'reason'=>'panic']);
            dn_team_presentation_emit($s,'hit',['actor'=>$key,'target'=>$key,'damage'=>$beforeHp-$a['hp'],'rolled_damage'=>$damage,'hp_before'=>$beforeHp,'hit_index'=>1,'hit_count'=>1,'reason'=>'panic']+$detail,[$a]);
            if($a['hp']<=0)dn_team_presentation_emit($s,'ko',['target'=>$key]);
        }
        else dn_team_apply_move($s,$side,$key,$validated['move'],$validated['targets']);
    }
    dn_combat_tick($a);unset($a);if(!$s['finished'])dn_team_refill($s);
    if(isset($s['_presentation']))dn_team_presentation_emit($s,'state',['active_left'=>$s['active_left'],'active_right'=>$s['active_right'],'finished'=>$s['finished'],'result'=>$s['result'],'round'=>$s['round']],array_merge(array_values($s['left']),array_values($s['right'])));
}
function dn_team_ai_command(array $s,string $side,string $key): array {
    $a=$s[$side][$key];$other=$side==='left'?'right':'left';$foes=$s['active_'.$other];
    usort($foes,fn($x,$y)=>($s[$other][$x]['hp']/$s[$other][$x]['max_hp'])<=>($s[$other][$y]['hp']/$s[$other][$y]['max_hp']));
    $best=['action'=>'attack','target'=>$foes[0]??''];$score=dn_team_is_damage(dn_basic_move())?40:0;
    if(dn_has_effect($a,'dot'))return $best;
    foreach($a['moves'] as $slug=>$m){
        if((int)$m['sp']>$a['sp']||(($m['type']??'')==='Support'&&dn_has_effect($a,'support_block')))continue;
        if(array_filter($m['effects']??[],fn($e)=>$e['kind']==='escape'))continue;
        $candidate=['action'=>'skill','move_slug'=>$slug];$value=0;$target='';
        if(dn_team_is_revive($m)){
            foreach($s[$side] as $k=>$u)if($u['hp']<=0){$target=$k;$value=250;break;}
        }elseif(array_filter($m['effects']??[],fn($e)=>in_array($e['kind'],['heal','cure'],true))){
            foreach($s['active_'.$side] as $k){$u=$s[$side][$k];$missing=1-$u['hp']/$u['max_hp'];if($missing>.3&&$missing*220>$value){$target=$k;$value=$missing*220;}}
        }elseif(dn_team_is_damage($m)){
            $target=$foes[0]??'';$value=(float)($m['power']??0)+(!empty($m['pierce'])?100:0)+(!empty($m['fixed'])?(float)$m['fixed']*.6:0)+(!empty($m['percent_hp'])?65:0);
            $value*=($m['hits'][0]??1);$value*=($m['target']??'')==='all_foes'?count($foes)*.8:1;$value*=.85+random_int(0,30)/100;
        }elseif(random_int(1,100)<=12){$value=45;$target=in_array($m['target']??'foe',['self','ally','all_allies'],true)?$key:($foes[0]??'');}
        if($value>$score){$candidate['target']=$target;try{dn_team_validate($s,$side,$key,$candidate);$best=$candidate;$score=$value;}catch(RuntimeException){}}
    }
    return $best;
}
/** Returns a finite match with per-action logs; never writes to a database. */
function dn_team_simulate(array $left,array $right,int $maxRounds=60,bool $presentation=false): array {
    $s=dn_team_create($left,$right);$initial=$presentation?dn_team_presentation_snapshot($s):[];if($presentation)dn_team_presentation_begin($s);$maxRounds=max(1,min(200,$maxRounds));$actions=0;
    while(!$s['finished']&&$actions<$maxRounds*6){$next=dn_team_next($s);if(!$next||$s['round']>$maxRounds)break;dn_team_take_turn($s,dn_team_ai_command($s,$next['side'],$next['key']));$actions++;}
    if(!$s['finished']){$s['finished']=true;$s['result']='draw';dn_combat_log($s,'Round limit reached · draw.');}
    $s['rounds']=min($s['round'],$maxRounds);$s['actions']=$actions;if($presentation)$s['presentation']=['initial'=>$initial]+dn_team_presentation_end($s)+['final'=>dn_team_presentation_snapshot($s)];return $s;
}
