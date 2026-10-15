<?php
declare(strict_types=1);
require_once __DIR__.'/includes/overworld_encounters.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function dn_signal_json(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
if(!pv_is_logged_in())dn_signal_json(['ok'=>false,'error'=>'Your session expired. Please sign in again.'],401);
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')dn_signal_json(['ok'=>false,'error'=>'Use the map to choose a Digimon.'],405);
if(!pv_verify_csrf())dn_signal_json(['ok'=>false,'error'=>'Your scanner session expired. Refresh the map.'],419);
try{
    $db=pv_db();$uid=(int)$_SESSION['myid'];$areaKey=pv_world_area_key((string)($_POST['area']??''));
    $area=pv_world_area('cyberspace',$areaKey);
    if(!$area||$areaKey!==(string)($_SESSION['world_area']??''))dn_signal_json(['ok'=>false,'error'=>'You changed sectors. Refresh your map before choosing a signal.'],409);
    $active=dn_active_battle($db,$uid);
    if($active&&empty($active['finished']))dn_signal_json(['ok'=>true,'redirect'=>pv_url('wildbattle.php')]);
    $pending=dn_overworld_claim($db,$uid,$area,(string)($_POST['spawn_id']??''));
    dn_signal_json(['ok'=>true,'action'=>pv_url('wildbattle.php'),'encounterToken'=>$pending['token'],'csrf'=>pv_csrf_token()]);
}catch(RuntimeException $e){dn_signal_json(['ok'=>false,'error'=>$e->getMessage()],409);}
catch(Throwable $e){pv_log('Roaming signal: '.$e->getMessage());dn_signal_json(['ok'=>false,'error'=>'The scanner is reconnecting. Please try again.'],503);}
