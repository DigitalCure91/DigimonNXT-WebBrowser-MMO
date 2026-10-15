<?php
/** Travel only to a curated landing on the area currently open in this session. */
declare(strict_types=1);
require_once __DIR__.'/includes/overworld_encounters.php';
pv_require_login();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);header('Allow: POST');exit;}
if(!pv_verify_csrf()){http_response_code(419);exit('Your travel session expired. Return to the map and try again.');}
$areaKey=pv_world_resolve_area_key((string)($_POST['area']??''));
$landingId=filter_var($_POST['landing_id']??null,FILTER_VALIDATE_INT);
$area=pv_world_area('cyberspace',$areaKey);
if(!$area||$areaKey!==pv_world_resolve_area_key((string)($_SESSION['world_area']??''))||!is_int($landingId)){http_response_code(409);exit('This map changed. Return to the area directory and choose your destination.');}
try{
    $db=pv_db();$uid=(int)$_SESSION['myid'];$active=dn_active_battle($db,$uid);if($active&&empty($active['finished']))pv_redirect('wildbattle.php',303);$blocks=pv_world_blocks($db,'cyberspace',$areaKey);
    $landing=pv_world_landing($area,$blocks,$landingId);
    if(!$landing){$_SESSION['dn_world_travel_error']='That landing is unavailable. Choose another landing from the map.';header('Location: '.pv_world_area_url('cyberspace',$areaKey),true,303);exit;}
    pv_world_presence_upsert($db,$uid,'cyberspace',$areaKey,(int)$landing['x'],(int)$landing['y']);
    unset($_SESSION['dn_pending_encounter']);
    dn_world_event('game','world.landing','Travelled to '.(string)$landing['label'].' in '.(string)$area['name'],['area'=>$areaKey]);
    header('Location: '.pv_world_area_url('cyberspace',$areaKey),true,303);exit;
}catch(Throwable $e){pv_log('World landing unavailable: '.$e->getMessage());http_response_code(503);exit('Travel is reconnecting. Return to the map and try again.');}
