<?php
/** Original graphics decoded from the supplied Digimon World Dawn US ROM. */
declare(strict_types=1);
function dawn_asset_manifest(): array {
    static $manifest=null;
    if($manifest!==null)return $manifest;
    $path=__DIR__.'/../config/dawn_assets.json';
    $data=is_file($path)?json_decode((string)file_get_contents($path),true):null;
    return $manifest=is_array($data)?$data:[];
}
function dawn_tamers(): array {return dawn_asset_manifest()['tamers']??[];}
function dawn_tamer_asset(string $key): ?array {
    $catalog=dawn_tamers();
    if(isset($catalog[$key]))return $catalog[$key];
    if(ctype_digit($key))foreach($catalog as $row)if((int)$row['numeric_id']===(int)$key)return $row;
    return null;
}
function dawn_asset_url(string $path): string {
    return function_exists('pv_url')?pv_url($path):'/'.ltrim($path,'/');
}
function dawn_tamer_sprite(string $key): string {
    $asset=dawn_tamer_asset($key)??dawn_tamer_asset('dawn_002');
    return dawn_asset_url((string)($asset['sprite']??'assets/dawn/tamers/dawn_002.png'));
}
function dawn_digimon_asset(string $slug): ?array {
    static $bySlug=null;
    if($bySlug===null){$bySlug=[];foreach(dawn_asset_manifest()['roster_mapping']??[] as $row)$bySlug[$row['slug']]=$row;}
    return $bySlug[$slug]??null;
}
function dawn_battle_background(int $sourceId=0): string {
    $backgrounds=dawn_asset_manifest()['backgrounds']??[];
    foreach($backgrounds as $row)if((int)$row['source_id']===$sourceId)return dawn_asset_url((string)$row['sprite']);
    return dawn_asset_url((string)($backgrounds[0]['sprite']??'assets/dawn/backgrounds/battle_000.png'));
}
