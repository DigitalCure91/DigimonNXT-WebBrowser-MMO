<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/catalog.php';
require_once __DIR__.'/includes/dawn_assets.php';
require_once __DIR__.'/includes/world_maps.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$ok=false;$tables=0;$species=0;$bots=0;$schema=null;$music=0;$background=false;$lastBotRun=0;
try {
    $db=pv_db();
    $required=['members','digimon_species','digimon_instances','world_map_positions','messages','trade_listings','digivolution_log','digimon_learned_moves','digimon_battle_state','digimon_action_receipts','nxt_bots','nxt_ranked_profiles','nxt_ranked_seasons','nxt_ranked_tickets','nxt_ranked_matches','nxt_bot_activity','nxt_worker_state','nxt_world_settings','nxt_world_admin_audit','nxt_world_bans','nxt_world_ip_bans'];
    $ok=true;foreach($required as $table)if(!pv_table_exists($table))$ok=false;
    if($ok){
        $tables=(int)$db->query('SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE()')->fetch_assoc()['c'];
        $species=(int)$db->query('SELECT COUNT(*) c FROM digimon_species')->fetch_assoc()['c'];
        $bots=(int)$db->query('SELECT COUNT(*) c FROM nxt_bots')->fetch_assoc()['c'];
        $worker=$db->query("SELECT worker_key,last_run FROM nxt_worker_state WHERE worker_key IN ('tamers','tamers_daemon')");while($state=$worker->fetch_assoc()){if($state['worker_key']==='tamers')$lastBotRun=(int)$state['last_run'];else $background=(int)$state['last_run']>time()-90;}
        $schema=(string)($db->query("SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")->fetch_assoc()['meta_value']??'');
    }
    $audio=json_decode((string)file_get_contents(__DIR__.'/config/dawn_audio.json'),true);
    foreach($audio['tracks']??[] as $track)if(($track['kind']??'')==='music'&&($track['status']??'')==='rendered'&&is_file(__DIR__.'/assets/'.($track['file']??'')))$music++;
}catch(Throwable $e){$ok=false;}
$tamers=count(dawn_tamers());
$dawnMaps=count((array)(pv_world_manifest()['areas']??[]));
$ok=$ok&&$schema==='8'&&$species===count(dn_catalog())&&$bots===1000&&$music===46&&$tamers===64&&$dawnMaps===264;
http_response_code($ok?200:503);
echo json_encode(['ok'=>$ok,'service'=>'Digimon NXT','version'=>(string)pv_config('asset_version','0.7.2'),'schema'=>$schema,'tables'=>$tables,'species'=>$species,'bots'=>$bots,'bot_last_activity'=>$lastBotRun,'bot_background_worker'=>$background,'dawn_tamers'=>$tamers,'dawn_maps'=>$dawnMaps,'music_tracks'=>$music,'time'=>time()],JSON_UNESCAPED_SLASHES);
