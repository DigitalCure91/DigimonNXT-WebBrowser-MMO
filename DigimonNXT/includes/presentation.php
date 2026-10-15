<?php
/** Digimon NXT presentation helpers. No gameplay settlement or schema changes. */
declare(strict_types=1);
if(is_file(__DIR__.'/dawn_assets.php'))require_once __DIR__.'/dawn_assets.php';
/** Stable integer IDs preserve existing accounts while exposing every extracted human avatar. */
function nxt_tamer_catalog(): array {
    static $catalog=null;if(is_array($catalog))return $catalog;
    $catalog=[1=>['id'=>1,'name'=>'Tai','source'=>'Original avatar','url'=>pv_static('images/tamers/1.png')],2=>['id'=>2,'name'=>'Matt','source'=>'Original avatar','url'=>pv_static('images/tamers/2.png')]];
    if(function_exists('dawn_tamers'))foreach(dawn_tamers() as $key=>$entry){
        $id=(int)($entry['numeric_id']??(100+(int)preg_replace('/\D/','',(string)$key)));
        if($id<100||isset($catalog[$id]))continue;
        $url=function_exists('dawn_tamer_sprite')?dawn_tamer_sprite((string)$key):pv_static((string)$entry['sprite']);
        $catalog[$id]=['id'=>$id,'key'=>$key,'name'=>(string)($entry['name']??'Dawn Tamer'),'source'=>'Digimon World Dawn','url'=>$url];
    }
    return $catalog;
}
function nxt_skin_id(int $value): int {
    if(isset(nxt_tamer_catalog()[$value]))return $value;
    return in_array($value,[2,4,6],true)?2:1;
}
function nxt_skin_url(int $value): string {
    $url=(string)nxt_tamer_catalog()[nxt_skin_id($value)]['url'];
    return $url.(str_contains($url,'?')?'&':'?').'v='.pv_asset_version();
}
function nxt_tamer_sprite_catalog(): array {
    $out=[];foreach(nxt_tamer_catalog() as $id=>$entry)$out[$id]=nxt_skin_url((int)$id);return $out;
}
function nxt_tamer_walk_catalog(): array {
    $out=[];
    if(function_exists('dawn_tamers'))foreach(dawn_tamers() as $entry){
        $id=(int)($entry['numeric_id']??0);if($id<100)continue;
        $out[$id]=['sheet'=>dawn_asset_url((string)$entry['sheet']),'width'=>(int)$entry['frame_width'],'height'=>(int)$entry['frame_height']];
    }
    return $out;
}
function nxt_skin_picker(int $selected): void {
    $catalog=nxt_tamer_catalog();$selected=nxt_skin_id($selected);
    echo '<div class="dn-avatar-intro"><span>'.count($catalog).' playable avatars</span><small>Your choice changes your appearance, never your stats.</small></div>';
    echo '<div class="dn-tamer-grid dn-dawn-tamer-grid" role="group" aria-label="Playable tamer avatar">';
    foreach($catalog as $id=>$entry){
        echo '<label class="nxt-skin-card"><input type="radio" name="tamer" value="'.$id.'" '.($selected===$id?'checked':'').'><span class="nxt-skin-platform"><img src="'.pv_h(nxt_skin_url((int)$id)).'" width="96" height="128" loading="lazy" alt="'.pv_h($entry['name']).' playable tamer"></span><span class="nxt-skin-label"><strong>'.pv_h($entry['name']).'</strong><small>'.pv_h($entry['source']).'</small></span><span class="nxt-skin-selected" aria-hidden="true">✓</span></label>';
    }
    echo '</div>';
}
function nxt_preferences(bool $logged): array {
    $options=['tamer'=>1,'battle_animation'=>1,'compact_ui'=>0];
    if($logged){try{$row=dn_row(pv_db(),'SELECT COALESCE(o.tamer,m.created_avatar,1) tamer,COALESCE(o.battle_animation,1) battle_animation,COALESCE(o.compact_ui,0) compact_ui FROM members m LEFT JOIN members_options o ON o.id=m.id WHERE m.id=?',[(int)$_SESSION['myid']]);if($row)$options=array_merge($options,$row);}catch(Throwable){}}
    return $options;
}
function nxt_icon(string $key): string {
    $shapes=[
        'home'=>'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
        'world'=>'m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2zm6-2v16m6-14v16',
        'battle'=>'m4 3 17 17-2 2L2 5zm14 0 4 4-7 7m-5 3-5 5-3-3 5-5',
        'history'=>'M3 10a9 9 0 1 1 1 8M3 3v7h7m2-4v7l4 2',
        'partner'=>'m12 2 9 5v10l-9 5-9-5V7zm-9 5 9 5 9-5m-9 5v10',
        'party'=>'M8 3v6m8-6v6M4 9h16v8l-4 4H8l-4-4zm4 5h1m6 0h1',
        'scan'=>'M8 3H3v5m13-5h5v5M3 16v5h5m13-5v5h-5M5 12h14',
        'evolve'=>'m5 9 7-6 7 6m-14 6 7 6 7-6M12 3v18',
        'dex'=>'M4 3h13l3 3v15H4zm4 4h5m-5 5h8m-8 5h8',
        'shop'=>'M3 7h18l-2 14H5zm5 0V5a4 4 0 0 1 8 0v2m-8 5h8',
        'exchange'=>'M3 7h17l-4-4m4 14H3l4 4M20 7l-4 4M3 17l4-4',
        'event'=>'m12 2 3 6 7 1-5 5 1 8-6-4-6 4 1-8-5-5 7-1z',
        'network'=>'M8 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8M1 21v-3a7 7 0 0 1 14 0v3m1-17a4 4 0 0 1 0 8m2 3a6 6 0 0 1 5 6',
        'mail'=>'M3 4h18v16H3zm0 0 9 9 9-9',
        'clan'=>'m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6zm0 5v10m-5-5h10',
        'profile'=>'M12 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10M3 22v-3a9 9 0 0 1 18 0v3',
        'notice'=>'M5 16h14l-2-4V8a5 5 0 0 0-10 0v4zm5 4h4',
        'settings'=>'M4 5h16M4 12h16M4 19h16M8 2v6m8 1v6m-6 1v6',
        'next'=>'M3 12h17m-7-7 7 7-7 7','power'=>'M12 2v10M6 5a9 9 0 1 0 12 0',
    ];
    return '<svg class="nxt-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.($shapes[$key]??$shapes['partner']).'"/></svg>';
}
function nxt_sprite(string $slug,string $class='',string $alt=''): string {
    return '<img class="nxt-sprite '.pv_h($class).'" src="'.pv_h(pv_static('images/digimon/'.$slug.'.png')).'" alt="'.pv_h($alt).'" width="128" height="128" loading="'.(str_contains($class,'nxt-stage-sprite')?'eager':'lazy').'" decoding="async">';
}
function nxt_page_sprites(): void {
    static $shown=false;if($shown)return;
    $groups=[
        'dashboard'=>['agumon','gabumon'],'digimon'=>['guilmon','veemon'],'party'=>['terriermon','renamon'],
        'materialize'=>['lunamon','coronamon'],'digidex'=>['tentomon','patamon'],'digivolution'=>['wargreymon','metalgarurumon'],
        'map_select'=>['hawkmon','armadillomon'],'battle_arena'=>['gallantmon','alphamon'],'battle_history'=>['cyberdramon','justimonblitz-arm'],
        'trade'=>['impmon','gatomon'],'event_center'=>['dorumon','hackmon'],'community'=>['biyomon','gomamon'],
        'messages'=>['patamon','gatomon'],'clans'=>['flamedramon','magnamon'],'notifications'=>['lopmon','terriermon'],
        'options'=>['hagurumon','guardromon'],'your_account'=>['agumon','veemon'],'admin'=>['omnimon','alphamon'],
    ];
    $key=$GLOBALS['nxt_route']??'';if(!isset($groups[$key]))return;$shown=true;
    echo '<div class="nxt-page-sprites" aria-hidden="true">';foreach($groups[$key] as $slug)echo nxt_sprite($slug);echo '</div>';
}
function nxt_status_page(int $status,string $heading,string $text): never {
    http_response_code($status);pv_page_start($heading,'',pv_is_logged_in());
    echo '<main class="nxt-document"><section class="pv-card nxt-document-panel"><div class="nxt-document-head"><div><span class="pv-eyebrow">NXT / '.(int)$status.'</span><h1>'.pv_h($heading).'</h1></div>'.nxt_sprite('keramon').'</div><p class="pv-subtle">'.pv_h($text).'</p><a class="pv-button" href="'.pv_h(pv_url(pv_is_logged_in()?'dashboard.php':'index.php')).'">Return to Digimon NXT</a></section></main>';
    pv_page_end();exit;
}
