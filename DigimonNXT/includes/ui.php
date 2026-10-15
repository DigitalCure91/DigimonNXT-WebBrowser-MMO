<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/digimon.php';
require_once __DIR__.'/community.php';
require_once __DIR__.'/presentation.php';
require_once __DIR__.'/audio.php';
require_once __DIR__.'/world_notice.php';
function pv_page_start(string $title,string $active='',bool $loggedIn=false): void {
    $name=trim((string)($_SESSION['myuser']??'Tamer'))?:'Tamer';$v=pv_asset_version();
    $route=preg_replace('/[^a-z0-9_-]/','',pathinfo(basename((string)($_SERVER['SCRIPT_NAME']??$active)),PATHINFO_FILENAME));
    $GLOBALS['nxt_route']=$route;$options=nxt_preferences($loggedIn);$GLOBALS['nxt_preferences']=$options;
    if($loggedIn){try{dn_touch_online(pv_db(),(int)$_SESSION['myid'],$title);}catch(Throwable){}}
    $nav=$loggedIn?['dashboard.php'=>'Command center','map_select.php'=>'Dawn World','digimon.php'=>'Digimon','materialize.php'=>'DigiLab','battle_arena.php'=>'Battle Park']:['index.php'=>'Home','news.php'=>'News','about.php'=>'The world','login.php'=>'Log in','signup.php'=>'Join NXT'];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><meta name="theme-color" content="#070b19"><meta name="description" content="Digimon NXT. Connect with your partners, explore Cyberspace, battle wild signatures and unlock your next evolution."><title>'.pv_h($title).' · Digimon NXT</title><link rel="icon" href="'.pv_h(pv_asset('ui/nxt.svg')).'"><link rel="stylesheet" href="'.pv_h(pv_asset('css/digimon-nxt.css')).'?v='.$v.'"><script defer src="'.pv_h(pv_asset('js/digimon-nxt.js')).'?v='.$v.'"></script></head><body class="dn-page pv-modern-page nxt-'.$route.' '.($loggedIn?'pv-session-active':'pv-session-public').' '.(!(int)$options['battle_animation']?'nxt-motion-paused ':'').((int)$options['compact_ui']?'nxt-compact':'').'" data-motion-preference="'.((int)$options['battle_animation']?'on':'off').'"><a class="pv-skip-link" href="#pv-main-content">Skip to game content</a><div class="nxt-atmosphere" aria-hidden="true"><div class="nxt-data-rain"><span>0101 0010 1011 0100 1001</span><span>1000 0111 1010 0010 1101</span><span>0110 1001 0100 1011 0010</span></div></div><div class="pv-site-shell">';
    foreach(['team-combat','ranked','dawn-world','dawn-polish','world-notice'] as $sheet)if(is_file(dirname(__DIR__).'/assets/css/'.$sheet.'.css'))echo '<link rel="stylesheet" href="'.pv_h(pv_asset('css/'.$sheet.'.css')).'?v='.$v.'">';
    echo '<header class="pv-topbar"><a class="pv-brand" href="'.pv_h(pv_url($loggedIn?'dashboard.php':'index.php')).'" aria-label="Digimon NXT home"><img src="'.pv_h(pv_asset('ui/nxt.svg')).'" alt="" width="42" height="42"><span>DIGIMON <b>NXT</b><small>DIGITAL WORLD / TAMER NETWORK</small></span></a><button class="pv-nav-toggle" type="button" aria-expanded="false" aria-controls="pv-primary-nav" data-pv-nav-toggle>'.nxt_icon('home').'<span>Menu</span></button><nav class="pv-nav" id="pv-primary-nav" aria-label="Primary navigation">';
    foreach($nav as $url=>$label)echo '<a '.($url===$active?'class="active" aria-current="page"':'').' href="'.pv_h(pv_url($url)).'">'.pv_h($label).'</a>';
    echo '</nav>';
    if($loggedIn)echo '<a class="pv-trainer-chip" href="'.pv_h(pv_url('your_account.php')).'"><img src="'.pv_h(nxt_skin_url((int)$options['tamer'])).'" width="30" height="40" alt=""><span>'.pv_h($name).'</span></a><a class="nxt-signout" href="'.pv_h(pv_url('logout.php')).'" aria-label="Log out">'.nxt_icon('power').'</a>';
    echo '</header><div class="pv-command-strip"><span class="pv-command-state"><i></i>'.($loggedIn?'TAMER CONNECTED':'DIGITAL GATEWAY').'</span><span class="nxt-strip-route">'.pv_h($title).'</span><span class="nxt-strip-network">NXT / CYBERSPACE NETWORK</span><button class="nxt-motion-switch" type="button" data-nxt-motion aria-pressed="'.((int)$options['battle_animation']?'true':'false').'">Motion <b>'.((int)$options['battle_animation']?'on':'off').'</b></button></div><div id="pv-main-content" tabindex="-1"></div>';
    dn_world_notice_banner();
}
function pv_page_end(): void {
    dn_audio_widget();
    if(pv_is_logged_in())echo '<script defer data-endpoint="'.pv_h(pv_url('world_notice.php')).'" src="'.pv_h(pv_asset('js/world-notice.js')).'?v='.pv_asset_version().'"></script>';
    if(pv_is_logged_in())echo '<script defer data-tamer-pulse data-endpoint="'.pv_h(pv_url('tamer_pulse.php')).'" data-csrf="'.pv_h(pv_csrf_token()).'" src="'.pv_h(pv_asset('js/tamer-pulse.js')).'?v='.pv_asset_version().'"></script>';
    echo '<footer class="pv-footer"><a class="nxt-footer-brand" href="'.pv_h(pv_url('index.php')).'">DIGIMON <b>NXT</b></a><span>Make the connection.</span><nav aria-label="Footer"><a href="'.pv_h(pv_url('news.php')).'">News</a><a href="'.pv_h(pv_url('about.php')).'">About</a><a href="'.pv_h(pv_url('release.php')).'">Release notes</a><a href="'.pv_h(pv_url('terms.php')).'">Terms</a><a href="'.pv_h(pv_url('privacy.php')).'">Privacy</a></nav><small>BUILD '.pv_h((string)pv_config('asset_version')).'</small></footer></div></body></html>';
}
function pv_game_side_menu(string $active=''): void {
    $groups=[
        'Explore'=>['dashboard.php'=>['Command center','home'],'map_select.php'=>['Dawn atlas','world'],'wildbattle.php'=>['Wild battle','battle'],'battle_history.php'=>['Battle history','history']],
        'Partners'=>['digimon.php'=>['Collection','partner'],'party.php'=>['Active party','party'],'materialize.php'=>['Scan & materialize','scan'],'digivolution.php'=>['Digivolution','evolve'],'digidex.php'=>['DigiDex','dex'],'items.php'=>['Supply shop','shop']],
        'Network'=>['battle_arena.php'=>['Battle Park','battle'],'ai_activity.php'=>['Tamer activity','network'],'tamer_rivals.php'=>['Tamer rivals','history'],'trade.php'=>['Partner exchange','exchange'],'event_center.php'=>['Operations','event'],'community.php'=>['Tamer network','network'],'messages.php'=>['Messages','mail'],'clans.php'=>['Clans','clan']],
        'Tamer'=>['your_account.php'=>['Your profile','profile'],'notifications.php'=>['Notifications','notice'],'options.php'=>['Preferences','settings']],
    ];
    $o=$GLOBALS['nxt_preferences']??['tamer'=>1];$name=(string)($_SESSION['myuser']??'Tamer');
    echo '<aside class="pv-side-menu" aria-label="Game navigation"><button type="button" class="nxt-side-toggle" data-nxt-side-toggle aria-expanded="false" aria-controls="nxt-side-links">'.nxt_icon('home').'<span>Game navigation</span><b>+</b></button><div id="nxt-side-links"><a class="pv-side-profile" href="'.pv_h(pv_url('your_account.php')).'"><img src="'.pv_h(nxt_skin_url((int)$o['tamer'])).'" width="44" height="62" alt=""><span><small>TAMER IDENTITY</small><strong>'.pv_h($name).'</strong><em>LINK ESTABLISHED</em></span></a>';
    foreach($groups as $group=>$links){echo '<div class="pv-side-group"><h2>'.pv_h($group).'</h2>';foreach($links as $url=>[$label,$icon])echo '<a '.($url===$active?'class="active" aria-current="page"':'').' href="'.pv_h(pv_url($url)).'">'.nxt_icon($icon).'<span>'.pv_h($label).'</span></a>';echo '</div>';}
    echo '<a class="nxt-sidebar-partner" href="'.pv_h(pv_url('materialize.php')).'">'.nxt_sprite('terriermon').'<span>Next partner?<small>Visit the DigiLab</small></span></a></div></aside>';
}
function dn_digimon_card(array $d,bool $showStats=true): string {
    $name=(string)(($d['nickname']??'')?:$d['name']);$paradox=dn_is_paradox($d);
    $html='<article class="dn-digimon-card '.($paradox?'is-paradox':'').'"><div class="dn-digimon-art"><span class="nxt-level">LV <b>'.(int)$d['level'].'</b></span><img src="'.pv_h(dn_instance_image($d)).'" alt="'.pv_h($name).'" width="120" height="120" loading="lazy" decoding="async"></div><div class="dn-digimon-copy"><small>'.pv_h(strtoupper((string)$d['stage'])).($paradox?' / PARADOX':'').(!empty($d['slot'])?' / SLOT '.(int)$d['slot']:'').'</small><h3>'.pv_h($name).'</h3><p>'.pv_h(($d['type']??'Digimon').' · '.$d['attribute'].' · '.$d['element']).'</p><div class="nxt-partner-traits"><span>ABI <b>'.(int)($d['abi']??0).'</b></span><span>CAM <b>'.(int)($d['cam']??0).'%</b></span></div>';
    if($showStats){$html.='<div class="dn-bars">';foreach(['hp'=>'HP','sp'=>'SP'] as $key=>$label){$value=max(0,(int)($d[$key]??0));$max=max(1,(int)($d['max_'.$key]??1));$percent=max(0,min(100,(int)round($value/$max*100)));$html.='<div><span>'.$label.'</span><i aria-hidden="true"><b style="width:'.$percent.'%"></b></i><strong>'.$value.'/'.$max.'</strong></div>';}$html.='</div>';}
    return $html.'</div></article>';
}
