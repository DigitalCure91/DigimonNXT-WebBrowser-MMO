<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../public_html/includes/schema.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
set_error_handler(function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
function ranked_expect(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;}
function ranked_blocked(callable $fn,string $label): void {try{$fn();}catch(RuntimeException $e){ranked_expect(true,$label);return;}throw new RuntimeException('FAIL: accepted '.$label);}
$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);$db->set_charset('utf8mb4');$name='nxt_ranked_test_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$name.'`');$db->select_db($name);$GLOBALS['mysql_connection']=$db;
try{
    dn_apply_schema_migrations($db);
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM nxt_bots')['n']===1000,'1000 persistent unique bot accounts');
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_party p JOIN nxt_bots b ON b.owner_id=p.owner_id')['n']===3000,'three real owned starters per bot');
    ranked_expect(dn_seed_bots($db,1000)===0,'repeat seed is idempotent');
    ranked_expect(gmdate('N H:i:s',dn_ranked_season(1790000000))==='1 00:00:00','UTC Monday season boundary');
    $e=dn_ranked_energy(['energy'=>2,'energy_at'=>10000],10000+3700);ranked_expect($e['energy']===4&&$e['next_in']===1700,'energy regeneration preserves partial interval');
    $e=dn_ranked_energy(['energy'=>5,'energy_at'=>1],100000);ranked_expect($e['energy']===5&&$e['energy_at']===100000,'full energy cannot bank future refills');
    $bot=(int)dn_row($db,'SELECT owner_id FROM nxt_bots WHERE bot_no=1')['owner_id'];$other=(int)dn_row($db,'SELECT owner_id FROM nxt_bots WHERE bot_no=2')['owner_id'];
    $ticket=dn_ranked_ticket($db,$bot);ranked_expect($ticket!==null,'server-generated real defender matchup');
    ranked_blocked(fn()=>dn_ranked_challenge($db,$other,$ticket['token']),'foreign matchup rejected');
    $r=dn_ranked_challenge($db,$bot,$ticket['token']);ranked_expect(in_array($r['result'],['win','loss','draw'],true)&&$r['battle']['actions']>0,'ranked result resolved through actual team combat');
    $match=dn_row($db,'SELECT * FROM nxt_ranked_matches WHERE id=?',[$r['id']]);ranked_expect((int)$match['attacker_id']===$bot&&count(json_decode($match['attacker_json'],true))===3,'actual ranked teams and report persisted');
    ranked_expect((int)dn_ranked_profile($db,$bot)['energy']===4,'one match consumes exactly one energy');
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM nxt_bot_activity WHERE owner_id=? AND action="ranked"',[$bot])['n']===1,'ranked AI feed committed with real result');
    ranked_blocked(fn()=>dn_ranked_challenge($db,$bot,$ticket['token']),'settled match replay rejected');
    ranked_expect((int)dn_ranked_profile($db,$bot)['energy']===4,'replay does not spend energy again');
    dn_write($db,'UPDATE nxt_ranked_profiles SET energy=0,energy_at=? WHERE owner_id=?',[time(),$bot]);$ticket2=dn_ranked_ticket($db,$bot);ranked_blocked(fn()=>dn_ranked_challenge($db,$bot,$ticket2['token']),'empty energy prevents settlement');
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM nxt_ranked_matches')['n']===1,'rejected battle creates no match');
    $batches=[];for($i=0;$i<4;$i++)$batches[]=dn_bots_tick($db,250);ranked_expect(!array_filter($batches,fn($batch)=>!empty($batch['errors'])),'1000 autonomous actions complete without errors');
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM nxt_bots WHERE activity_step>0')['n']===1000,'fair cursor advances to all 1000 distinct tamers');
    $outcomes=dn_row($db,'SELECT SUM(wild_wins) wins,SUM(wild_losses) losses FROM nxt_bots');ranked_expect((int)$outcomes['wins']>0&&(int)$outcomes['losses']>0,'real wild simulation produces both wins and losses');
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id WHERE d.hp<>d.max_hp OR d.sp<>d.max_sp')['n']===0,'all active bot parties fully restored');
    $loss=dn_row($db,'SELECT detail_json FROM nxt_bot_activity WHERE action="wild" AND JSON_UNQUOTE(JSON_EXTRACT(detail_json,"$.result"))="loss" LIMIT 1');$detail=json_decode($loss['detail_json'],true);ranked_expect($detail['scan']>0,'defeated bots retain real encounter scan data');
    // Materialization fixture uses already-earned scan if available, otherwise fills a fixture scan row.
    $ready=dn_row($db,'SELECT owner_id,species_id FROM digimon_scan WHERE scan_percent>=100 LIMIT 1');
    if(!$ready){$species=dn_species_by_slug($db,'agumon');dn_write($db,'UPDATE digimon_scan SET scan_percent=200 WHERE owner_id=? AND species_id=?',[$bot,(int)$species['id']]);$ready=['owner_id'=>$bot,'species_id'=>$species['id']];}
    $owner=(int)$ready['owner_id'];$count=(int)dn_row($db,'SELECT COUNT(*) n FROM digimon_instances WHERE owner_id=?',[$owner])['n'];ranked_expect(dn_bot_materialize($db,$owner),'bot uses actual materialization action');
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM digimon_instances WHERE owner_id=?',[$owner])['n']===$count+1,'materialization creates a real owned partner');
    ranked_expect(dn_bot_rotate($db,$owner,9),'bot rotates owned party');ranked_expect(count(dn_party_rows($db,$owner))>=3,'rotation preserves the formation');
    ranked_expect(dn_bot_train($db,$owner,4),'bot performs real stat training');
    ranked_expect(dn_bot_shop($db,$owner,14),'bot spends earned Bits in the real shop');
    ranked_expect((int)dn_row($db,'SELECT COUNT(*) n FROM mapusers p JOIN nxt_bots b ON b.owner_id=p.id')['n']>0,'wild bot activity creates real overworld presence');
    $evo=dn_species_by_slug($db,'agumon');$id=dn_create_instance($db,$owner,(int)$evo['id'],'test',50,100);dn_write($db,'UPDATE digimon_instances SET cam=100 WHERE id=?',[$id]);dn_assign_party_slot($db,$owner,1,$id);ranked_expect(dn_bot_evolve($db,$owner),'bot evolves through actual requirement checks');
    $priorSeason=dn_ranked_season()-604800;dn_write($db,'INSERT INTO nxt_ranked_seasons(season_id,owner_id,points,wins,updated_at) VALUES(?,?,?,?,?)',[$priorSeason,$owner,123,4,time()-604800]);$reward=dn_ranked_claim($db,$owner,$priorSeason);ranked_expect($reward['bits']>0,'weekly completed season reward credited');ranked_blocked(fn()=>dn_ranked_claim($db,$owner,$priorSeason),'weekly reward cannot be claimed twice');
    $frozen=dn_row($db,'SELECT final_rank,season_closed_at FROM nxt_ranked_seasons WHERE season_id=? AND owner_id=?',[$priorSeason,$owner]);ranked_expect((int)$frozen['final_rank']===1&&(int)$frozen['season_closed_at']>0,'completed season rank freezes durably');
    dn_write($db,'UPDATE nxt_ranked_profiles SET medals=100 WHERE owner_id=?',[$owner]);$receipt=bin2hex(random_bytes(24));dn_ranked_exchange($db,$owner,'hp-capsule-m',$receipt);dn_ranked_exchange($db,$owner,'hp-capsule-m',$receipt);ranked_expect((int)dn_row($db,'SELECT medals FROM nxt_ranked_profiles WHERE owner_id=?',[$owner])['medals']===92,'medal shop receipt prevents double charge');
    $lock='dn_bot_tick_'.substr(hash('sha256',$name),0,30);$secondDb=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$name,(int)$cfg['port']);dn_row($secondDb,'SELECT GET_LOCK(?,0) ok',[$lock]);$skip=dn_bots_tick($db,10);ranked_expect($skip['skipped']&&$skip['processed']===0,'overlapping worker exits without duplicate actions');dn_row($secondDb,'SELECT RELEASE_LOCK(?) ok',[$lock]);$secondDb->close();
    echo 'Wild outcomes: '.json_encode($outcomes).PHP_EOL;
    echo 'All ranked and bot integration checks passed.'.PHP_EOL;
}finally{$db->query('DROP DATABASE `'.$name.'`');}
