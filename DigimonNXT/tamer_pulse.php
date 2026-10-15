<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bots.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function dn_pulse_json(array $payload,int $status=200): never {
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
    header('Allow: POST');dn_pulse_json(['ok'=>false,'error'=>'method'],405);
}
if(!pv_is_logged_in())dn_pulse_json(['ok'=>false,'error'=>'session','message'=>'Sign in again to reconnect.'],401);
if(!pv_verify_csrf())dn_pulse_json(['ok'=>false,'error'=>'csrf','message'=>'Reload this page to reconnect.'],403);
$uid=(int)$_SESSION['myid'];
// World simulation must never hold the player's session lock or alter battle state.
session_write_close();
try{
    $db=pv_db();
    $member=dn_row($db,'SELECT m.id FROM members m LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE m.id=? AND m.banned=0 AND b.owner_id IS NULL',[$uid]);
    if(!$member)dn_pulse_json(['ok'=>false,'error'=>'account','message'=>'Sign in with an active tamer account.'],403);
    // A disconnected tab cannot interrupt an already started bounded action. No
    // request parameter can change the batch, cadence, clock or selected tamers.
    ignore_user_abort(true);
    $db->query('SET SESSION innodb_lock_wait_timeout=2');
    $result=dn_bots_tick($db,40,5,1.5);
    $worker=dn_row($db,"SELECT last_run,last_error FROM nxt_worker_state WHERE worker_key='tamers'");
    $daemon=dn_row($db,"SELECT last_run FROM nxt_worker_state WHERE worker_key='tamers_daemon'");
    $problem=!empty($worker['last_error']);
    dn_pulse_json(['ok'=>true,'processed'=>$result['processed'],'skipped'=>$result['skipped'],
        'retry_after'=>max(5,(int)($result['retry_after']??5)),
        'last_run'=>(int)($worker['last_run']??0),'background'=>(int)($daemon['last_run']??0)>time()-90,'attention'=>$problem,
        'message'=>$problem?'Some tamers could not finish their turn. See the server log.':'']);
}catch(Throwable $e){
    pv_log('Automatic tamer activity: '.$e->getMessage());
    dn_pulse_json(['ok'=>false,'error'=>'service','message'=>'Tamer activity could not connect. Retrying shortly.'],503);
}
