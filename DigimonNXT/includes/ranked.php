<?php
declare(strict_types=1);
require_once __DIR__.'/combat.php';

const DN_RANKED_ENERGY_MAX = 5;
const DN_RANKED_REGEN_SECONDS = 1800;

/** NXT rules: weekly 3v3 asynchronous Battle Park, Monday 00:00 UTC. */
function dn_ranked_season(?int $at=null): int {
    $at??=time();$day=intdiv($at,86400);$monday=$day-(($day+3)%7);return $monday*86400;
}
function dn_ranked_tier(int $points): string {
    foreach([2000=>'S',1500=>'A',1100=>'B',800=>'C',550=>'D',350=>'E',200=>'F',100=>'G',40=>'H',0=>'I'] as $at=>$label)if($points>=$at)return $label;
    return 'I';
}
function dn_ranked_energy(array $profile,?int $now=null): array {
    $now??=time();$energy=max(0,min(DN_RANKED_ENERGY_MAX,(int)$profile['energy']));$at=min($now,(int)$profile['energy_at']);
    if($energy>=DN_RANKED_ENERGY_MAX)return ['energy'=>DN_RANKED_ENERGY_MAX,'energy_at'=>$now,'next_in'=>0];
    $ticks=intdiv(max(0,$now-$at),DN_RANKED_REGEN_SECONDS);$energy=min(DN_RANKED_ENERGY_MAX,$energy+$ticks);
    $at=$energy===DN_RANKED_ENERGY_MAX?$now:$at+$ticks*DN_RANKED_REGEN_SECONDS;
    return ['energy'=>$energy,'energy_at'=>$at,'next_in'=>$energy===DN_RANKED_ENERGY_MAX?0:max(0,DN_RANKED_REGEN_SECONDS-($now-$at))];
}
function dn_ranked_register(mysqli $db,int $uid): void {
    $now=time();dn_write($db,'INSERT IGNORE INTO nxt_ranked_profiles(owner_id,energy_at,joined_at) VALUES(?,?,?)',[$uid,$now,$now]);
    dn_write($db,'INSERT IGNORE INTO nxt_ranked_seasons(season_id,owner_id,updated_at) VALUES(?,?,?)',[dn_ranked_season($now),$uid,$now]);
}
function dn_ranked_profile(mysqli $db,int $uid): array {
    dn_ranked_register($db,$uid);$p=dn_row($db,'SELECT p.*,s.points,s.streak,s.best_streak,s.wins season_wins,s.losses season_losses,s.draws season_draws,s.defense_wins,s.defense_losses FROM nxt_ranked_profiles p JOIN nxt_ranked_seasons s ON s.owner_id=p.owner_id AND s.season_id=? WHERE p.owner_id=?',[dn_ranked_season(),$uid]);
    return array_merge($p,dn_ranked_energy($p));
}
function dn_ranked_position(mysqli $db,int $uid,?int $season=null): int {
    $season??=dn_ranked_season();$s=dn_row($db,'SELECT points,wins FROM nxt_ranked_seasons WHERE season_id=? AND owner_id=?',[$season,$uid]);if(!$s)return 0;
    return 1+(int)dn_row($db,'SELECT COUNT(*) n FROM nxt_ranked_seasons s JOIN members m ON m.id=s.owner_id WHERE s.season_id=? AND m.banned=0 AND (s.points>? OR (s.points=? AND s.wins>?) OR (s.points=? AND s.wins=? AND s.owner_id<?))',[$season,(int)$s['points'],(int)$s['points'],(int)$s['wins'],(int)$s['points'],(int)$s['wins'],$uid])['n'];
}
function dn_ranked_team(mysqli $db,int $uid): array {
    $team=[];
    foreach(dn_party_rows($db,$uid) as $d){
        if((int)$d['locked_trade'])continue;
        $u=dn_combat_unit($d);$u['hp']=$u['max_hp'];$u['sp']=$u['max_sp'];$u['level']=(int)$d['level'];$u['moves']=dn_instance_moves($db,$d);$u['sprite']=$d['sprite'];$u['species_id']=(int)$d['species_id'];$u['name']=$d['nickname']?:$d['name'];$team[]=$u;
        if(count($team)===3)break;
    }
    return $team;
}
function dn_ranked_strength(array $team): int {
    $sum=0;foreach($team as $u)$sum+=(int)$u['max_hp']+4*((int)$u['atk']+(int)$u['def']+(int)$u['int']+(int)$u['spd']);return $sum;
}
/** Server selects eligible defenders; a browser cannot submit arbitrary opponents. */
function dn_ranked_ticket(mysqli $db,int $uid,bool $refresh=false): ?array {
    dn_ranked_register($db,$uid);$old=dn_row($db,'SELECT * FROM nxt_ranked_tickets WHERE owner_id=?',[$uid]);
    if(!$refresh&&$old&&(int)$old['expires_at']>time()&&(int)$old['season_id']===dn_ranked_season())return $old;
    $p=dn_ranked_profile($db,$uid);$own=dn_ranked_team($db,$uid);if(!$own)return null;
    // Humans and autonomous tamers share the same pool. Prioritize a real-player sample on 35% of searches.
    $humans=random_int(1,100)<=35;
    $sql='SELECT m.id,m.username,m.created_avatar,s.points,IF(b.owner_id IS NULL,0,1) is_bot FROM members m JOIN nxt_ranked_profiles p ON p.owner_id=m.id LEFT JOIN nxt_ranked_seasons s ON s.owner_id=m.id AND s.season_id=? LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE m.id<>? AND m.banned=0 AND (b.owner_id IS NULL OR b.enabled=1) AND EXISTS(SELECT 1 FROM digimon_party dp WHERE dp.owner_id=m.id)';
    $sql.=$humans?' ORDER BY is_bot,ABS(COALESCE(s.points,0)-?),CRC32(CONCAT(m.id,UNIX_TIMESTAMP() DIV 30)) LIMIT 24':' ORDER BY ABS(COALESCE(s.points,0)-?),CRC32(CONCAT(m.id,UNIX_TIMESTAMP() DIV 30)) LIMIT 24';
    $rows=dn_rows($db,$sql,[dn_ranked_season(),$uid,(int)$p['points']]);if(!$rows)return null;
    $strength=dn_ranked_strength($own);$candidates=[];
    foreach($rows as $r){$team=dn_ranked_team($db,(int)$r['id']);if(!$team)continue;$distance=abs(dn_ranked_strength($team)-$strength)/max(1,$strength);$r['team']=$team;$r['distance']=$distance+abs((int)$r['points']-(int)$p['points'])/300;if($humans&&!$r['is_bot'])$r['distance']-=0.2;$candidates[]=$r;}
    if(!$candidates)return null;usort($candidates,fn($a,$b)=>$a['distance']<=>$b['distance']);$candidates=array_slice($candidates,0,12);$chosen=$candidates[random_int(0,count($candidates)-1)];
    $token=bin2hex(random_bytes(32));$json=json_encode($chosen,JSON_THROW_ON_ERROR);$expires=time()+600;$season=dn_ranked_season();
    dn_write($db,'INSERT INTO nxt_ranked_tickets(token,owner_id,defender_id,defender_json,season_id,expires_at) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE token=VALUES(token),defender_id=VALUES(defender_id),defender_json=VALUES(defender_json),season_id=VALUES(season_id),expires_at=VALUES(expires_at)',[$token,$uid,(int)$chosen['id'],$json,$season,$expires]);
    return ['token'=>$token,'owner_id'=>$uid,'defender_id'=>(int)$chosen['id'],'defender_json'=>$json,'season_id'=>$season,'expires_at'=>$expires];
}
function dn_ranked_challenge(mysqli $db,int $uid,string $token): array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new RuntimeException('Your matchup expired. Find a new rival.');
    $db->begin_transaction();try{
        $ticket=dn_row($db,'SELECT * FROM nxt_ranked_tickets WHERE token=? AND owner_id=?',[$token,$uid]);
        if(!$ticket)throw new RuntimeException('This matchup was already played or has expired. Find a new rival.');
        $defender=(int)$ticket['defender_id'];$locks=[$uid,$defender];sort($locks,SORT_NUMERIC);foreach($locks as $id)dn_lock_owner($db,$id);
        $ticket=dn_row($db,'SELECT * FROM nxt_ranked_tickets WHERE token=? AND owner_id=? FOR UPDATE',[$token,$uid]);
        if(!$ticket||(int)$ticket['expires_at']<=time()||(int)$ticket['season_id']!==dn_ranked_season())throw new RuntimeException('Your matchup expired. Find a new rival.');
        dn_require_idle($db,$uid);foreach($locks as $id)dn_ranked_register($db,$id);
        $member=dn_row($db,'SELECT banned FROM members WHERE id=?',[$defender]);if(!$member||(int)$member['banned'])throw new RuntimeException('This rival is unavailable. Find a new rival.');
        $p=dn_ranked_profile($db,$uid);if((int)$p['energy']<1)throw new RuntimeException('Battle energy is recharging. Your next point arrives in '.(int)ceil($p['next_in']/60).' minutes.');
        $left=dn_ranked_team($db,$uid);$enemy=json_decode($ticket['defender_json'],true,512,JSON_THROW_ON_ERROR);$right=$enemy['team'];
        if(!$left||!$right)throw new RuntimeException('Assign a Digimon to your active party before entering Battle Park.');
        // Record the actual resolved actions whenever a player can view this match.
        // Bot-only matches retain their compact report; presentation never resimulates combat.
        $botParticipants=(int)(dn_row($db,'SELECT COUNT(*) n FROM nxt_bots WHERE owner_id IN (?,?)',[$uid,$defender])['n']??0);
        $battle=dn_team_simulate($left,$right,60,$botParticipants<2);if(dn_ranked_season()!==(int)$ticket['season_id'])throw new RuntimeException('The season just ended. Find your first rival of the new season.');$outcome=$battle['result'];if(!in_array($outcome,['win','loss','draw'],true))throw new RuntimeException('Battle result unavailable. No energy was spent.');
        $won=$outcome==='win';$lost=$outcome==='loss';$draw=$outcome==='draw';$streak=$won?(int)$p['streak']+1:0;
        $delta=$won?20+min(5,$streak-1):($lost?-10:0);$before=(int)$p['points'];$after=max(0,$before+$delta);$bits=$won?150:($draw?50:25);$exp=$won?90:30;$medals=$won?3:1;$now=time();$season=dn_ranked_season($now);
        dn_write($db,'UPDATE nxt_ranked_profiles SET energy=?,energy_at=?,wins=wins+?,losses=losses+?,draws=draws+?,medals=medals+? WHERE owner_id=?',[(int)$p['energy']-1,(int)$p['energy_at'],(int)$won,(int)$lost,(int)$draw,$medals,$uid]);
        dn_write($db,'UPDATE nxt_ranked_seasons SET points=?,wins=wins+?,losses=losses+?,draws=draws+?,streak=?,best_streak=GREATEST(best_streak,?),updated_at=? WHERE season_id=? AND owner_id=?',[$after,(int)$won,(int)$lost,(int)$draw,$streak,$streak,$now,$season,$uid]);
        dn_write($db,'UPDATE nxt_ranked_seasons SET defense_wins=defense_wins+?,defense_losses=defense_losses+? WHERE season_id=? AND owner_id=?',[(int)$lost,(int)$won,$season,$defender]);
        dn_write($db,'UPDATE members SET money=money+?,battle=battle+1,wins=wins+?,losses=losses+? WHERE id=?',[$bits,(int)$won,(int)$lost,$uid]);
        foreach($left as $u)dn_award_exp($db,(int)$u['id'],$uid,$exp);
        dn_write($db,'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.hp=d.max_hp,d.sp=d.max_sp WHERE d.owner_id=?',[$uid]);
        dn_record_battle($db,$uid,(int)$left[0]['id'],null,'arena',$draw?'disconnect':$outcome,'battle-park',$exp*count($left),0,$bits);
        dn_write($db,'INSERT INTO nxt_ranked_matches(request_token,season_id,attacker_id,defender_id,result,points_before,points_after,bits_gain,exp_gain,medals_gain,rounds,attacker_json,defender_json,battle_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$token,$season,$uid,$defender,$outcome,$before,$after,$bits,$exp*count($left),$medals,(int)($battle['rounds']??0),json_encode($left,JSON_THROW_ON_ERROR),json_encode($right,JSON_THROW_ON_ERROR),json_encode($battle,JSON_THROW_ON_ERROR),$now]);
        $matchId=(int)$db->insert_id;dn_write($db,'DELETE FROM nxt_ranked_tickets WHERE token=? AND owner_id=?',[$token,$uid]);
        // AI feed is part of the same settlement transaction as its match.
        if(dn_row($db,'SELECT owner_id FROM nxt_bots WHERE owner_id=?',[$uid]))dn_write($db,'INSERT INTO nxt_bot_activity(owner_id,action,summary,detail_json,match_id,created_at) VALUES(?,?,?,?,?,?)',[$uid,'ranked',$won?'Won a Battle Park match':($lost?'Lost a Battle Park match':'Drew a Battle Park match'),json_encode(['opponent_id'=>$defender,'opponent'=>$enemy['username'],'points_before'=>$before,'points_after'=>$after,'result'=>$outcome],JSON_THROW_ON_ERROR),$matchId,$now]);
        $db->commit();dn_world_game_event($db,$uid,'ranked.complete','Battle Park '.$outcome.' against '.$enemy['username'].'; '.$before.' → '.$after.' points',['opponent'=>$enemy['username'],'result'=>$outcome,'bits'=>$bits,'exp'=>$exp*count($left)]);return ['id'=>$matchId,'result'=>$outcome,'won'=>$won,'before'=>$before,'after'=>$after,'bits'=>$bits,'exp'=>$exp*count($left),'medals'=>$medals,'opponent'=>$enemy,'battle'=>$battle];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_ranked_reward(int $rank): array {
    if($rank<=0)return ['bits'=>0,'medals'=>0];
    return match(true){$rank===1=>['bits'=>20000,'medals'=>150],$rank<=10=>['bits'=>12000,'medals'=>100],$rank<=100=>['bits'=>7000,'medals'=>70],$rank<=500=>['bits'=>4000,'medals'=>40],default=>['bits'=>2000,'medals'=>20]};
}
function dn_ranked_claim(mysqli $db,int $uid,int $season): array {
    if($season>=dn_ranked_season()||$season!==dn_ranked_season($season))throw new RuntimeException('That season has not ended.');
    dn_ranked_finalize_season($db,$season);
    $db->begin_transaction();try{
        dn_lock_owner($db,$uid);$s=dn_row($db,'SELECT * FROM nxt_ranked_seasons WHERE season_id=? AND owner_id=? FOR UPDATE',[$season,$uid]);
        if(!$s||$s['reward_claimed']||((int)$s['wins']+(int)$s['losses']+(int)$s['draws'])<1)throw new RuntimeException('No unclaimed reward is available for that season.');
        $rank=(int)$s['final_rank'];if($rank<1)throw new RuntimeException('This tamer was not eligible at the end of that season.');$reward=dn_ranked_reward($rank);
        dn_write($db,'UPDATE nxt_ranked_seasons SET reward_claimed=1 WHERE season_id=? AND owner_id=?',[$season,$uid]);
        dn_write($db,'UPDATE members SET money=money+? WHERE id=?',[$reward['bits'],$uid]);dn_write($db,'UPDATE nxt_ranked_profiles SET medals=medals+? WHERE owner_id=?',[$reward['medals'],$uid]);
        $db->commit();dn_world_game_event($db,$uid,'ranked.reward','Claimed weekly rank '.$rank.' rewards',['bits'=>$reward['bits']]);return $reward+['rank'=>$rank];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function dn_ranked_medal_shop(): array {
    return ['hp-capsule-m'=>['name'=>'Medium HP Capsule','cost'=>8],'sp-capsule-m'=>['name'=>'Medium SP Capsule','cost'=>10],'hp-capsule-l'=>['name'=>'Large HP Capsule','cost'=>18],'sp-capsule-l'=>['name'=>'Large SP Capsule','cost'=>20]];
}
function dn_ranked_exchange(mysqli $db,int $uid,string $slug,string $token): string {
    $offer=dn_ranked_medal_shop()[$slug]??null;if(!$offer)throw new RuntimeException('That medal reward is unavailable.');
    $db->begin_transaction();try{
        dn_lock_owner($db,$uid);$prior=dn_receipt($db,$uid,$token);if($prior!==null){$db->commit();return $prior;}
        $p=dn_row($db,'SELECT medals FROM nxt_ranked_profiles WHERE owner_id=? FOR UPDATE',[$uid]);
        if(!$p||(int)$p['medals']<$offer['cost'])throw new RuntimeException('Earn more Battle Park medals to claim this capsule.');
        $item=dn_row($db,'SELECT id,stack_limit FROM items WHERE slug=? AND is_active=1',[$slug]);if(!$item)throw new RuntimeException('This capsule is unavailable.');
        $qty=(int)(dn_row($db,'SELECT quantity FROM member_items WHERE owner_id=? AND item_id=?',[$uid,(int)$item['id']])['quantity']??0);
        if($qty>=(int)$item['stack_limit'])throw new RuntimeException('Your capsule stack is full.');
        dn_write($db,'UPDATE nxt_ranked_profiles SET medals=medals-? WHERE owner_id=?',[$offer['cost'],$uid]);dn_add_item($db,$uid,(int)$item['id'],1);
        $message='Claimed '.$offer['name'].' for '.$offer['cost'].' medals.';dn_save_receipt($db,$uid,$token,$message);$db->commit();dn_world_game_event($db,$uid,'ranked.exchange',$message,['quantity'=>1]);return $message;
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Freeze historical placement before any owner lock, avoiding cross-owner finalization deadlocks. */
function dn_ranked_finalize_season(mysqli $db,int $season): void {
    if($season>=dn_ranked_season())return;
    $database=(string)dn_row($db,'SELECT DATABASE() n')['n'];$lock='dn_season_'.substr(hash('sha256',$database.':'.$season),0,40);
    if((int)dn_row($db,'SELECT GET_LOCK(?,10) ok',[$lock])['ok']!==1)throw new RuntimeException('Season rewards are being prepared. Try again shortly.');
    try{
        if(dn_row($db,'SELECT owner_id FROM nxt_ranked_seasons WHERE season_id=? AND season_closed_at>0 LIMIT 1',[$season]))return;
        $db->begin_transaction();try{
            $rows=dn_rows($db,'SELECT s.owner_id FROM nxt_ranked_seasons s JOIN members m ON m.id=s.owner_id WHERE s.season_id=? AND m.banned=0 ORDER BY s.points DESC,s.wins DESC,s.owner_id FOR UPDATE',[$season]);
            dn_write($db,'UPDATE nxt_ranked_seasons SET season_closed_at=? WHERE season_id=?',[time(),$season]);
            foreach($rows as $index=>$row)dn_write($db,'UPDATE nxt_ranked_seasons SET final_rank=? WHERE season_id=? AND owner_id=?',[$index+1,$season,(int)$row['owner_id']]);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
    }finally{dn_row($db,'SELECT RELEASE_LOCK(?) ok',[$lock]);}
}
