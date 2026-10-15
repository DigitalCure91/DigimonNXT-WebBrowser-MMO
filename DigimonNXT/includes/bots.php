<?php
declare(strict_types=1);
require_once __DIR__.'/ranked.php';
require_once __DIR__.'/digivolution.php';
require_once __DIR__.'/world_maps.php';

/** Bot accounts are genuine persistent owners; passwords are random and never exposed. */
function dn_seed_bots(mysqli $db,int $count=1000): int {
    $count=max(1,min(1000,$count));$made=0;
    $lock='dn_bot_seed_'.substr(hash('sha256',(string)dn_row($db,'SELECT DATABASE() n')['n']),0,30);
    if((int)dn_row($db,'SELECT GET_LOCK(?,10) ok',[$lock])['ok']!==1)throw new RuntimeException('Another installation is preparing the tamers. Retry setup shortly.');
    try{
        $existing=array_column(dn_rows($db,'SELECT bot_no FROM nxt_bots'),'bot_no');$present=array_fill_keys($existing,true);
        $rookies=dn_rows($db,"SELECT id,slug FROM digimon_species WHERE stage='Rookie' AND slug NOT LIKE '%paradox%' ORDER BY id");
        if(count($rookies)<3)throw new RuntimeException('Install the Digimon catalog before preparing autonomous tamers.');
        $prefixes=['Aster','Byte','Cobalt','Dawn','Echo','Flux','Glint','Halo','Ion','Jade','Kite','Lumen','Mica','Nova','Onyx','Pulse','Quartz','Rune','Sora','Talon'];
        $avatars=array_keys(nxt_tamer_catalog());
        $password=password_hash(bin2hex(random_bytes(64)),PASSWORD_DEFAULT);$now=time();
        for($n=1;$n<=$count;$n++){
            if(isset($present[$n]))continue;
            $db->begin_transaction();try{
                $name='NXT_'.$prefixes[($n-1)%count($prefixes)].'_'.str_pad((string)$n,4,'0',STR_PAD_LEFT);
                // Never convert an existing human account if a matching name was registered earlier.
                if(dn_row($db,'SELECT id FROM members WHERE username=?',[$name]))$name='AI_'.bin2hex(random_bytes(5)).'_'.$n;
                $avatar=(int)$avatars[($n-1)%count($avatars)];
                dn_write($db,'INSERT INTO members(username,password,email,registered,money,bio,created_avatar,messonoff,messnotifyonoff) VALUES(?,?,?,?,?,?,?,?,?)',[$name,$password,'bot-'.bin2hex(random_bytes(6)).'-'.$n.'@nxt.invalid',$now,5000,'Autonomous NXT tamer · real battles, scan data and partner growth.',$avatar,0,0]);
                $uid=(int)$db->insert_id;dn_write($db,'INSERT INTO members_options(id,tamer,messonoff,messnotifyonoff) VALUES(?,?,0,0)',[$uid,$avatar]);
                dn_write($db,'INSERT INTO nxt_bots(owner_id,bot_no,next_action_at) VALUES(?,?,?)',[$uid,$n,$now]);
                for($slot=1;$slot<=3;$slot++){
                    $species=$rookies[(($n-1)*3+$slot-1)%count($rookies)];$id=dn_create_instance($db,$uid,(int)$species['id'],'starter',5);
                    dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$uid,$slot,$id]);
                }
                dn_ranked_register($db,$uid);$db->commit();$made++;
            }catch(Throwable $e){$db->rollback();throw $e;}
        }
    }finally{dn_row($db,'SELECT RELEASE_LOCK(?) ok',[$lock]);}
    return $made;
}
function dn_bot_record(mysqli $db,int $uid,string $action,string $summary,array $detail=[]): void {
    dn_write($db,'INSERT INTO nxt_bot_activity(owner_id,action,summary,detail_json,created_at) VALUES(?,?,?,?,?)',[$uid,$action,$summary,json_encode($detail,JSON_THROW_ON_ERROR),time()]);
}
/** Persist map presence from the bot identity without impersonating a browser session. */
function dn_bot_visit(mysqli $db,int $uid,string $area): void {
    $definition=pv_world_area('cyberspace',$area);if(!$definition)return;$area=(string)$definition['key'];
    $cells=pv_world_encounter_cells($definition);$blocks=pv_world_blocks($db,'cyberspace',$area);
    $cells=array_keys(array_diff_key($cells,$blocks));$cells=array_values(array_filter($cells,static function($cell)use($definition){[$x,$y]=array_map('intval',explode(':',$cell));return pv_world_transition($definition,$x,$y)===null;}));
    // Small disconnected paths receive visits too, rather than being swamped by the largest floor.
    $components=(array)(pv_world_collision_data($definition)['components']??[]);
    if($cells&&$components){$component=(int)$components[random_int(0,count($components)-1)]['id'];$local=array_values(array_filter($cells,static function($cell)use($definition,$component){[$x,$y]=array_map('intval',explode(':',$cell));return pv_world_component_at($definition,$x,$y)===$component;}));if($local)$cells=$local;}
    if($cells){$cell=$cells[random_int(0,count($cells)-1)];[$x,$y]=array_map('intval',explode(':',$cell));}
    else {[$x,$y]=pv_world_safe_spawn($definition,$blocks);}
    $member=dn_row($db,'SELECT username,created_avatar FROM members WHERE id=?',[$uid]);if(!$member)return;$now=time();
    dn_write($db,'INSERT INTO mapusers(id,username,tamer,world_key,map,x,y,time) VALUES(?,?,?,?,?,?,?,CURTIME()) ON DUPLICATE KEY UPDATE username=VALUES(username),tamer=VALUES(tamer),world_key=VALUES(world_key),map=VALUES(map),x=VALUES(x),y=VALUES(y),time=CURTIME()',[$uid,$member['username'],(int)$member['created_avatar'],'cyberspace',$area,$x,$y]);
    dn_write($db,'INSERT INTO world_map_positions(user_id,world_key,area_key,x,y,updated_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE x=VALUES(x),y=VALUES(y),updated_at=VALUES(updated_at)',[$uid,'cyberspace',$area,$x,$y,$now]);
    dn_write($db,'INSERT INTO online(id,username,activity,time,useragent,server) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE activity=VALUES(activity),time=VALUES(time),useragent=VALUES(useragent)',[$uid,$member['username'],'AI tamer exploring '.$definition['name'],$now,'NXT autonomous tamer','local']);
}
function dn_bot_shop(mysqli $db,int $uid,int $step): bool {
    $slug=$step%2?'hp-capsule-s':'sp-capsule-s';$item=dn_row($db,'SELECT id,price,stack_limit FROM items WHERE slug=? AND is_active=1',[$slug]);if(!$item)return false;
    $owner=dn_row($db,'SELECT money FROM members WHERE id=?',[$uid]);$qty=(int)(dn_row($db,'SELECT quantity FROM member_items WHERE owner_id=? AND item_id=?',[$uid,(int)$item['id']])['quantity']??0);
    if($qty>=10||(int)$owner['money']<(int)$item['price'])return false;
    $message=dn_buy_item($db,$uid,(int)$item['id'],1,bin2hex(random_bytes(24)));dn_bot_record($db,$uid,'shop',$message,['item'=>$slug,'cost'=>(int)$item['price'],'quantity'=>1]);return true;
}
function dn_bot_encounters(): array {
    return pv_world_encounter_profiles();
}
/** Every map sharing a profile is eligible; patrols no longer stop at its first map. */
function dn_bot_patrol_area(string $profile,int $number,int $step): array {
    static $groups=null;
    if($groups===null){$groups=[];foreach((array)pv_world_manifest()['areas'] as $key=>$raw){$p=(string)($raw['encounter_profile']??'');if($p!==''&&!empty($raw['ready']))$groups[$p][]=(string)$key;}}
    $keys=$groups[$profile]??[];if(!$keys)throw new RuntimeException('This patrol has no available map.');
    $at=intdiv(max(0,$number)+intdiv(max(0,$step),12),max(1,count(dn_bot_encounters())))%count($keys);
    for($i=0;$i<count($keys);$i++){$area=pv_world_area('cyberspace',$keys[($at+$i)%count($keys)]);if($area)return $area;}
    throw new RuntimeException('Patrol destinations are unavailable.');
}
/** Real wild combat: shared attack/effect engine, genuine losses and normal scan/EXP accounting. */
function dn_bot_wild(mysqli $db,int $uid,int $step): array {
    $db->begin_transaction();try{
        dn_lock_owner($db,$uid);dn_require_idle($db,$uid);$left=[];foreach(dn_party_rows($db,$uid) as $d){if((int)$d['locked_trade'])continue;$u=dn_combat_unit($d);$u['hp']=$u['max_hp'];$u['sp']=$u['max_sp'];$u['level']=(int)$d['level'];$u['moves']=dn_instance_moves($db,$d);$left[]=$u;}if(!$left)throw new RuntimeException('Bot has no available party.');
        $areas=dn_bot_encounters();$keys=array_keys($areas);if(!$keys)throw new RuntimeException('Encounter catalog unavailable.');
        // Each tamer explores repeatable local patrols to accumulate a species scan, then visits another sector.
        $bot=dn_row($db,'SELECT bot_no FROM nxt_bots WHERE owner_id=?',[$uid]);$number=(int)$bot['bot_no'];
        $profileKey=$keys[(intdiv($step,12)+$number)%count($keys)];$entries=$areas[$profileKey]['entries']??[];$destination=dn_bot_patrol_area($profileKey,$number,$step);$area=(string)$destination['key'];
        if(!$entries)throw new RuntimeException('Sector has no wild encounters.');
        $patrol=$entries[(intdiv($step,8)+$number)%count($entries)];$entry=dn_pick_wild_entry(['entries'=>[$patrol]])??$patrol;$species=dn_species_by_slug($db,(string)$entry[0]);if(!$species)throw new RuntimeException('Wild species is unavailable.');
        [$min,$max]=dn_wild_level_bounds((int)$entry[1],(int)$entry[2]);$level=random_int($min,$max);
        $danger=($step+$number)%5===0;$foes=$danger?3:random_int(1,3);$right=[];$scan=0;
        for($i=0;$i<$foes;$i++){
            $st=dn_level_stats($species,$level);$right[]=['id'=>-1-$i,'species_id'=>(int)$species['id'],'slug'=>$species['slug'],'name'=>$species['name'],'stage'=>$species['stage'],'level'=>$level,'hp'=>$st['max_hp'],'max_hp'=>$st['max_hp'],'sp'=>$st['max_sp'],'max_sp'=>$st['max_sp'],'atk'=>$st['attack'],'def'=>$st['defense'],'int'=>$st['intelligence'],'spd'=>$st['speed'],'attribute'=>$species['attribute'],'element'=>$species['element'],'effects'=>[],'moves'=>dn_species_moves($species['slug'],$level)];
            $scan+=dn_combat_scan_encounter($db,$uid,$right[$i])['reward'];
        }
        $battle=dn_team_simulate($left,$right);$won=$battle['result']==='win';$lost=$battle['result']==='loss';$result=$battle['result'];$gain=0;$bits=0;
        if($won){
            foreach($right as $u)dn_write($db,'UPDATE digimon_scan SET wins=wins+1 WHERE owner_id=? AND species_id=?',[$uid,(int)$u['species_id']]);
            $bonus=match($species['stage']){'Champion','Armor'=>150,'Ultimate'=>500,'Mega'=>1500,'Ultra'=>3000,default=>25};
            $multiplier=dn_is_paradox($species)?2:1;$gain=(25+$level*15+$bonus)*$foes*$multiplier;$bits=(20+$level*5+(int)round($bonus/10))*$foes*$multiplier;
            $share=max(1,intdiv($gain,count($left)));$gain=$share*count($left);foreach($left as $u)dn_award_exp($db,(int)$u['id'],$uid,$share);
            dn_write($db,'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.cam=LEAST(100,d.cam+1) WHERE d.owner_id=?',[$uid]);
        }
        dn_write($db,'UPDATE members SET money=money+?,battle=battle+1,wins=wins+?,losses=losses+?,points=points+? WHERE id=?',[$bits,(int)$won,(int)$lost,(int)$won,$uid]);
        dn_write($db,'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.hp=d.max_hp,d.sp=d.max_sp WHERE d.owner_id=?',[$uid]);
        dn_write($db,'UPDATE nxt_bots SET wild_wins=wild_wins+?,wild_losses=wild_losses+?,last_area=? WHERE owner_id=?',[(int)$won,(int)$lost,$area,$uid]);
        dn_record_battle($db,$uid,(int)$left[0]['id'],(int)$species['id'],'wild',$result==='draw'?'disconnect':$result,$area,$gain,$scan,$bits);
        $summary=($won?'Won':($lost?'Lost':'Drew')).' against '.$foes.' × '.$species['name'].' in '.(string)$destination['name'];
        dn_bot_record($db,$uid,'wild',$summary,['result'=>$result,'area'=>$area,'species'=>$species['name'],'species_id'=>(int)$species['id'],'foes'=>$foes,'level'=>$level,'scan'=>$scan,'exp'=>$gain,'bits'=>$bits,'rounds'=>$battle['rounds']??0,'log'=>$battle['log']??[]]);
        dn_bot_visit($db,$uid,$area);
        $db->commit();dn_world_game_event($db,$uid,'wild.complete',$summary.'; party fully restored',['result'=>$result,'area'=>$area,'species'=>$species['name'],'scan'=>$scan,'exp'=>$gain,'bits'=>$bits]);return ['action'=>'wild','result'=>$result,'scan'=>$scan];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_bot_materialize(mysqli $db,int $uid): bool {
    $scan=dn_row($db,'SELECT sc.species_id,sc.scan_percent,s.name FROM digimon_scan sc JOIN digimon_species s ON s.id=sc.species_id WHERE sc.owner_id=? AND sc.scan_percent>=100 ORDER BY sc.scan_percent DESC,sc.species_id LIMIT 1',[$uid]);
    if(!$scan)return false;
    $id=dn_materialize($db,$uid,(int)$scan['species_id']);
    dn_write($db,'UPDATE nxt_bots SET materialized=materialized+1 WHERE owner_id=?',[$uid]);
    dn_bot_record($db,$uid,'materialize','Materialized '.$scan['name'].' from '.$scan['scan_percent'].'% scan',['digimon_id'=>$id,'species_id'=>(int)$scan['species_id'],'scan_spent'=>(int)$scan['scan_percent']]);return true;
}
function dn_bot_rotate(mysqli $db,int $uid,int $step): bool {
    $collection=dn_collection_rows($db,$uid);$available=array_values(array_filter($collection,fn($d)=>!(int)$d['locked_trade']));if(count($available)<2)return false;
    $party=dn_party_rows($db,$uid);$bench=array_values(array_filter($available,fn($d)=>empty($d['slot'])));
    if($bench)$chosen=$bench[$step%count($bench)];else $chosen=$available[($step+1)%count($available)];
    $slot=1+($step%3);if(isset($party[$slot])&&(int)$party[$slot]['id']===(int)$chosen['id'])$slot=$slot%3+1;
    // Swapping two existing members must preserve both of them, unlike normal slot assignment.
    $db->begin_transaction();try{
        dn_lock_owner($db,$uid);dn_require_idle($db,$uid);$owned=dn_owned_instance($db,$uid,(int)$chosen['id'],true);if(!$owned||(int)$owned['locked_trade'])throw new RuntimeException('Partner is unavailable.');
        $party=dn_party_rows($db,$uid);$prior=0;foreach($party as $at=>$d)if((int)$d['id']===(int)$chosen['id'])$prior=(int)$at;
        $displaced=isset($party[$slot])?(int)$party[$slot]['id']:0;
        dn_write($db,'DELETE FROM digimon_party WHERE owner_id=? AND (slot=? OR digimon_id=?)',[$uid,$slot,(int)$chosen['id']]);
        dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$uid,$slot,(int)$chosen['id']]);
        if($prior&&$prior!==$slot&&$displaced)dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$uid,$prior,$displaced]);
        dn_write($db,'UPDATE nxt_bots SET party_changes=party_changes+1 WHERE owner_id=?',[$uid]);
        dn_bot_record($db,$uid,'party','Moved '.$chosen['name'].' into active slot '.$slot,['digimon_id'=>(int)$chosen['id'],'slot'=>$slot,'previous_slot'=>$prior,'replaced_id'=>$displaced]);$db->commit();dn_world_game_event($db,$uid,'party.rotate','Moved '.$chosen['name'].' into party slot '.$slot,['digimon_id'=>(int)$chosen['id'],'slot'=>$slot]);return true;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_bot_evolve(mysqli $db,int $uid): bool {
    foreach(dn_party_rows($db,$uid) as $d){
        foreach(dn_digivolution_options($d['slug']) as $rule){
            if($rule['direction']!=='up'||!empty($rule['partner']))continue;
            if(array_filter(dn_evolution_requirements($db,$uid,$d,$rule),fn($r)=>!$r['ready']))continue;
            $r=dn_digivolve($db,$uid,(int)$d['id'],$rule['target'],'up');
            dn_write($db,'UPDATE nxt_bots SET evolutions=evolutions+1 WHERE owner_id=?',[$uid]);
            dn_bot_record($db,$uid,'digivolve',$r['from'].' digivolved into '.$r['to'],['digimon_id'=>(int)$d['id'],'from'=>$r['from'],'to'=>$r['to'],'abi'=>$r['abi']]);return true;
        }
    }
    return false;
}
function dn_bot_train(mysqli $db,int $uid,int $step): bool {
    $party=array_values(dn_party_rows($db,$uid));if(!$party)return false;$d=$party[$step%count($party)];$training=dn_training($d);
    if(array_sum($training)>=50+intdiv((int)$d['abi'],2))return false;
    $stat=['hp','sp','atk','def','int','spd'][$step%6];dn_train_stat($db,$uid,(int)$d['id'],$stat,1);
    dn_bot_record($db,$uid,'training','Trained '.$d['name'].' · +1 '.strtoupper($stat),['digimon_id'=>(int)$d['id'],'stat'=>$stat,'gain'=>1]);return true;
}
/** Durable fair cursor: every enabled tamer receives a turn, with one worker at a time. */
function dn_bots_tick(mysqli $db,int $batch=80,int $minInterval=0,float $budgetSeconds=0.0): array {
    if(dn_world_setting($db,'bots.paused',false,true)||!empty(dn_world_maintenance($db,true)['enabled']))return ['processed'=>0,'skipped'=>true,'reason'=>'paused','errors'=>[]];
    $batch=max(1,min(250,$batch));$database=(string)dn_row($db,'SELECT DATABASE() n')['n'];$lock='dn_bot_tick_'.substr(hash('sha256',$database),0,30);
    if((int)dn_row($db,'SELECT GET_LOCK(?,0) ok',[$lock])['ok']!==1)return ['processed'=>0,'skipped'=>true,'reason'=>'busy','errors'=>[]];
    $done=0;$errors=[];$started=hrtime(true);$attempted=0;
    try{
        // Bots never sign in, so their temporary bans must also expire in the worker.
        try{
            $expired=dn_rows($db,'SELECT b.owner_id FROM nxt_world_bans b JOIN nxt_bots bot ON bot.owner_id=b.owner_id WHERE b.expires_at>0 AND b.expires_at<=? ORDER BY b.expires_at LIMIT 100',[time()]);
            foreach($expired as $ban)dn_world_expire_ban($db,(int)$ban['owner_id']);
        }catch(mysqli_sql_exception $e){if($e->getCode()!==1146)throw $e;}
        dn_write($db,"INSERT IGNORE INTO nxt_worker_state(worker_key) VALUES('tamers')");$state=dn_row($db,"SELECT * FROM nxt_worker_state WHERE worker_key='tamers'");$cursor=(int)$state['cursor_no'];$now=time();
        // One global cadence, checked under the same lock as CLI workers. A skipped
        // pulse must not move the heartbeat or many tabs could postpone work forever.
        $remaining=max(0,$minInterval)-($now-(int)$state['last_run']);
        if($remaining>0)return ['processed'=>0,'skipped'=>true,'reason'=>'cooldown','retry_after'=>$remaining,'errors'=>[]];
        $rows=dn_rows($db,'SELECT b.* FROM nxt_bots b JOIN members m ON m.id=b.owner_id WHERE m.banned=0 AND b.enabled=1 AND next_action_at<=? ORDER BY (bot_no<=?),bot_no LIMIT '.$batch,[$now,$cursor]);
        foreach($rows as $bot){
            // Finish the current real action, then yield before starting another.
            // The saved cursor advances only for attempted bots, including on slow hosts.
            if($attempted>0&&$budgetSeconds>0&&(hrtime(true)-$started)/1e9>=$budgetSeconds)break;
            if(dn_world_setting($db,'bots.paused',false,true)||!empty(dn_world_maintenance($db,true)['enabled']))break;
            $attempted++;
            $uid=(int)$bot['owner_id'];$step=(int)$bot['activity_step'];$cursor=(int)$bot['bot_no'];
            try{
                dn_ranked_register($db,$uid);
                $old=dn_row($db,'SELECT season_id FROM nxt_ranked_seasons WHERE owner_id=? AND season_id<? AND reward_claimed=0 AND wins+losses+draws>0 ORDER BY season_id LIMIT 1',[$uid,dn_ranked_season()]);
                if($old){$reward=dn_ranked_claim($db,$uid,(int)$old['season_id']);dn_bot_record($db,$uid,'reward','Claimed a weekly Battle Park reward',$reward);}
                $phase=($step+$cursor-1)%10;$acted=false;
                // A fixed cycle prevents ranked battles from crowding out wild exploration.
                // Stagger tamers without fabricating completed actions or starter progress.
                if(in_array($phase,[3,8],true)){
                    $p=dn_ranked_profile($db,$uid);
                    if($p['energy']>0){$ticket=dn_ranked_ticket($db,$uid);if($ticket){dn_ranked_challenge($db,$uid,$ticket['token']);$acted=true;}}
                }elseif($phase===6)$acted=dn_bot_materialize($db,$uid);
                elseif($phase===9)$acted=dn_bot_evolve($db,$uid)||dn_bot_rotate($db,$uid,$step);
                elseif($phase===4&&$step>0&&intdiv($step,10)%3===2)$acted=dn_bot_train($db,$uid,$step);
                elseif($phase===4&&$step>0&&intdiv($step,10)%3===1)$acted=dn_bot_shop($db,$uid,$step);
                if(!$acted)dn_bot_wild($db,$uid,$step);
                dn_write($db,'UPDATE nxt_bots SET activity_step=activity_step+1,last_active=?,next_action_at=? WHERE owner_id=?',[$now,$now+120+($cursor%61),$uid]);$done++;
            }catch(Throwable $e){
                $message='Tamer '.$cursor.': '.$e->getMessage();$errors[]=$message;pv_log('Bot worker '.$message);
                dn_write($db,'UPDATE nxt_bots SET next_action_at=? WHERE owner_id=?',[$now+180,$uid]);
            }
            dn_write($db,"UPDATE nxt_worker_state SET cursor_no=?,last_run=?,processed=processed+1 WHERE worker_key='tamers'",[$cursor,time()]);
        }
        dn_write($db,"UPDATE nxt_worker_state SET last_run=?,last_error=? WHERE worker_key='tamers'",[time(),substr(implode(' | ',array_slice($errors,0,2)),0,255)]);
        // Feed is bounded to thirty days; full match and battle histories remain durable.
        if($cursor%100===0)dn_write($db,'DELETE FROM nxt_bot_activity WHERE created_at<? LIMIT 5000',[time()-2592000]);
        return ['processed'=>$done,'skipped'=>false,'cursor'=>$cursor,'errors'=>$errors];
    }finally{dn_row($db,'SELECT RELEASE_LOCK(?) ok',[$lock]);}
}
