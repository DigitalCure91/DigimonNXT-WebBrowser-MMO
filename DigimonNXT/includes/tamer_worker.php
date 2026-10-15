<?php
declare(strict_types=1);
/** Persistent CLI worker. Windows startup task / supervisor runs this served copy. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bots.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$options=getopt('',['once','batch:','interval:','seed','game-dir:','help','check']);
if(isset($options['help'])){
    echo "Digimon NXT persistent tamer worker v1\nUsage: php includes/tamer_worker.php [--once] [--batch=40] [--interval=5] [--seed] [--check]\n";
    exit;
}
if(isset($options['check'])){
    if(!class_exists('mysqli')){fwrite(STDERR,"PHP mysqli is required.\n");exit(1);}
    echo "Digimon NXT persistent tamer worker v1 ready\n";exit;
}
$batch=max(1,min(250,(int)($options['batch']??40)));
$interval=max(5,min(60,(int)($options['interval']??5)));
$once=isset($options['once']);$seed=isset($options['seed']);$failures=0;
set_time_limit(0);
echo 'Digimon NXT persistent tamers · game folder '.dirname(__DIR__).PHP_EOL;
echo 'Players may close every browser. Keep Apache/MySQL available; database outages are retried.'.PHP_EOL;
do{
    try{
        $db=pv_db();
        if($seed){echo 'Created '.dn_seed_bots($db,1000)." missing tamers.\n";$seed=false;}
        // This separate heartbeat distinguishes a persistent process from browser pulses.
        if(!$once)dn_write($db,"INSERT INTO nxt_worker_state(worker_key,last_run) VALUES('tamers_daemon',?) ON DUPLICATE KEY UPDATE last_run=VALUES(last_run),last_error=''",[time()]);
        $result=dn_bots_tick($db,$batch,$once?0:5,2.0);
        if(!$once)dn_write($db,"UPDATE nxt_worker_state SET last_run=?,processed=processed+?,last_error=? WHERE worker_key='tamers_daemon'",[time(),(int)$result['processed'],substr(implode(' | ',$result['errors']),0,255)]);
        echo gmdate('c').' '.json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        if($once)exit($result['errors']?1:0);
        $failures=0;
    }catch(Throwable $e){
        $failures++;
        $message='Tamer worker is waiting for the database: '.$e->getMessage();
        fwrite(STDERR,gmdate('c').' '.$message.PHP_EOL);pv_log($message);
        // MySQL restarts invalidate the old connection: reconnect on the next loop.
        if(isset($GLOBALS['mysql_connection'])){
            try{$GLOBALS['mysql_connection']->close();}catch(Throwable){}
            unset($GLOBALS['mysql_connection']);
        }
        if($once)exit(1);
    }
    sleep($failures?min(30,$interval*$failures):$interval);
}while(true);
