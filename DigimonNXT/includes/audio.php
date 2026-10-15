<?php
declare(strict_types=1);

/** Render once, after page content: the soundtrack desk is never over the map. */
function dn_audio_widget(): void {
    static $rendered=false;
    if($rendered)return;
    $rendered=true;
    $path=dirname(__DIR__).'/config/dawn_audio.json';
    if(!is_file($path))return;
    $manifest=json_decode((string)file_get_contents($path),true);
    if(!is_array($manifest['tracks']??null))return;
    $tracks=[];
    foreach($manifest['tracks'] as $id=>$track){
        if(($track['status']??'')!=='rendered')continue;
        $tracks[$id]=['url'=>pv_asset((string)$track['file']),'kind'=>$track['kind'],
            'loopStart'=>max(0,(float)$track['loop_start']),'loopEnd'=>max(0,(float)$track['loop_end'])];
    }
    $route=(string)($GLOBALS['nxt_route']??'index');
    $scenes=['title'=>'bgm00','menu'=>'bgm01','town'=>'bgm10','battle'=>'bgm30','ranked'=>'bgm31',
        'lab'=>'bgm32','shop'=>'bgm33','community'=>'bgm02','victory'=>'bgm54','defeat'=>'bgm53'];
    $scene=match($route){'index','login','signup'=>'title','world_map'=>'town','wildbattle'=>'battle',
        'battle_arena','ranked_battle'=>'ranked','materialize','digivolution'=>'lab','items'=>'shop',
        'community','clans','messages','ai_activity','tamer_rivals'=>'community',default=>'menu'};
    $track=$scenes[$scene];
    if($route==='world_map'&&function_exists('pv_world_area')){
        $definition=pv_world_area('cyberspace',(string)($_SESSION['world_area']??$_GET['area']??'dawn-001'));
        $candidate=(string)($definition['music_track']??'');
        if(isset($tracks[$candidate])&&$tracks[$candidate]['kind']==='music')$track=$candidate;
    }
    $config=['track'=>$track,'scene'=>$scene,'tracks'=>$tracks,'scenes'=>$scenes,
        'effects'=>['select'=>'se000','confirm'=>'se001','cancel'=>'se002','error'=>'se003','hit'=>'se029',
            'skill'=>'se030','scan'=>'se009','evolve'=>'se014','victory'=>'se015','defeat'=>'se016','encounter'=>'se010']];
    echo '<link rel="stylesheet" href="'.pv_h(pv_asset('css/dawn-audio.css')).'?v='.pv_asset_version().'">';
    echo '<section class="dawn-audio" aria-label="Dawn soundtrack"><div class="dawn-audio-summary"><span class="dawn-audio-mark" aria-hidden="true">♫</span><div><strong>Dawn soundtrack</strong><span data-dawn-status aria-live="polite">Sound starts when you interact.</span></div><button class="pv-button pv-button-secondary" type="button" data-dawn-toggle aria-pressed="false">Enable sound</button><details class="dawn-audio-details"><summary>Sound settings</summary><div class="dawn-audio-settings">';
    echo '<label>Music volume<input data-dawn-music-volume type="range" min="0" max="100" value="38"></label><label>Effects volume<input data-dawn-effects-volume type="range" min="0" max="100" value="55"></label>';
    echo '<label class="dawn-audio-library">Music player<select data-dawn-track><option value="auto">Follow the game</option>';
    foreach($tracks as $id=>$t)if($t['kind']==='music')echo '<option value="'.pv_h($id).'">Dawn · '.pv_h(strtoupper($id)).'</option>';
    echo '</select></label><p>Original Dawn music and sound effects. Choose a track or let each sector set the mood.</p></div></details></div></section>';
    echo '<script>window.DAWN_AUDIO_CONFIG='.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';</script><script defer src="'.pv_h(pv_asset('js/dawn-audio.js')).'?v='.pv_asset_version().'"></script>';
}
