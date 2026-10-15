<?php
declare(strict_types=1);
require_once __DIR__.'/includes/overworld_encounters.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function dn_world_json(array $payload,int $status=200): never {http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
if(!pv_is_logged_in())dn_world_json(['ok'=>false,'error'=>'session'],401);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')dn_world_json(['ok'=>false,'error'=>'method'],405);
if(!pv_verify_csrf())dn_world_json(['ok'=>false,'error'=>'csrf'],419);
$direction=filter_var($_POST['direction']??null,FILTER_VALIDATE_INT);
$steps=filter_var($_POST['steps']??1,FILTER_VALIDATE_INT);
$deltas=[1=>[0,-1],2=>[0,1],3=>[-1,0],4=>[1,0],5=>[-1,-1],6=>[-1,1],7=>[1,-1],8=>[1,1]];
if(!is_int($direction)||!isset($deltas[$direction]))dn_world_json(['ok'=>false,'error'=>'direction'],422);
if(!is_int($steps)||$steps<1||$steps>4)dn_world_json(['ok'=>false,'error'=>'steps'],422);
$now=microtime(true);$next=(float)($_SESSION['dn_next_world_move']??0);
if($now<$next)dn_world_json(['ok'=>false,'error'=>'rate','retryAfterMs'=>(int)ceil(($next-$now)*1000)],429);
$_SESSION['dn_next_world_move']=$now+.055*$steps;
try{
    $db=pv_db();$uid=(int)$_SESSION['myid'];$world='cyberspace';
    $active=dn_active_battle($db,$uid);if($active&&empty($active['finished']))dn_world_json(['ok'=>true,'moved'=>false,'redirect'=>pv_url('wildbattle.php')]);
    $areaKey=pv_world_resolve_area_key((string)($_SESSION['world_area']??''));$area=pv_world_area($world,$areaKey);
    if(!$area)dn_world_json(['ok'=>false,'error'=>'area'],409);
    if(pv_world_resolve_area_key((string)($_POST['area']??''))!==$areaKey)dn_world_json(['ok'=>false,'error'=>'area-changed'],409);
    [$x,$y]=pv_world_position($db,$uid,$world,$areaKey,(array)$area['spawn_points']);
    $blocks=pv_world_blocks($db,$world,$areaKey);$moved=0;
    for($i=0;$i<$steps;$i++){
        $tx=$x+$deltas[$direction][0];$ty=$y+$deltas[$direction][1];
        // Every intermediate cell and diagonal corner is authoritative, including gateways.
        if(pv_world_step_blocked($area,$blocks,$x,$y,$tx,$ty))break;
        $transition=pv_world_transition($area,$tx,$ty);
        if($transition){
            $target=pv_world_area($world,(string)$transition['target_area']);if(!$target)break;
            $nx=(int)$transition['target_x'];$ny=(int)$transition['target_y'];$tb=pv_world_blocks($db,$world,(string)$target['key']);
            if(pv_world_step_blocked($target,$tb,$nx,$ny,$nx,$ny))break;
            pv_world_presence_upsert($db,$uid,$world,(string)$target['key'],$nx,$ny);unset($_SESSION['dn_pending_encounter']);
            dn_world_json(['ok'=>true,'moved'=>true,'transition'=>true,'world'=>$world,'area'=>$target['key'],'x'=>$nx,'y'=>$ny,'redirect'=>pv_world_area_url($world,(string)$target['key'])]);
        }
        $x=$tx;$y=$ty;$moved++;
    }
    if($moved){pv_world_presence_upsert($db,$uid,$world,$areaKey,$x,$y);unset($_SESSION['dn_pending_encounter']);}
    dn_world_json(['ok'=>true,'moved'=>$moved>0,'steps'=>$moved,'transition'=>false,'world'=>$world,'area'=>$areaKey,'x'=>$x,'y'=>$y,
        'players'=>pv_world_players($db,$uid,$world,$areaKey),'wild'=>dn_overworld_sync($db,$uid,$area,$x,$y),
        'blockedDirections'=>pv_world_blocked_directions($db,$area,$x,$y)]);
}catch(Throwable $e){pv_log('Map movement unavailable: '.$e->getMessage());dn_world_json(['ok'=>false,'error'=>'service'],503);}
