<?php
declare(strict_types=1);
require_once __DIR__.'/includes/overworld_encounters.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function dn_presence_json(array $payload,int $status=200): never {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
if(!pv_is_logged_in())dn_presence_json(['ok'=>false,'error'=>'session'],401);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')dn_presence_json(['ok'=>false,'error'=>'method'],405);
try{
    $db=pv_db();$uid=(int)$_SESSION['myid'];$active=dn_active_battle($db,$uid);if($active&&empty($active['finished']))dn_presence_json(['ok'=>true,'redirect'=>pv_url('wildbattle.php')]);$area=pv_world_resolve_area_key((string)($_SESSION['world_area']??''));$definition=pv_world_area('cyberspace',$area);
    if(!$definition)dn_presence_json(['ok'=>false,'error'=>'world'],409);
    [$x,$y]=pv_world_position($db,$uid,'cyberspace',$area,(array)$definition['spawn_points']);
    dn_presence_json(['ok'=>true,'world'=>'cyberspace','area'=>$area,'x'=>$x,'y'=>$y,'players'=>pv_world_players($db,$uid,'cyberspace',$area),
        'wild'=>dn_overworld_sync($db,$uid,$definition,$x,$y),'blockedDirections'=>pv_world_blocked_directions($db,$definition,$x,$y)]);
}catch(Throwable $e){pv_log('Map presence unavailable: '.$e->getMessage());dn_presence_json(['ok'=>false,'error'=>'service'],503);}
